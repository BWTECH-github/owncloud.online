<?php
/**
 * @author Viktar Dubiniuk <dubinuk@owncloud.com>
 * @author Ilja Neumann <ineumann@owncloud.com>
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
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
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-10.
 * Changes:
 *   - 0.10.3 — live catalog (30min cache), clickable installed apps, downgrade-...
 *   - sync bundled market app with fork fixes
 *   - connect bundled market to marketplace.owncloud.online by default (same as...
 *   - bundle the market app (neutral, local catalog default)
 */

namespace OCA\Market;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use OCP\App\AppManagerException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Talks to the marketplace backend or, when configured, to a fully local
 * static JSON catalog bundled with the plugin (BW-Tech fork default).
 */
class HttpService {
	public const CACHE_KEY = 'ocmp';

	public const APPS = 'apps_%s';
	public const BUNDLES = 'bundles';
	public const CATEGORIES = 'categories';
	public const DEMO_KEY = 'demo_license_information';

	/**
	 * Sentinel value for `appstoreurl` which forces the bundled local catalog
	 * shipped at `<plugin>/marketplace/`.
	 */
	public const LOCAL_CATALOG_MARKER = 'local';

	/**
	 * Default marketplace endpoint when no `appstoreurl` is configured, so a fresh
	 * owncloud.online install connects to the public marketplace out of the box.
	 * Set `appstoreurl` to 'local' (or a file:// path) to use the bundled offline catalog.
	 */
	public const DEFAULT_STORE_URL = 'https://marketplace.owncloud.online';

	/** @var array<string, string> */
	private array $urlConfig = [
		self::APPS => '/api/v1/platform/%s/apps.json',
		self::BUNDLES => '/api/v1/bundles.json',
		self::CATEGORIES => '/api/v1/categories.json',
		self::DEMO_KEY => '/api/v1/instance/%s/demo-key',
	];

	public function __construct(
		private readonly IClientService $httpClientService,
		private readonly VersionHelper $versionHelper,
		private readonly IConfig $config,
		private readonly ICacheFactory $cacheFactory,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @throws AppManagerException
	 */
	public function getApps(): mixed {
		return $this->getEntities(self::APPS);
	}

	/**
	 * @throws AppManagerException
	 */
	public function getBundles(): mixed {
		return $this->getEntities(self::BUNDLES);
	}

	/**
	 * @throws AppManagerException
	 */
	public function getCategories(): mixed {
		return $this->getEntities(self::CATEGORIES);
	}

	/**
	 * @throws AppManagerException
	 */
	public function getDemoKey(): mixed {
		return $this->getEntities(self::DEMO_KEY);
	}

	/**
	 * @throws AppManagerException
	 */
	public function downloadApp(string $url, string $path): void {
		// File-system or bundled relative paths: copy from disk, no HTTP.
		if (\str_starts_with($url, 'file://')) {
			$source = \substr($url, 7);
			$this->copyLocalFile($source, $path);
			return;
		}

		if ($this->isLocalCatalog() && !\str_starts_with($url, 'http')) {
			$source = $this->resolveCatalogRelativePath($url);
			$this->copyLocalFile($source, $path);
			return;
		}

		$apiKey = $this->getApiKey();
		// Größeres Timeout als für API-Abfragen: App-Pakete können mehrere MB groß sein.
		$this->httpGet($url, ['sink' => $path, 'timeout' => 300], $apiKey);
	}

	/**
	 * Validate a marketplace API key. In local-catalog mode the validation is
	 * always considered successful since no remote service is contacted.
	 *
	 * @throws AppManagerException
	 */
	public function validateKey(string $apiKey): ?IResponse {
		if ($this->isLocalCatalog()) {
			return null;
		}
		$url = $this->getAbsoluteUrl('/api/v1/categories.json');
		return $this->httpGet($url, [], $apiKey);
	}

	public function invalidateCache(): void {
		if (!$this->cacheFactory->isAvailable()) {
			return;
		}
		$cache = $this->cacheFactory->create(self::CACHE_KEY);
		$cache->clear();
	}

	/**
	 * @throws AppManagerException
	 */
	public function queryData(string $key, string $uri): mixed {
		// read from cache
		if ($this->cacheFactory->isAvailable()) {
			$cache = $this->cacheFactory->create(self::CACHE_KEY);
			$data = $cache->get($key);
			if ($data !== null) {
				$decoded = \json_decode($data, true);
				if (\is_array($decoded)) {
					return $decoded;
				}
				// Ungültiger Alt-Eintrag (z.B. vor dieser Validierung gecachte
				// Fehlerseite): verwerfen und frisch laden.
				$cache->remove($key);
			}
		}

		$this->checkInternetConnection();
		$apiKey = $this->getApiKey();
		$endpointUrl = $this->getAbsoluteUrl($uri);
		$response = $this->httpGet($endpointUrl, [], $apiKey);
		$data = $response->getBody();
		try {
			$decoded = \json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new AppManagerException(
				$this->l10n->t('Marketplace returned invalid JSON: %s', [$e->getMessage()]),
				0,
				$e
			);
		}
		if (!\is_array($decoded)) {
			throw new AppManagerException(
				$this->l10n->t('Marketplace returned an unexpected response format.')
			);
		}
		// Erst nach erfolgreicher Validierung cachen: eine kaputte 200er-Antwort
		// (Proxy-/Wartungsseite) würde sonst den Katalog vergiften. Kurze TTL (30
		// Min), damit neu veröffentlichte Katalog-Versionen zeitnah sichtbar sind und
		// nicht bis zu einem Tag ein veraltetes Release (mit toter Download-URL)
		// ausgeliefert wird.
		if ($this->cacheFactory->isAvailable()) {
			$cache = $this->cacheFactory->create(self::CACHE_KEY);
			$cache->set($key, $data, 60 * 30);
		}
		return $decoded;
	}

	/**
	 * Checks if this instance can connect to the internet.
	 *
	 * @throws AppManagerException
	 */
	public function checkInternetConnection(): void {
		// Local-catalog mode does not need internet access.
		if ($this->isLocalCatalog()) {
			return;
		}
		$hasInternetConnection = $this->config->getSystemValue('has_internet_connection', true);
		if ($hasInternetConnection !== true) {
			throw new AppManagerException(
				(string) $this->l10n->t('The Internet connection is disabled.')
			);
		}
	}

	public function getApiKey(): ?string {
		$configFileApiKey = $this->config->getSystemValue('marketplace.key', null);
		if ($configFileApiKey) {
			return $configFileApiKey;
		}
		return $this->config->getAppValue('market', 'key', null) ?: null;
	}

	/**
	 * @throws AppManagerException
	 */
	private function httpGet(string $path, array $options, ?string $apiKey): IResponse {
		// Guzzle-Default ist timeout=0 (unendlich): ohne Limit blockiert ein
		// hängender Marketplace Seitenaufrufe und die serielle Cron-Queue.
		// Aufrufer-Optionen (z.B. Download-Timeout) haben Vorrang.
		$options = \array_merge(
			['connect_timeout' => 10, 'timeout' => 30],
			$options
		);
		if (!empty($apiKey)) {
			$options = \array_merge(
				['headers' => ['Authorization' => "apikey: $apiKey"]],
				$options
			);
		}
		$ca = $this->config->getSystemValue('marketplace.ca', null);
		if ($ca !== null) {
			$options = \array_merge(['verify' => $ca], $options);
		}
		$client = $this->httpClientService->newClient();
		try {
			return $client->get($path, $options);
		} catch (TransferException $e) {
			if ($e instanceof ClientException) {
				throw match ($e->getCode()) {
					401 => new AppManagerException(
						$apiKey !== null
							? $this->l10n->t('Invalid marketplace API key provided')
							: $this->l10n->t('Marketplace API key missing')
					),
					402 => new AppManagerException(
						$this->l10n->t('Active subscription on marketplace required')
					),
					default => new AppManagerException(
						$this->l10n->t('No marketplace connection: %s', [$e->getMessage()]),
						0,
						$e
					),
				};
			}
			throw new AppManagerException(
				$this->l10n->t('No marketplace connection: %s', [$e->getMessage()]),
				0,
				$e
			);
		}
	}

	/**
	 * @throws AppManagerException
	 */
	private function getEntities(string $code): mixed {
		// Local-catalog mode bypasses caching and HTTP entirely.
		if ($this->isLocalCatalog()) {
			return $this->readLocalEntity($code);
		}

		$url = $this->urlConfig[$code];
		if ($code === self::APPS) {
			$platformVersion = $this->versionHelper->getPlatformVersion(3);
			$url = \sprintf($url, $platformVersion);
			$code = \sprintf($code, $platformVersion);
		} elseif ($code === self::DEMO_KEY) {
			$instanceId = $this->config->getSystemValue('instanceid');
			$url = \sprintf($url, $instanceId);
		}
		return $this->queryData($code, $url);
	}

	private function getAbsoluteUrl(string $relativeUrl): string {
		$storeUrl = $this->config->getSystemValue('appstoreurl', self::DEFAULT_STORE_URL);
		return \rtrim($storeUrl, '/') . $relativeUrl;
	}

	/**
	 * Local catalog mode is active when `appstoreurl` is set to the `local`
	 * marker or points at a `file://` location; without configuration the
	 * remote marketplace (DEFAULT_STORE_URL) is used.
	 */
	private function isLocalCatalog(): bool {
		$url = $this->config->getSystemValue('appstoreurl', self::DEFAULT_STORE_URL);
		return $url === self::LOCAL_CATALOG_MARKER || \str_starts_with((string) $url, 'file://');
	}

	private function getLocalCatalogPath(): string {
		$url = (string) $this->config->getSystemValue('appstoreurl', self::DEFAULT_STORE_URL);
		if (\str_starts_with($url, 'file://')) {
			return \rtrim(\substr($url, 7), '/');
		}
		// dirname(__DIR__) -> plugin root, then `marketplace/`
		return \dirname(__DIR__) . '/marketplace';
	}

	/**
	 * @throws AppManagerException
	 */
	private function readLocalEntity(string $code): mixed {
		$filename = match (true) {
			\str_starts_with($code, 'apps') => 'apps.json',
			$code === self::BUNDLES => 'bundles.json',
			$code === self::CATEGORIES => 'categories.json',
			$code === self::DEMO_KEY => null,
			default => null,
		};
		if ($filename === null) {
			throw new AppManagerException(
				$this->l10n->t('Local catalog does not provide entity "%s".', [$code])
			);
		}
		$path = $this->getLocalCatalogPath() . '/' . $filename;
		if (!\is_readable($path)) {
			throw new AppManagerException(
				$this->l10n->t('Local marketplace catalog file is missing or unreadable: %s', [$path])
			);
		}
		$contents = \file_get_contents($path);
		if ($contents === false) {
			throw new AppManagerException(
				$this->l10n->t('Failed to read local marketplace catalog: %s', [$path])
			);
		}
		try {
			return \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new AppManagerException(
				$this->l10n->t('Local marketplace catalog %1$s is invalid JSON: %2$s', [$path, $e->getMessage()]),
				0,
				$e
			);
		}
	}

	/**
	 * @throws AppManagerException
	 */
	private function copyLocalFile(string $source, string $destination): void {
		if (!\is_readable($source)) {
			throw new AppManagerException(
				$this->l10n->t('Local app archive %s is not readable.', [$source])
			);
		}
		if (!@\copy($source, $destination)) {
			throw new AppManagerException(
				$this->l10n->t('Failed to copy local app archive from %1$s to %2$s.', [$source, $destination])
			);
		}
	}

	private function resolveCatalogRelativePath(string $relative): string {
		if (\str_starts_with($relative, '/')) {
			return $relative;
		}
		return $this->getLocalCatalogPath() . '/' . $relative;
	}
}
