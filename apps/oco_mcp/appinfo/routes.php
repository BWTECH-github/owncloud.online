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
namespace OCA\OcoMcp\AppInfo;

$application = new Application();
$application->registerRoutes(
	$this,
	[
		'routes' => [
			// Streamable HTTP MCP endpoint in JSON mode: POST carries JSON-RPC,
			// DELETE tears a session down. We deliberately do NOT expose the GET
			// SSE stream — under PHP-FPM a long-lived server->client stream would
			// pin a worker, and a tool-only server has no server-initiated
			// messages to push. Both verbs are CSRF-exempt because MCP clients
			// cannot present an ownCloud CSRF token; the controller enforces
			// token/basic auth instead (see McpController).
			[
				'name' => 'Mcp#handle',
				'url' => '/mcp',
				'verb' => 'POST',
			],
			[
				'name' => 'Mcp#handle',
				'url' => '/mcp',
				'verb' => 'DELETE',
				'postfix' => 'delete',
			],
		],
	]
);
