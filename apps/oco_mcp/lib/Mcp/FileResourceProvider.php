<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Mcp;

use Mcp\Exception\ResourceReadException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Exposes the acting user's files as MCP resources.
 *
 * - `owncloud:///` (static resource) → a JSON listing of the user's root folder.
 * - `owncloud:///{path}` (template)  → read any file or folder. Because the SDK's
 *   URI-template variables match a single non-slash segment, nested paths are
 *   percent-encoded by the client ("/" → "%2F", per RFC 6570 simple expansion);
 *   the handler percent-decodes before resolving.
 *
 * Handlers return `['text'|'blob' => …, 'mimeType' => …]`; the SDK's formatter
 * pairs that with the actual request URI. Every path is resolved through the
 * user's own root, so a resource can never escape it.
 */
class FileResourceProvider {
	private const MAX_BYTES = 5 * 1048576;

	private IRootFolder $rootFolder;
	private string $userId;

	public function __construct(IRootFolder $rootFolder, string $userId) {
		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
	}

	/**
	 * Content of the static `owncloud:///` resource: a JSON listing of the root.
	 */
	public function root(): array {
		return ['text' => $this->listingJson($this->userFolder()), 'mimeType' => 'application/json'];
	}

	/**
	 * Content of the `owncloud:///{path}` template for one path.
	 *
	 * @param string $path The (percent-encoded) path captured from the URI.
	 */
	public function read(string $path): array {
		$real = \rawurldecode($path);
		$node = $this->resolve($real);
		if ($node instanceof Folder) {
			return ['text' => $this->listingJson($node), 'mimeType' => 'application/json'];
		}
		if (!$node instanceof File) {
			throw new ResourceReadException('Unsupported resource: ' . $real);
		}
		$mime = $node->getMimetype();
		$data = $this->boundedContent($node);
		if ($data === '' || \mb_check_encoding($data, 'UTF-8')) {
			return ['text' => $data, 'mimeType' => $mime ?: 'text/plain'];
		}
		return ['blob' => \base64_encode($data), 'mimeType' => $mime ?: 'application/octet-stream'];
	}

	private function userFolder(): Folder {
		return $this->rootFolder->getUserFolder($this->userId);
	}

	private function resolve(string $path): Node {
		try {
			return $this->userFolder()->get('/' . \ltrim($path, '/'));
		} catch (NotFoundException | NotPermittedException) {
			throw new ResourceReadException('Path not found or not accessible: ' . $path);
		}
	}

	private function boundedContent(File $node): string {
		$handle = $node->fopen('r');
		if ($handle === false) {
			throw new ResourceReadException('Cannot open file: ' . $node->getName());
		}
		try {
			$data = \stream_get_contents($handle, self::MAX_BYTES);
		} finally {
			\fclose($handle);
		}
		return $data === false ? '' : $data;
	}

	private function listingJson(Folder $folder): string {
		$root = $this->userFolder()->getPath();
		$entries = [];
		foreach ($folder->getDirectoryListing() as $child) {
			$rel = \substr($child->getPath(), \strlen($root));
			$isFolder = $child->getType() === \OCP\Files\FileInfo::TYPE_FOLDER;
			$entries[] = [
				'name' => $child->getName(),
				'path' => $rel === '' ? '/' : $rel,
				'uri' => 'owncloud:///' . \rawurlencode(\ltrim($rel, '/')),
				'type' => $isFolder ? 'folder' : 'file',
				'size' => $child->getSize(),
				'mimetype' => $isFolder ? 'httpd/unix-directory' : $child->getMimetype(),
			];
		}
		$rootRel = \substr($folder->getPath(), \strlen($root));
		// JSON_INVALID_UTF8_SUBSTITUTE keeps the listing usable when a filecache
		// row holds non-UTF-8 bytes (external SMB/FTP shares, legacy locales):
		// bad bytes become U+FFFD instead of making json_encode() return false
		// and silently yielding an empty "successful" listing.
		$json = \json_encode([
			'path' => $rootRel === '' ? '/' : $rootRel,
			'count' => \count($entries),
			'entries' => $entries,
		], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			throw new ResourceReadException('Failed to encode folder listing: ' . \json_last_error_msg());
		}
		return $json;
	}
}
