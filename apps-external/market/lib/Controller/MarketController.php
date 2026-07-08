<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Ilja Neumann <ineumann@owncloud.com>
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

use Exception;
use OCA\Market\Exception\LicenseKeyAlreadyAvailableException;
use OCA\Market\MarketService;
use OCP\App\AppManagerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class MarketController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MarketService $marketService,
		private readonly IL10N $l10n,
		private readonly IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function categories(): array|DataResponse {
		try {
			return $this->marketService->getCategories();
		} catch (Exception $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * @NoCSRFRequired
	 */
	public function bundles(): array|DataResponse {
		try {
			return \array_map(function ($bundle): array {
				$bundle['products'] = \array_map(fn ($product): array => $this->enrichApp($product), $bundle['products']);
				return $bundle;
			}, $this->marketService->getBundles());
		} catch (AppManagerException $ex) {
			// 503 statt 200: Das Frontend erkennt Marketplace-Ausfälle nur über
			// den Fehlerstatus, sonst wird {message} als App-Liste gerendert.
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (Exception $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * @NoCSRFRequired
	 */
	public function index(): array|DataResponse {
		try {
			// Expliziter Nutzer-Refresh (?refresh=1): Katalog-Cache vor der
			// Abfrage verwerfen; normale Seitenaufrufe nutzen den Cache.
			if ($this->request->getParam('refresh') === '1') {
				$this->marketService->invalidateCache();
			}
			return $this->queryData();
		} catch (AppManagerException $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (Exception $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * @NoCSRFRequired
	 */
	public function app(string $appId): array|DataResponse {
		try {
			$info = $this->marketService->getAppInfo($appId);
			if ($info === null) {
				return new DataResponse(
					['message' => $this->l10n->t('App %s was not found in the marketplace catalog.', [$appId])],
					Http::STATUS_NOT_FOUND
				);
			}
			return $this->enrichApp($info);
		} catch (Exception $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	public function install(string $appId): array|DataResponse {
		try {
			$this->marketService->installApp($appId);
			return ['message' => $this->l10n->t('App %s installed successfully', [$appId])];
		} catch (Exception $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * @NoCSRFRequired
	 */
	public function changeApiKey(string $apiKey): Response|DataResponse {
		if (!$this->marketService->isApiKeyValid($apiKey)) {
			return new DataResponse(['message' => $this->l10n->t('The api key is not valid.')]);
		}

		// Don't update on GET
		if ($this->request->getMethod() === 'GET') {
			return (new Response())->setStatus(Http::STATUS_OK);
		}

		if (!$this->marketService->setApiKey($apiKey)) {
			return new DataResponse([
				'message' => $this->l10n->t('Can not change api key because it is configured in config.php')
			]);
		}

		return (new Response())->setStatus(Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function getApiKey(): DataResponse {
		$responseBody = [
			'changeable' => $this->marketService->isApiKeyChangeableByUser(),
		];

		if ($this->marketService->isApiKeyChangeableByUser()) {
			$responseBody['apiKey'] = $this->marketService->getApiKey();
		}

		return new DataResponse($responseBody, Http::STATUS_OK);
	}

	public function uninstall(string $appId): array|DataResponse {
		try {
			$this->marketService->uninstallApp($appId);
			return ['message' => $this->l10n->t('App %s uninstalled successfully', [$appId])];
		} catch (Exception $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	public function update(string $appId): array|DataResponse {
		$targetVersion = $this->request->getParam('toVersion');
		try {
			$this->marketService->updateApp($appId, $targetVersion);
			return ['message' => $this->l10n->t('App %s updated successfully', [$appId])];
		} catch (Exception $ex) {
			return new DataResponse(['message' => $ex->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	protected function queryData(?string $category = null): array {
		return \array_map(
			fn ($app): array => $this->enrichApp($app),
			$this->marketService->listApps($category)
		);
	}

	private function enrichApp(array $app): array {
		$app['installed'] = $this->marketService->isAppInstalled($app['id']);
		$releases = \array_map(
			function ($release): array {
				$missing = $this->marketService->getMissingDependencies($release);
				$release['canInstall'] = empty($missing);
				$release['missingDependencies'] = $missing;
				return $release;
			},
			$app['releases']
		);
		unset($app['releases']);

		$app['minorUpdate'] = false;
		$app['majorUpdate'] = false;
		if ($app['installed']) {
			$app['installInfo'] = $this->marketService->getInstalledAppInfo($app['id']);
			$app['updateInfo'] = $this->marketService->getAvailableUpdateVersions($app['id']);
			$app['updateTo'] = $app['updateInfo']['minor'] !== false
				? $app['updateInfo']['minor']
				: $app['updateInfo']['major'];
			\array_walk(
				$releases,
				static function ($release) use (&$app): void {
					if ($release['version'] === $app['updateInfo']['major']) {
						$app['majorUpdate'] = $release;
					}
					if ($release['version'] === $app['updateInfo']['minor']) {
						$app['minorUpdate'] = $release;
					}
				}
			);
		} else {
			\usort(
				$releases,
				static fn ($a, $b): int => \version_compare($a['version'], $b['version'])
			);
			if (!empty($releases)) {
				$app['release'] = \array_pop($releases);
			}
		}
		$app['updateInfo'] = $app['majorUpdate'] !== false || $app['minorUpdate'] !== false;
		return $app;
	}

	/**
	 * @NoCSRFRequired
	 */
	public function getConfig(): DataResponse {
		$licenseKeyAvailable = $this->marketService->hasLicenseKey();
		$licenseMessage = $licenseKeyAvailable
			? $this->l10n->t('License key available.')
			: $this->l10n->t('No license key configured.');

		return new DataResponse([
			'canInstall' => $this->marketService->canInstall(),
			'hasInternetConnection' => $this->config->getSystemValue('has_internet_connection', true),
			'licenseKeyAvailable' => $licenseKeyAvailable,
			'licenseMessage' => $licenseMessage,
		], Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function requestDemoLicenseKeyFromMarket(): DataResponse {
		try {
			$this->marketService->requestLicenseKey();
			return new DataResponse(
				['message' => $this->l10n->t('Demo license key successfully fetched from the marketplace.')],
				Http::STATUS_OK
			);
		} catch (LicenseKeyAlreadyAvailableException) {
			return new DataResponse(
				['message' => $this->l10n->t('A license key is already configured.')],
				Http::STATUS_CONFLICT
			);
		} catch (Exception) {
			return new DataResponse(
				['message' => $this->l10n->t('Could not request the license key.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}

	public function invalidateCache(): DataResponse {
		$this->marketService->invalidateCache();
		return new DataResponse(
			['message' => $this->l10n->t('Cache cleared.')],
			Http::STATUS_OK
		);
	}
}
