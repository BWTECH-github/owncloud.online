<?php
/**
 * @author Viktar Dubiniuk <dubinuk@owncloud.com>
 * @author Ilja Neumann <ineumann@owncloud.com>
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
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

namespace OCA\Market;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use OCP\App\AppManagerException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
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

	/**
	 * Angaben zur eigenen Instanz. Mit ihnen ordnet der Marktplatz einen
	 * API-Schlüssel den Instanzen zu, die ihn benutzen. Gesendet werden sie
	 * nur zusammen mit dem Schlüssel und nur an die Herkunft von `appstoreurl`.
	 */
	public const HEADER_INSTANCE = 'X-Market-Instance';
	public const HEADER_SERVER_HOST = 'X-Market-Server-Host';
	public const HEADER_PLATFORM = 'X-Market-Platform';
	public const HEADER_CLIENT = 'X-Market-Client';

	/** Wie Guzzles Vorgabe für allow_redirects. */
	private const MAX_REDIRECTS = 5;

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
		private readonly IAppManager $appManager,
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
		// Diese Adresse steht im Katalog, stammt also von der Gegenstelle. Ein
		// lokaler Pfad wird deshalb nur im lokalen Katalogbetrieb angenommen
		// und nur innerhalb des Katalogverzeichnisses: sonst koennte ein
		// Marktplatz - oder wer seine Antwort beeinflusst - mit
		// 'file:///var/www/owncloud/config/config.php' eine beliebige lokale
		// Datei in den App-Installer schieben, statt ein Paket zu liefern.
		if ($this->isLocalCatalog()) {
			$source = \str_starts_with($url, 'file://') ? \substr($url, 7) : $url;
			if (!self::isHttpUrl($url)) {
				$this->copyLocalFile($this->resolveCatalogPath($source), $path);
				return;
			}
		} elseif (!self::isHttpUrl($url)) {
			throw new AppManagerException(
				(string) $this->l10n->t('Refusing to fetch an app package from a non-HTTP location: %s', [$url])
			);
		}

		$apiKey = $this->getApiKey();
		// Größeres Timeout als für API-Abfragen: App-Pakete können mehrere MB groß sein.
		$this->httpGet($url, ['sink' => $path, 'timeout' => 300], $apiKey);
	}

	private static function isHttpUrl(string $url): bool {
		// Nicht str_starts_with($url, 'http'): das trifft auch 'httpfoo://'.
		return \str_starts_with($url, 'https://') || \str_starts_with($url, 'http://');
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
	private function httpGet(string $url, array $options, ?string $apiKey): IResponse {
		// Guzzle-Default ist timeout=0 (unendlich): ohne Limit blockiert ein
		// hängender Marketplace Seitenaufrufe und die serielle Cron-Queue.
		// Aufrufer-Optionen (z.B. Download-Timeout) haben Vorrang.
		$options = \array_merge(
			['connect_timeout' => 10, 'timeout' => 30],
			$options
		);
		$ca = $this->config->getSystemValue('marketplace.ca', null);
		if ($ca !== null) {
			$options = \array_merge(['verify' => $ca], $options);
		}
		$client = $this->httpClientService->newClient();
		$sendKey = !empty($apiKey);
		for ($redirects = 0; ; $redirects++) {
			$instanceHeaders = $sendKey ? $this->getInstanceHeaders($url) : [];
			$requestOptions = $options;
			if ($sendKey) {
				$requestOptions = \array_merge(
					['headers' => ['Authorization' => "apikey: $apiKey"] + $instanceHeaders],
					$requestOptions
				);
			}
			if ($instanceHeaders === []) {
				// Kein Marktplatz-Ziel: Guzzle folgt Weiterleitungen wie bisher
				// und lässt den Schlüssel an einer Herkunftsgrenze selbst weg.
				return $this->send($client, $url, $requestOptions, $apiKey);
			}

			// Guzzle nimmt bei einer Weiterleitung auf einen fremden Host nur
			// Authorization und Cookie heraus, eigene Kopfzeilen gingen mit.
			// Solange die Instanzangaben dabei sind, folgen wir deshalb selbst.
			$requestOptions['allow_redirects'] = false;
			$response = $this->send($client, $url, $requestOptions, $apiKey);
			$next = $this->getRedirectTarget($url, $response);
			if ($next === null) {
				return $response;
			}
			if ($redirects >= self::MAX_REDIRECTS) {
				throw new AppManagerException(
					(string) $this->l10n->t('No marketplace connection: %s', ['too many redirects'])
				);
			}
			// Wie Guzzle: Der Schlüssel geht nicht über eine Herkunftsgrenze mit
			// (auch nicht von http auf https desselben Hosts).
			if (self::getOrigin($next) !== self::getOrigin($url)) {
				$sendKey = false;
			}
			$url = $next;
		}
	}

	/**
	 * @throws AppManagerException
	 */
	private function send(IClient $client, string $url, array $options, ?string $apiKey): IResponse {
		try {
			return $client->get($url, $options);
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
	 * Kopfzeilen mit den Angaben zur eigenen Instanz, wenn $url zur Herkunft
	 * von `appstoreurl` gehört (Schema, Host und Port), sonst keine. Werte,
	 * die der Marktplatz nicht annehmen würde, bleiben weg; eine kaputte
	 * Einstellung darf die Verbindung nicht stören.
	 *
	 * @return array<string, string>
	 */
	private function getInstanceHeaders(string $url): array {
		$storeOrigin = self::getOrigin((string) $this->config->getSystemValue('appstoreurl', self::DEFAULT_STORE_URL));
		if ($storeOrigin === null || self::getOrigin($url) !== $storeOrigin) {
			return [];
		}

		$version = $this->getOwnVersion();
		$headers = ['User-Agent' => 'owncloud.online-market' . ($version !== null ? '/' . $version : '')];
		if ($version !== null) {
			$headers[self::HEADER_CLIENT] = 'market/' . $version;
		}
		$instanceId = $this->config->getSystemValue('instanceid', '');
		if (\is_string($instanceId) && \preg_match('/^[a-z0-9]{6,64}$/D', $instanceId) === 1) {
			$headers[self::HEADER_INSTANCE] = $instanceId;
		}
		$platform = $this->versionHelper->getPlatformVersion();
		if (\preg_match('/^\d+(?:\.\d+){1,3}$/D', $platform) === 1) {
			$headers[self::HEADER_PLATFORM] = $platform;
		}
		$host = $this->getServerHost();
		if ($host !== null) {
			$headers[self::HEADER_SERVER_HOST] = $host;
		}
		return $headers;
	}

	/** Eigene Version aus appinfo/info.xml oder null, wenn sie nicht lesbar ist. */
	private function getOwnVersion(): ?string {
		$info = $this->appManager->getAppInfo('market');
		$version = \is_array($info) ? ($info['version'] ?? null) : null;
		return \is_string($version) && \preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1 ? $version : null;
	}

	/**
	 * Hostname dieser Instanz aus `overwrite.cli.url`, sonst der erste
	 * brauchbare Eintrag in `trusted_domains`; nur der Host, ohne Schema,
	 * Port und Pfad. Mit `marketplace.send_host` = false bleibt er weg.
	 */
	private function getServerHost(): ?string {
		// Auch Text wie 'false' zählt: occ config:system:set speichert ohne
		// --type=boolean einen Text, und gemeint ist trotzdem „aus“.
		$sendHost = $this->config->getSystemValue('marketplace.send_host', true);
		if (\filter_var($sendHost, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) === false) {
			return null;
		}
		$candidates = [$this->config->getSystemValue('overwrite.cli.url', '')];
		$trustedDomains = $this->config->getSystemValue('trusted_domains', []);
		if (\is_array($trustedDomains)) {
			\array_push($candidates, ...\array_values($trustedDomains));
		}
		foreach ($candidates as $candidate) {
			$host = \is_string($candidate) ? self::reportableHost($candidate) : null;
			if ($host !== null) {
				return $host;
			}
		}
		return null;
	}

	/**
	 * Host aus einer Adresse oder einem Eintrag wie „cloud.example.de:8443“:
	 * klein geschrieben, internationale Namen als Punycode. null, wenn dabei
	 * kein Hostname nach RFC 1123 herauskommt (etwa bei einer IPv6-Adresse
	 * oder einem Platzhalter wie „*“) oder der Host nur die Maschine selbst
	 * meint: Nach einer Installation über occ steht in `overwrite.cli.url`
	 * http://localhost, und das sagt dem Marktplatz nichts.
	 */
	private static function reportableHost(string $value): ?string {
		$value = \trim($value);
		if ($value === '') {
			return null;
		}
		// Ohne Schema liest parse_url den Host als Pfad.
		$host = \parse_url(\str_contains($value, '://') ? $value : '//' . $value, \PHP_URL_HOST);
		if (!\is_string($host) || $host === '') {
			return null;
		}
		$host = \rtrim(\strtolower($host), '.');
		if (\preg_match('/[^\x00-\x7f]/', $host) === 1) {
			if (!\function_exists('idn_to_ascii')) {
				return null;
			}
			$host = \idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
			if (!\is_string($host)) {
				return null;
			}
		}
		$label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
		if (\strlen($host) > 253 || \preg_match('/^' . $label . '(?:\.' . $label . ')*$/D', $host) !== 1) {
			return null;
		}
		if ($host === 'localhost' || \str_ends_with($host, '.localhost') || \preg_match('/^127(?:\.\d{1,3}){3}$/D', $host) === 1) {
			return null;
		}
		return $host;
	}

	/**
	 * Herkunft einer Adresse (Schema, Host und Port, so wie Guzzle sie
	 * auflöst) oder null, wenn es keine http(s)-Adresse mit Host ist.
	 */
	private static function getOrigin(string $url): ?string {
		try {
			$uri = new Uri($url);
		} catch (\InvalidArgumentException) {
			return null;
		}
		$scheme = $uri->getScheme();
		if (($scheme !== 'http' && $scheme !== 'https') || $uri->getHost() === '') {
			return null;
		}
		return $scheme . '://' . $uri->getHost() . ':' . ($uri->getPort() ?? ($scheme === 'https' ? 443 : 80));
	}

	/**
	 * Ziel einer Weiterleitung, aufgelöst gegen die angefragte Adresse, oder
	 * null, wenn die Antwort keine Weiterleitung ist.
	 *
	 * @throws AppManagerException
	 */
	private function getRedirectTarget(string $url, IResponse $response): ?string {
		$status = (int) $response->getStatusCode();
		$location = \trim((string) $response->getHeader('Location'));
		if ($status < 300 || $status > 399 || $location === '') {
			return null;
		}
		try {
			$target = (string) UriResolver::resolve(new Uri($url), new Uri($location));
		} catch (\InvalidArgumentException) {
			$target = '';
		}
		if (self::getOrigin($target) === null) {
			throw new AppManagerException(
				(string) $this->l10n->t('No marketplace connection: %s', ['redirect to an unsupported location'])
			);
		}
		return $target;
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

	/**
	 * Einen Pfad aus dem Katalog auf eine Datei IM Katalogverzeichnis
	 * abbilden. Alles andere wird abgewiesen - ein '..' oder ein absoluter
	 * Pfad im Katalog ist kein Paket, sondern ein Versuch, an einer Datei zu
	 * kommen, die dem Installer nicht zusteht.
	 *
	 * @throws AppManagerException
	 */
	private function resolveCatalogPath(string $source): string {
		$root = \realpath($this->getLocalCatalogPath());
		if ($root === false) {
			throw new AppManagerException(
				(string) $this->l10n->t('Local marketplace catalog directory %s does not exist.', [$this->getLocalCatalogPath()])
			);
		}
		$root = \rtrim($root, '/');

		$candidate = \str_starts_with($source, '/') ? $source : $root . '/' . $source;
		$resolved = \realpath($candidate);
		if ($resolved === false || ($resolved !== $root && !\str_starts_with($resolved, $root . '/'))) {
			throw new AppManagerException(
				(string) $this->l10n->t('Local app archive %s is not inside the catalog directory.', [$source])
			);
		}

		return $resolved;
	}
}
