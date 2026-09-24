<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 *
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * @license AGPL-3.0
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
 *
 */

namespace OCA\Market\Controller;

use OC\App\DependencyAnalyzer;
use OC\App\Platform;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\IRequest;

class LocalAppsController extends Controller {
	private ?DependencyAnalyzer $dependencyAnalyzer = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppManager $appManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function index(string $state = 'enabled'): array {
		$apps = [];
		foreach ($this->appManager->getAllApps() as $appId) {
			$app = $this->appManager->getAppInfo($appId);
			if (!\is_array($app) || empty($app['id'])) {
				continue;
			}
			$app['active'] = $this->appManager->isEnabledForUser($appId);
			$apps[] = $app;
		}

		// state=all liefert enabled+disabled in einem Request, damit das
		// Frontend nicht zwei getrennte Abfragen stellen muss.
		if ($state !== 'all') {
			$apps = \array_filter(
				$apps,
				static fn ($app): bool => $state === 'enabled' ? (bool) $app['active'] : !(bool) $app['active']
			);
		}

		return \array_values(\array_map(function ($app): array {
			$missing = $this->getMissingDependencies($app);
			$app['canInstall'] = empty($missing);
			$app['missingDependencies'] = $missing;
			$app['installed'] = true;
			$app['updateInfo'] = [];
			return $app;
		}, $apps));
	}

	private function getMissingDependencies(array $appInfo): array {
		// bad hack - should use OCP
		// Analyzer einmal pro Request bauen statt pro App.
		$this->dependencyAnalyzer ??= new DependencyAnalyzer(
			new Platform(\OC::$server->getConfig()),
			\OC::$server->getL10N('settings')
		);
		return $this->dependencyAnalyzer->analyze($appInfo);
	}
}
