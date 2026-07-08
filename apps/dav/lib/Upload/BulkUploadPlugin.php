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

	/** Maximum number of parts in a single request. Bounds the filesystem/DB
	 *  operation fan-out so a tiny body cannot amplify into millions of writes. */
	private const MAX_PARTS = 10000;

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
		// Priority 85: earlier than Sabre's Browser plugin (100), which would try to
		// resolve the (nonexistent) "bulk" node and 404, AND earlier than app plugins
		// that registered themselves at 90 — customgroups' CSVImportPlugin calls
		// getNodeForPath() unguarded, so at equal priority the registration order
		// decided whether bulk uploads worked at all.
		$server->on('method:POST', [$this, 'httpPost'], 85);
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
		// Ab hier wird nur noch mit den Part-Kopien gearbeitet — Body-Referenz
		// freigeben, damit während der Store-Phase nicht zwei komplette Abbilder
		// des Requests gleichzeitig im RAM liegen.
		unset($body);

		foreach ($parts as $part) {
			if (!isset($part['headers']['x-file-path']) || $part['headers']['x-file-path'] === '') {
				// Ohne Pfad ist kein Response-Key möglich; hart ablehnen statt den
				// Part still zu überspringen (der Client hielte die Datei sonst für
				// hochgeladen). Läuft vor dem ersten Schreibzugriff, also verlustfrei.
				throw new BadRequest('Bulk upload part is missing X-File-Path');
			}
		}

		$view = new \OC\Files\View('/' . $user->getUID() . '/files');
		$results = [];
		// Defer etag/size propagation to the end of the batch. Without this, every
		// stored file immediately UPDATEs its whole parent-folder chain (Propagator::
		// propagateChange), so N files dropped into one folder cause N propagation
		// UPDATE storms in a single request. beginBatch()/commitBatch() collapse the
		// common single-target-folder case to ~2 grouped UPDATEs total. The per-file
		// etag/fileid returned below comes from the file's own scanned row, not from
		// folder propagation, so deferring the folder update does not corrupt them.
		// Files may land in different storages; for a received share the batch is
		// begun on the owner's *source* propagator (the SharedPropagator delegates
		// every call there and never honours its own batch mode), tracked per
		// propagator instance and committed once each.
		$batchedPropagators = [];
		// Request-weite Nebenbuchführung: kumulative Quota-Prüfung pro Storage
		// (free_space() ist wegen der gebatchten Size-Propagation für die gesamte
		// Request-Dauer eingefroren) und die erfolgreich geschriebenen Pfade für
		// den Fallback bei fehlgeschlagenem commitBatch().
		$state = ['quotaFree' => [], 'quotaUsed' => [], 'storedPaths' => []];
		try {
			foreach ($parts as $part) {
				$path = $part['headers']['x-file-path'];
				$this->beginPropagatorBatch($view, $path, $batchedPropagators);
				$results[$path] = $this->storeFile($view, $part, $state);
			}
		} finally {
			// Commit every batch we opened, even if a store threw, so folder sizes/etags
			// are flushed and the connection is not left mid-batch. A commit failure on
			// one storage must not swallow the collected results or block the others.
			$commitFailed = false;
			foreach ($batchedPropagators as $propagator) {
				try {
					$propagator->commitBatch();
				} catch (\Throwable $e) {
					$commitFailed = true;
					\OC::$server->getLogger()->logException($e, ['app' => 'dav_bulkupload']);
				}
			}
			if ($commitFailed) {
				// commitBatch() leert den Batch vor dem Rethrow — die Deltas sind
				// unwiederbringlich weg. Ohne Fallback blieben Ordnergrößen/-etags
				// bis zum nächsten occ files:scan dauerhaft falsch.
				$this->propagateStoredPathsIndividually($view, $state['storedPaths']);
			}
		}

		$response->setStatus(200);
		$response->setHeader('Content-Type', 'application/json; charset=utf-8');
		// X-File-Path (the result keys) are raw DAV byte strings and need not be valid
		// UTF-8; substitute invalid sequences so json_encode cannot return false and
		// emit an empty 200 body that hides every per-file result from the client.
		// Dokumentiert ist ein JSON-Objekt: json_encode([]) ergäbe das Array [],
		// daher leeres Ergebnis explizit als Objekt serialisieren.
		$json = $results === [] ? '{}' : \json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			$json = \json_encode(['error' => true, 'message' => 'Could not encode bulk result']);
		}
		$response->setBody($json);
		return false;
	}

	/**
	 * Ensure the propagator for the storage that will hold $path is in batch mode,
	 * tracking it so it gets committed once. Resolving the storage for a not-yet-
	 * existing file works because the mount is matched by path prefix. If the mount
	 * cannot be resolved, we simply do not batch that file and it falls back to
	 * immediate per-file propagation — correct, just not batched.
	 *
	 * @param array<int, \OCP\Files\Cache\IPropagator> $batchedPropagators keyed by spl_object_id
	 */
	private function beginPropagatorBatch(\OC\Files\View $view, string $path, array &$batchedPropagators): void {
		try {
			$mount = $view->getMount($path);
			if ($mount === null) {
				return;
			}
			$storage = $mount->getStorage();
			if ($storage === null) {
				return;
			}
			// Empfangene Shares: SharedPropagator::propagateChange() delegiert jeden
			// Aufruf sofort an den Propagator des Owner-Quell-Storage und prüft nie
			// den eigenen Batch-Modus — beginBatch() auf dem SharedPropagator wäre
			// wirkungslos. Der Batch muss deshalb auf dem Quell-Propagator geführt
			// werden. Schleife wegen möglicher Reshare-Ketten; das Dedupe unten
			// greift, wenn mehrere Parts (oder Shares) denselben Quell-Storage
			// treffen.
			$depth = 0;
			while ($storage->instanceOfStorage('\OCA\Files_Sharing\SharedStorage') && $depth < 10) {
				// Erst äußere Wrapper (Availability/Encoding/PermissionsMask/…)
				// abtragen: resolvePath() existiert nur auf der Jail-/SharedStorage-
				// Ebene, auf einem generischen Wrapper wäre der Aufruf ein Fatal
				// (der unten gefangen würde → Batching stillschweigend wirkungslos).
				while ($storage instanceof \OC\Files\Storage\Wrapper\Wrapper
					&& !($storage instanceof \OCA\Files_Sharing\SharedStorage)
				) {
					$storage = $storage->getWrapperStorage();
				}
				if (!($storage instanceof \OCA\Files_Sharing\SharedStorage)) {
					break;
				}
				// resolvePath('') liefert [Quell-Storage des Owners, Quellpfad]
				list($sourceStorage, ) = $storage->resolvePath('');
				if (!$sourceStorage instanceof \OC\Files\Storage\Storage || $sourceStorage === $storage) {
					break;
				}
				$storage = $sourceStorage;
				$depth++;
			}
			$propagator = $storage->getPropagator();
			$key = \spl_object_id($propagator);
			if (!isset($batchedPropagators[$key])) {
				$propagator->beginBatch();
				$batchedPropagators[$key] = $propagator;
			}
		} catch (\Throwable $e) {
			// Non-fatal: without a batch this file just propagates immediately.
			\OC::$server->getLogger()->logException($e, ['app' => 'dav_bulkupload']);
		}
	}

	/**
	 * Fallback when commitBatch() failed: the batched mtime/etag/size deltas are
	 * gone for good (the propagator clears its batch before rethrowing), so folder
	 * etags would never change and folder sizes would stay wrong until the next
	 * occ files:scan. Re-propagate per stored path instead: an etag/mtime bump on
	 * the whole parent chain plus an absolute (and therefore idempotent) folder
	 * size recalculation from the child rows.
	 *
	 * Läuft bewusst über ALLE gespeicherten Pfade, nicht nur die des
	 * fehlgeschlagenen Propagators: für bereits committete Storages ist der
	 * zweite Etag-Bump harmlos und correctFolderSize() rechnet absolut.
	 *
	 * @param string[] $storedPaths
	 */
	private function propagateStoredPathsIndividually(\OC\Files\View $view, array $storedPaths): void {
		$seenDirs = [];
		foreach ($storedPaths as $path) {
			try {
				list($storage, $internalPath) = $view->resolvePath($path);
				if ($storage === null) {
					continue;
				}
				// Pro (Storage, Elternordner) genügt ein Durchlauf: propagateChange()
				// bumpt die komplette Elternkette, correctFolderSize() rekursiert
				// selbst bis zur Storage-Wurzel.
				$parentDir = \dirname($internalPath);
				if ($parentDir === '.' || $parentDir === '/') {
					$parentDir = '';
				}
				$key = \spl_object_id($storage) . '|' . $parentDir;
				if (isset($seenDirs[$key])) {
					continue;
				}
				$seenDirs[$key] = true;
				// inBatch ist nach dem (fehlgeschlagenen) commitBatch() wieder false,
				// das UPDATE läuft also sofort als einzelnes Statement. Für Shares
				// delegiert der SharedPropagator korrekt an den Quell-Storage.
				$storage->getPropagator()->propagateChange($internalPath, \time());
				$storage->getCache()->correctFolderSize($parentDir);
			} catch (\Throwable $e) {
				\OC::$server->getLogger()->logException($e, ['app' => 'dav_bulkupload']);
			}
		}
	}

	/**
	 * Write a single part to the user's storage, verifying its md5.
	 *
	 * @param array $state request-weite Nebenbuchführung (quotaFree/quotaUsed je
	 *                     Storage + storedPaths), siehe httpPost()
	 * @return array per-file result (error flag + etag/fileid or message)
	 */
	private function storeFile(\OC\Files\View $view, array $part, array &$state): array {
		$path = $part['headers']['x-file-path'];
		try {
			if (!\OC\Files\Filesystem::isValidPath($path)) {
				return ['error' => true, 'message' => 'Invalid path'];
			}

			$expectedMd5 = isset($part['headers']['x-file-md5']) ? \strtolower($part['headers']['x-file-md5']) : null;
			if ($expectedMd5 !== null && \md5($part['body']) !== $expectedMd5) {
				return ['error' => true, 'message' => 'Checksum mismatch'];
			}

			// Enforce the share permission mask. file_put_contents/mkdir/touch below
			// write straight through the storage and — unlike fopen('wb') on a
			// SharedStorage — do NOT re-check the recipient's permissions. Without
			// this gate a read-only share recipient could create or overwrite files
			// in the owner's storage through the bulk endpoint.
			$exists = $view->file_exists($path);
			if ($exists) {
				if (!$view->isUpdatable($path)) {
					return ['error' => true, 'message' => 'Permission denied'];
				}
			} else {
				// walk up to the nearest existing ancestor and require create rights
				// there (intermediate directories are created via mkdir below)
				$checkDir = \dirname($path);
				while ($checkDir !== '' && $checkDir !== '.' && $checkDir !== '/' && !$view->file_exists($checkDir)) {
					$checkDir = \dirname($checkDir);
				}
				if ($checkDir === '' || $checkDir === '.') {
					$checkDir = '/';
				}
				if (!$view->isCreatable($checkDir)) {
					return ['error' => true, 'message' => 'Permission denied'];
				}
			}

			list($targetStorage, $targetInternalPath) = $view->resolvePath($path);
			$partSize = \strlen($part['body']);
			// Beim Überschreiben belegt nur das Wachstum zusätzliche Quota: die
			// unten eingefrorene free_space-Basis von Batch-Beginn enthält die
			// alte Dateigröße bereits. Volle Anrechnung würde Overwrite-Syncs
			// auf fast vollen Accounts fälschlich ablehnen, die per Einzel-PUT
			// durchgingen. Der Quota-Wrapper prüft beim Schreiben trotzdem
			// weiterhin jede Datei einzeln.
			$quotaCharge = $partSize;
			if ($exists) {
				$oldSize = $view->filesize($path);
				if ($oldSize !== false && $oldSize >= 0) {
					$quotaCharge = \max(0, $partSize - (int)$oldSize);
				}
			}

			// Kumulative Quota-Prüfung: der Quota-Wrapper prüft jede Datei gegen
			// free_space(), das wegen der gebatchten Size-Propagation für die
			// gesamte Request-Dauer auf dem Stand von Batch-Beginn steht. Ohne
			// laufende Summe könnte ein fast voller Account bis MAX_BODY_SIZE an
			// Dateien in einem einzigen Request über die Quota schieben.
			if ($targetStorage !== null) {
				$quotaKey = \spl_object_id($targetStorage);
				if (!isset($state['quotaUsed'][$quotaKey])) {
					$free = $targetStorage->free_space('');
					// negative Werte sind SPACE_UNKNOWN/UNLIMITED/NOT_COMPUTED →
					// keine Prüfung, wie im Quota-Wrapper selbst
					$state['quotaFree'][$quotaKey] = \is_numeric($free) && $free >= 0 ? (int)$free : null;
					$state['quotaUsed'][$quotaKey] = 0;
				}
				if ($state['quotaFree'][$quotaKey] !== null
					&& $state['quotaUsed'][$quotaKey] + $quotaCharge >= $state['quotaFree'][$quotaKey]
				) {
					// gleiche Semantik wie Quota::file_put_contents (strlen < free)
					return ['error' => true, 'message' => 'Insufficient storage'];
				}
			}

			// Make sure the parent collection exists.
			$dir = \dirname($path);
			if ($dir !== '' && $dir !== '.' && $dir !== '/' && !$view->is_dir($dir)) {
				$view->mkdir($dir);
			}

			// No explicit locking here: View::file_put_contents() already takes the
			// shared→exclusive→shared lock dance around the write itself. Wrapping it in
			// an outer exclusive lock self-deadlocks (the inner acquire then sees a
			// conflicting exclusive lock held by the same request and throws).
			$written = $view->file_put_contents($path, $part['body']);
			if ($part['body'] === '') {
				// 0-Byte-Sonderfall: die Storages liefern hier 0 geschriebene Bytes,
				// was die View als falsy wertet und writeUpdate() (Scan + Propagation)
				// überspringt — PermissionsMask macht aus der 0 sogar false. Erfolg
				// deshalb direkt am Storage prüfen und das Cache-Update samt
				// (gebatchter) Propagation explizit anstoßen.
				if ($targetStorage === null || !$targetStorage->file_exists($targetInternalPath)) {
					return ['error' => true, 'message' => 'Could not write file'];
				}
				$storedSize = $targetStorage->filesize($targetInternalPath);
				if ($storedSize === false || (int)$storedSize !== 0) {
					return ['error' => true, 'message' => 'Could not write file'];
				}
				$targetStorage->getUpdater()->update($targetInternalPath);
			} elseif ($written === false || $written === null) {
				// null: basicOperation() bricht ohne false ab (Hook-Veto, kein Storage)
				return ['error' => true, 'message' => 'Could not write file'];
			}

			// Ab hier ist die Datei geschrieben: Quota-Summe fortschreiben und den
			// Pfad für den commitBatch-Fallback vormerken.
			if ($targetStorage !== null) {
				$state['quotaUsed'][\spl_object_id($targetStorage)] += $quotaCharge;
			}
			$state['storedPaths'][] = $path;

			if (isset($part['headers']['x-oc-mtime']) && $targetStorage !== null) {
				$mtime = (int)$part['headers']['x-oc-mtime'];
				if ($mtime > 0) {
					// Wie File::handleMetadataUpdate() beim normalen PUT: mtime direkt
					// auf dem Storage setzen und nur die Cache-Zeile nachziehen.
					// $view->touch() würde über den 'touch'-Hook einen kompletten
					// zweiten Scan-/Update-Zyklus pro Datei auslösen.
					$touched = false;
					try {
						$touched = $targetStorage->touch($targetInternalPath, $mtime) !== false;
					} catch (\Throwable $ignored) {
						// Storage ohne mtime-Unterstützung: nur Cache-mtime setzen
						// (entspricht dem Emulations-Fallback in View::touch()).
					}
					$fileInfoUpdate = ['mtime' => $mtime];
					if ($touched) {
						// storage_mtime mitziehen, sonst meldet der Watcher die Datei
						// später als extern geändert und erzwingt einen Rescan.
						$fileInfoUpdate['storage_mtime'] = $mtime;
					}
					$view->putFileInfo($path, $fileInfoUpdate);
				}
			}

			$info = $view->getFileInfo($path);
			// getFileInfo() is typed FileInfo|bool and returns false when the cache could
			// not be updated (object storage, scan race). Guard before dereferencing so
			// one un-stat-able file returns a per-file error instead of a fatal that 500s
			// the whole batch and discards the results already collected.
			if ($info === false) {
				return ['error' => true, 'message' => 'Stored but could not stat file'];
			}
			// Return the canonical OC-FileID (same format the DAV layer / PROPFIND
			// uses: zero-padded numeric id + instance id), so the desktop client
			// stores the same file id it would get from a PROPFIND and does not see
			// the freshly uploaded file as changed on the next sync.
			$ocFileId = \sprintf('%08d', $info->getId()) . \OC_Util::getInstanceId();
			return [
				'error' => false,
				'etag' => $info->getEtag(),
				'fileid' => $info->getId(),
				'OC-FileID' => $ocFileId,
			];
		} catch (ForbiddenException $e) {
			return ['error' => true, 'message' => $e->getMessage()];
		} catch (\Throwable $e) {
			// \Throwable (not just \Exception) so a PHP \Error in the storage stack
			// degrades to a per-file error rather than 500-ing the entire batch.
			return ['error' => true, 'message' => $e->getMessage()];
		}
	}

	/**
	 * Read the request body with a hard size cap.
	 */
	private function readBody(RequestInterface $request): string {
		$maxSize = self::MAX_BODY_SIZE;
		// Body und Part-Kopien liegen während des Parsens ~2x im RAM. Ohne
		// Kopplung an memory_limit stirbt ein ausgereizter Request auf knapp
		// dimensionierten Instanzen (z. B. 128M) mit einem nicht abfangbaren
		// OOM-Fatal (500 ohne JSON-Body) statt mit einem sauberen 400.
		try {
			$memoryLimit = \OC::$server->getIniWrapper()->getBytes('memory_limit');
			if (\is_numeric($memoryLimit) && $memoryLimit > 0) {
				$maxSize = (int)\min($maxSize, (int)($memoryLimit / 3));
			}
		} catch (\Throwable $ignored) {
			// memory_limit nicht ermittelbar: beim statischen Limit bleiben
		}
		$declared = (int)$request->getHeader('Content-Length');
		if ($declared > $maxSize) {
			throw new BadRequest('Bulk upload body too large; use chunked upload for big files or send smaller batches');
		}
		$stream = $request->getBodyAsStream();
		$body = \stream_get_contents($stream, $maxSize + 1);
		if ($body === false) {
			throw new BadRequest('Could not read request body');
		}
		if (\strlen($body) > $maxSize) {
			throw new BadRequest('Bulk upload body too large; use chunked upload for big files or send smaller batches');
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
		$delimLen = \strlen($delimiter);
		while ($pos !== false && $pos < $len) {
			if (\count($parts) >= self::MAX_PARTS) {
				throw new BadRequest('Too many parts in bulk upload request');
			}
			$pos += $delimLen;
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
				// Abgeschnittene oder LF-only Part-Header: hart ablehnen statt die
				// restlichen Parts still zu verwerfen und 200 zu liefern (der Client
				// hielte sie für hochgeladen). Erzwingt zugleich den End-Delimiter
				// "--boundary--". Läuft vor jedem Schreibzugriff, also verlustfrei.
				throw new BadRequest('Malformed multipart: unterminated part headers');
			}
			$rawHeaders = \substr($body, $pos, $headerEnd - $pos);
			$headers = $this->parseHeaders($rawHeaders);
			$bodyStart = $headerEnd + 4;

			if (isset($headers['content-length'])) {
				$bodyLen = (int)$headers['content-length'];
				// The declared length must fit inside the body and the part must be
				// followed by [CRLF +] the delimiter. Otherwise the client under- or
				// over-declared it: reject instead of silently storing a truncated /
				// over-read body, or crashing on an out-of-range strpos offset (which
				// raises a ValueError on PHP 8 and 500s the whole request).
				if ($bodyLen < 0 || $bodyStart + $bodyLen > $len) {
					throw new BadRequest('Part content-length exceeds request body');
				}
				$partBody = \substr($body, $bodyStart, $bodyLen);
				$delimAt = $bodyStart + $bodyLen;
				if (\substr($body, $delimAt, 2) === "\r\n") {
					$delimAt += 2;
				}
				if (\substr($body, $delimAt, $delimLen) !== $delimiter) {
					throw new BadRequest('Malformed multipart: part content-length does not match part extent');
				}
				$next = $delimAt;
			} else {
				// The bulk protocol requires a per-part Content-Length so parsing is
				// always length-driven and binary-safe. Scanning for the boundary in a
				// length-less part would truncate any file whose bytes happen to contain
				// the (fixed) boundary token, so reject instead of silently storing a
				// short file. The owncloud.online client always sends Content-Length.
				throw new BadRequest('Bulk upload part is missing Content-Length');
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
