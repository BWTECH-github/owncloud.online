<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 *
 * Modified by BW-Tech GmbH on 2026-07-06.
 * Changes:
 *   - add oco_mcp — Model Context Protocol server for AI assistants
 */
namespace OCA\OcoMcp\Tools;

use OCP\Files\IRootFolder;

/**
 * Meta tools: identity, quota and a summary of what this connection can do.
 */
class MetaTool {
	private IRootFolder $rootFolder;
	private string $userId;
	private bool $isAdmin;
	private bool $writeEnabled;

	public function __construct(IRootFolder $rootFolder, string $userId, bool $isAdmin, bool $writeEnabled) {
		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
		$this->isAdmin = $isAdmin;
		$this->writeEnabled = $writeEnabled;
	}

	/**
	 * Identify the acting user and this connection's capabilities.
	 *
	 * @return array The user id, admin flag and whether write tools are enabled.
	 */
	public function whoami(): array {
		return [
			'user_id' => $this->userId,
			'is_admin' => $this->isAdmin,
			'write_enabled' => $this->writeEnabled,
			'instance' => 'owncloud.online',
		];
	}

	/**
	 * Report the acting user's storage usage and free space.
	 *
	 * @return array Used bytes, free bytes and total (where known).
	 */
	public function quota(): array {
		$folder = $this->rootFolder->getUserFolder($this->userId);
		$used = $folder->getSize();
		$free = $folder->getFreeSpace();
		$total = ($free >= 0) ? $used + $free : -1;
		return [
			'used' => $used,
			'free' => $free,
			'total' => $total,
			'unlimited' => $free < 0,
		];
	}

	/**
	 * List the tool groups available on this connection and whether they are writable.
	 *
	 * @return array The capability summary.
	 */
	public function capabilities(): array {
		return [
			'write_enabled' => $this->writeEnabled,
			'is_admin' => $this->isAdmin,
			'tool_groups' => [
				'files' => ['read' => true, 'write' => $this->writeEnabled],
				'shares' => ['read' => true, 'write' => $this->writeEnabled],
				'tags' => ['read' => true, 'write' => $this->writeEnabled],
				'comments' => ['read' => true, 'write' => $this->writeEnabled],
				'users' => ['read' => $this->isAdmin, 'write' => $this->isAdmin && $this->writeEnabled],
				'groups' => ['read' => $this->isAdmin, 'write' => $this->isAdmin && $this->writeEnabled],
			],
		];
	}
}
