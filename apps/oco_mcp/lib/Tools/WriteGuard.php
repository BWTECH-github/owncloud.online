<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;

/**
 * Shared write / admin gating for tool classes. The flags are set from the
 * request context (app config "enable_write" and the acting user's admin state).
 */
trait WriteGuard {
	private bool $writeEnabled = false;

	private function assertWrite(): void {
		if (!$this->writeEnabled) {
			throw new ToolCallException(
				'Write access is disabled on this MCP connection. '
				. 'An administrator can enable it with: occ config:app:set oco_mcp enable_write --value=yes'
			);
		}
	}

	private function assertAdmin(bool $isAdmin): void {
		if (!$isAdmin) {
			throw new ToolCallException('This tool requires ownCloud administrator privileges.');
		}
	}
}
