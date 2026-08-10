<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-06.
 * Changes:
 *   - add oco_mcp — Model Context Protocol server for AI assistants
 */
namespace OCA\OcoMcp\Mcp;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Minimal PSR-11 container holding already-constructed tool instances.
 *
 * The MCP SDK resolves an array handler [Class::class, 'method'] by asking the
 * container for the class. We pre-build each tool with the acting user baked in
 * and hand it back here, so tools never run without a user context.
 */
class InstanceContainer implements ContainerInterface {
	/** @var array<string, object> */
	private array $instances;

	/**
	 * @param array<string, object> $instances class-string => instance
	 */
	public function __construct(array $instances) {
		$this->instances = $instances;
	}

	public function get(string $id) {
		if (!isset($this->instances[$id])) {
			throw new class ('No entry for ' . $id) extends \RuntimeException implements NotFoundExceptionInterface {
			};
		}
		return $this->instances[$id];
	}

	public function has(string $id): bool {
		return isset($this->instances[$id]);
	}
}
