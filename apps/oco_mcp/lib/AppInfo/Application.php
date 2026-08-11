<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 *
 * Modified by BW-Tech GmbH on 2026-08-06.
 * Changes:
 *   - route every MCP path through one strict gate and stop leaking backend errors
 *   - file resources, image viewing, and optional AI document search
 *   - add oco_mcp — Model Context Protocol server for AI assistants
 */
namespace OCA\OcoMcp\AppInfo;

use OCA\OcoMcp\Controller\McpController;
use OCA\OcoMcp\Mcp\ServerFactory;
use OCP\AppFramework\App;
use OCP\IContainer;

/**
 * The app deliberately registers NO bootstrap hooks and does NOT load its
 * composer autoloader here. The bundled MCP SDK vendor is pulled in lazily
 * inside {@see McpController::handle()} only, so a normal ownCloud request never
 * loads the app's vendor/ and therefore can never shadow a core-provided library
 * (the class of bug that once took down the global HTTP client via an old Guzzle).
 */
class Application extends App {
	public function __construct(array $urlParams = []) {
		parent::__construct('oco_mcp', $urlParams);
		$container = $this->getContainer();
		$server = $container->getServer();

		$container->registerService(ServerFactory::class, function (IContainer $c) use ($server) {
			return new ServerFactory(
				$server->getRootFolder(),
				$server->getShareManager(),
				$server->getUserManager(),
				$server->getGroupManager(),
				$server->getSystemTagManager(),
				$server->getSystemTagObjectMapper(),
				$server->getCommentsManager(),
				$server->getConfig(),
				$server->getURLGenerator(),
				$server->getAppManager(),
				$server->getLogger()
			);
		});

		$container->registerService(McpController::class, function (IContainer $c) use ($server) {
			return new McpController(
				$c->query('AppName'),
				$c->query('Request'),
				$server->getUserSession(),
				$server->getGroupManager(),
				$c->query(ServerFactory::class),
				$server->getConfig(),
				$server->getLogger()
			);
		});
	}
}
