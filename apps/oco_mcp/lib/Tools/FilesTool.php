<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

/**
 * File tools: browse, read, search and (when enabled) modify the acting user's files.
 */
class FilesTool {
	use WriteGuard;

	private IRootFolder $rootFolder;
	private string $userId;

	public function __construct(IRootFolder $rootFolder, string $userId, bool $writeEnabled) {
		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
		$this->writeEnabled = $writeEnabled;
	}

	/**
	 * List the files and folders directly inside a directory.
	 *
	 * @param string $path Directory path relative to the user's root, e.g. "/" or "/Documents".
	 * @return array The directory entries with name, path, type, size, mtime and mimetype.
	 */
	public function list(string $path = '/'): array {
		$node = $this->getNode($path);
		if (!$node instanceof Folder) {
			throw new ToolCallException('Not a directory: ' . $path);
		}
		$entries = [];
		foreach ($node->getDirectoryListing() as $child) {
			$entries[] = $this->describe($child);
		}
		return ['path' => $this->relPath($node), 'count' => \count($entries), 'entries' => $entries];
	}

	/**
	 * Get metadata about a single file or folder.
	 *
	 * @param string $path Path relative to the user's root.
	 * @return array The node metadata.
	 */
	public function info(string $path): array {
		return $this->describe($this->getNode($path));
	}

	/**
	 * Read the contents of a text file. Binary files are returned base64-encoded.
	 *
	 * @param string $path Path to the file relative to the user's root.
	 * @param int $max_bytes Maximum number of bytes to return (default 1 MiB).
	 * @return array The file contents plus an "encoding" of "utf-8" or "base64" and a "truncated" flag.
	 */
	public function read(string $path, int $max_bytes = 1048576): array {
		$node = $this->getNode($path);
		if (!$node instanceof File) {
			throw new ToolCallException('Not a file: ' . $path);
		}
		$max_bytes = \max(1, \min($max_bytes, 10 * 1048576));
		// Stream at most $max_bytes so a multi-GB file never gets pulled into
		// memory whole (getContent() would). Truncation is decided from the size.
		$size = $node->getSize();
		$handle = $node->fopen('r');
		if ($handle === false) {
			throw new ToolCallException('Cannot open file for reading: ' . $path);
		}
		try {
			$data = \stream_get_contents($handle, $max_bytes);
		} finally {
			\fclose($handle);
		}
		if ($data === false) {
			$data = '';
		}
		$truncated = $size > $max_bytes;
		$isUtf8 = $data === '' || \mb_check_encoding($data, 'UTF-8');
		return [
			'path' => $this->relPath($node),
			'encoding' => $isUtf8 ? 'utf-8' : 'base64',
			'content' => $isUtf8 ? $data : \base64_encode($data),
			'truncated' => $truncated,
			'size' => $node->getSize(),
		];
	}

	/**
	 * Search the user's files by name (substring match).
	 *
	 * @param string $query The search term to match against file and folder names.
	 * @param int $limit Maximum number of results (default 50).
	 * @return array Matching nodes.
	 */
	public function search(string $query, int $limit = 50): array {
		$limit = \max(1, \min($limit, 200));
		$results = [];
		foreach ($this->userFolder()->search($query) as $node) {
			$results[] = $this->describe($node);
			if (\count($results) >= $limit) {
				break;
			}
		}
		return ['query' => $query, 'count' => \count($results), 'results' => $results];
	}

	/**
	 * Create or overwrite a text file. Requires write access.
	 *
	 * @param string $path Destination path relative to the user's root.
	 * @param string $content The file contents to write.
	 * @param bool $base64 Set true if "content" is base64-encoded binary data.
	 * @return array Metadata of the written file.
	 */
	public function write(string $path, string $content, bool $base64 = false): array {
		$this->assertWrite();
		$path = $this->clean($path);
		$data = $base64 ? \base64_decode($content, true) : $content;
		if ($data === false) {
			throw new ToolCallException('Invalid base64 content.');
		}
		$folder = $this->userFolder();
		try {
			$node = $folder->get($path);
			if (!$node instanceof File) {
				throw new ToolCallException('Path is a directory: ' . $path);
			}
		} catch (NotFoundException) {
			$node = $folder->newFile($path);
		}
		$node->putContent($data);
		return $this->describe($node);
	}

	/**
	 * Create a directory (and missing parents). Requires write access.
	 *
	 * @param string $path Directory path to create, relative to the user's root.
	 * @return array Metadata of the created folder.
	 */
	public function mkdir(string $path): array {
		$this->assertWrite();
		return $this->describe($this->userFolder()->newFolder($this->clean($path)));
	}

	/**
	 * Move or rename a file or folder. Requires write access.
	 *
	 * @param string $source Existing path relative to the user's root.
	 * @param string $target Destination path relative to the user's root.
	 * @return array Metadata of the moved node.
	 */
	public function move(string $source, string $target): array {
		$this->assertWrite();
		$folder = $this->userFolder();
		$node = $this->getNode($source);
		$node->move($folder->getFullPath($this->clean($target)));
		return $this->describe($folder->get($this->clean($target)));
	}

	/**
	 * Copy a file or folder. Requires write access.
	 *
	 * @param string $source Existing path relative to the user's root.
	 * @param string $target Destination path relative to the user's root.
	 * @return array Metadata of the copied node.
	 */
	public function copy(string $source, string $target): array {
		$this->assertWrite();
		$folder = $this->userFolder();
		$node = $this->getNode($source);
		$node->copy($folder->getFullPath($this->clean($target)));
		return $this->describe($folder->get($this->clean($target)));
	}

	/**
	 * Delete a file or folder (moves it to the trash bin). Requires write access.
	 *
	 * @param string $path Path relative to the user's root.
	 * @return array A confirmation with the deleted path.
	 */
	public function delete(string $path): array {
		$this->assertWrite();
		$node = $this->getNode($path);
		$rel = $this->relPath($node);
		$node->delete();
		return ['deleted' => $rel];
	}

	private function userFolder(): Folder {
		return $this->rootFolder->getUserFolder($this->userId);
	}

	private function getNode(string $path): Node {
		try {
			return $this->userFolder()->get($this->clean($path));
		} catch (NotFoundException) {
			throw new ToolCallException('Path not found: ' . $path);
		}
	}

	private function clean(string $path): string {
		$path = '/' . \ltrim(\trim($path), '/');
		return $path === '/' ? '/' : \rtrim($path, '/');
	}

	private function relPath(Node $node): string {
		$root = $this->userFolder()->getPath();
		$full = $node->getPath();
		$rel = \substr($full, \strlen($root));
		return $rel === '' || $rel === false ? '/' : $rel;
	}

	private function describe(Node $node): array {
		$isFolder = $node->getType() === \OCP\Files\FileInfo::TYPE_FOLDER;
		return [
			'name' => $node->getName(),
			'path' => $this->relPath($node),
			'type' => $isFolder ? 'folder' : 'file',
			'size' => $node->getSize(),
			'mtime' => $node->getMTime(),
			'mimetype' => $isFolder ? 'httpd/unix-directory' : $node->getMimetype(),
			'id' => $node->getId(),
			'permissions' => $node->getPermissions(),
		];
	}
}
