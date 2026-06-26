<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH (owncloud.online)
 * @license AGPL-3.0
 *
 * Bulk upload endpoint for owncloud.online: accepts many small files in a single
 * multipart/related POST to /remote.php/dav/bulk, so the desktop client can avoid
 * one request per tiny file. Mirrors the Nextcloud bulk-upload wire format so a
 * single client implementation can talk to either server.
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 */

namespace OCA\DAV\Upload;

use OCP\Files\ForbiddenException;
use OCP\IUserSession;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Handles POST /remote.php/dav/bulk with a multipart/related body. Each MIME part
 * carries one file with headers:
 *   - X-File-Path : path relative to the user's files root (e.g. /Photos/a.jpg)
 *   - X-File-Md5  : hex md5 of the part body (verified server side)
 *   - X-OC-Mtime  : desired modification time (unix seconds), optional
 *   - Content-Length : body length in bytes
 * The response is a JSON object keyed by X-File-Path with per-file etag/fileid or
 * an error, so one failing file does not fail the whole batch.
 */
class BulkUploadPlugin extends ServerPlugin {
	/** Maximum accepted total request body. Bulk is meant for many *small*
	 *  files; large files must use chunked upload. Guards against memory abuse. */
	private const MAX_BODY_SIZE = 100 * 1024 * 1024; // 100 MiB

	/** @var Server */
	private $server;
	/** @var IUserSession */
	private $userSession;

	public function __construct(IUserSession $userSession) {
		$this->userSession = $userSession;
	}

	/**
	 * @inheritdoc
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		// Priority 90 (< the default 100) so this runs before Sabre's Browser
		// plugin POST handler, which would otherwise try to resolve the (nonexistent)
		// "bulk" node and return 404 before we get a chance to handle the request.
		$server->on('method:POST', [$this, 'httpPost'], 90);
	}

	/**
	 * @return bool|void false to stop further handling, void to let others handle
	 * @throws BadRequest
	 * @throws Forbidden
	 */
	public function httpPost(RequestInterface $request, ResponseInterface $response) {
		if (\trim($request->getPath(), '/') !== 'bulk') {
			// Not our endpoint, let the default handling continue.
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Forbidden('No authenticated user for bulk upload');
		}

		$contentType = (string)$request->getHeader('Content-Type');
		if (\stripos($contentType, 'multipart/related') === false) {
			throw new BadRequest('Bulk upload requires Content-Type: multipart/related');
		}
		$boundary = $this->parseBoundary($contentType);
		if ($boundary === null) {
			throw new BadRequest('Missing multipart boundary');
		}

		$body = $this->readBody($request);
		$parts = $this->parseMultipart($body, $boundary);

		$view = new \OC\Files\View('/' . $user->getUID() . '/files');
		$results = [];
		foreach ($parts as $part) {
			$path = isset($part['headers']['x-file-path']) ? $part['headers']['x-file-path'] : null;
			if ($path === null || $path === '') {
				// Can't key the result without a path; skip defensively.
				continue;
			}
			$results[$path] = $this->storeFile($view, $part);
		}

		$response->setStatus(200);
		$response->setHeader('Content-Type', 'application/json; charset=utf-8');
		$response->setBody(\json_encode($results));
		return false;
	}

	/**
	 * Write a single part to the user's storage, verifying its md5.
	 *
	 * @return array per-file result (error flag + etag/fileid or message)
	 */
	private function storeFile(\OC\Files\View $view, array $part): array {
		$path = $part['headers']['x-file-path'];
		try {
			if (!\OC\Files\Filesystem::isValidPath($path)) {
				return ['error' => true, 'message' => 'Invalid path'];
			}

			$expectedMd5 = isset($part['headers']['x-file-md5']) ? \strtolower($part['headers']['x-file-md5']) : null;
			if ($expectedMd5 !== null && \md5($part['body']) !== $expectedMd5) {
				return ['error' => true, 'message' => 'Checksum mismatch'];
			}

			// Make sure the parent collection exists.
			$dir = \dirname($path);
			if ($dir !== '' && $dir !== '.' && $dir !== '/' && !$view->is_dir($dir)) {
				$view->mkdir($dir);
			}

			if ($view->file_put_contents($path, $part['body']) === false) {
				return ['error' => true, 'message' => 'Could not write file'];
			}

			if (isset($part['headers']['x-oc-mtime'])) {
				$mtime = (int)$part['headers']['x-oc-mtime'];
				if ($mtime > 0) {
					$view->touch($path, $mtime);
				}
			}

			$info = $view->getFileInfo($path);
			return [
				'error' => false,
				'etag' => $info->getEtag(),
				'fileid' => $info->getId(),
				'OC-FileID' => $info->getId(),
			];
		} catch (ForbiddenException $e) {
			return ['error' => true, 'message' => $e->getMessage()];
		} catch (\Exception $e) {
			return ['error' => true, 'message' => $e->getMessage()];
		}
	}

	/**
	 * Read the request body with a hard size cap.
	 */
	private function readBody(RequestInterface $request): string {
		$declared = (int)$request->getHeader('Content-Length');
		if ($declared > self::MAX_BODY_SIZE) {
			throw new BadRequest('Bulk upload body too large; use chunked upload for big files');
		}
		$stream = $request->getBodyAsStream();
		$body = \stream_get_contents($stream, self::MAX_BODY_SIZE + 1);
		if ($body === false) {
			throw new BadRequest('Could not read request body');
		}
		if (\strlen($body) > self::MAX_BODY_SIZE) {
			throw new BadRequest('Bulk upload body too large; use chunked upload for big files');
		}
		return $body;
	}

	/**
	 * Extract the boundary token from a multipart Content-Type header.
	 */
	private function parseBoundary(string $contentType): ?string {
		if (\preg_match('/boundary=(?:"([^"]+)"|([^;,\s]+))/i', $contentType, $m)) {
			return $m[1] !== '' ? $m[1] : $m[2];
		}
		return null;
	}

	/**
	 * Cursor-based, binary-safe multipart parser. Uses each part's Content-Length
	 * to read its body exactly, so file data containing the boundary token (very
	 * unlikely, but possible) cannot corrupt parsing.
	 *
	 * @return array<int, array{headers: array<string,string>, body: string}>
	 */
	private function parseMultipart(string $body, string $boundary): array {
		$delimiter = '--' . $boundary;
		$parts = [];
		$pos = \strpos($body, $delimiter);
		if ($pos === false) {
			throw new BadRequest('No multipart boundary found in body');
		}
		$len = \strlen($body);
		while ($pos !== false && $pos < $len) {
			$pos += \strlen($delimiter);
			// End delimiter "--boundary--"
			if (\substr($body, $pos, 2) === '--') {
				break;
			}
			// Skip the CRLF after the delimiter.
			if (\substr($body, $pos, 2) === "\r\n") {
				$pos += 2;
			}
			// Headers run until a blank line.
			$headerEnd = \strpos($body, "\r\n\r\n", $pos);
			if ($headerEnd === false) {
				break;
			}
			$rawHeaders = \substr($body, $pos, $headerEnd - $pos);
			$headers = $this->parseHeaders($rawHeaders);
			$bodyStart = $headerEnd + 4;

			if (isset($headers['content-length'])) {
				$bodyLen = (int)$headers['content-length'];
				$partBody = \substr($body, $bodyStart, $bodyLen);
				$next = \strpos($body, $delimiter, $bodyStart + $bodyLen);
			} else {
				// Fall back to scanning for the next delimiter (strips trailing CRLF).
				$next = \strpos($body, $delimiter, $bodyStart);
				$rawLen = ($next === false ? $len : $next) - $bodyStart;
				$partBody = \substr($body, $bodyStart, $rawLen);
				$partBody = \preg_replace('/\r\n$/', '', $partBody);
			}

			$parts[] = ['headers' => $headers, 'body' => $partBody];
			$pos = $next;
		}
		return $parts;
	}

	/**
	 * @return array<string,string> lower-cased header name => value
	 */
	private function parseHeaders(string $raw): array {
		$headers = [];
		foreach (\explode("\r\n", $raw) as $line) {
			$colon = \strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			$name = \strtolower(\trim(\substr($line, 0, $colon)));
			$value = \trim(\substr($line, $colon + 1));
			$headers[$name] = $value;
		}
		return $headers;
	}
}
