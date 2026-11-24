<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

use Exception;
use OC\App\DependencyAnalyzer;
use OC\App\Platform;
use OCA\Market\Exception\LicenseKeyAlreadyAvailableException;
use OCA\Market\Exception\MarketException;
use OCP\App\AppAlreadyInstalledException;
use OCP\App\AppManagerException;
use OCP\App\AppNotFoundException;
use OCP\App\AppNotInstalledException;
use OCP\App\AppUpdateNotFoundException;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class MarketService {
	/** Kontakt für Support-Verträge, solange der Marktplatz keinen nennt. */
	public const SUPPORT_EMAIL = 'support@owncloudonline.com';
	public const SUPPORT_URL = 'https://owncloud.online';

	/** Ab so vielen Resttagen gilt ein Support-Vertrag als „läuft bald ab“. */
	private const EXPIRES_SOON_DAYS = 30;

	private ?array $apps = null;
	private ?array $categories = null;
	private ?array $bundles = null;
	private ?array $supportContact = null;
	/** @var array<string, array> Memo Schlüssel => getKeyStatus() je Prozess */
	private array $keyStatus = [];
	/** @var array<string, true> Schlüssel, deren Statusabfrage den Marktplatz nicht erreichte */
	private array $keyStatusUnreachable = [];
	/** @var array<string, array>|null Memo appId => info.xml-Daten der installierten Apps */
	private ?array $installedAppInfos = null;
	private ?DependencyAnalyzer $dependencyAnalyzer = null;

	public function __construct(
		private readonly HttpService $httpService,
		private readonly VersionHelper $versionHelper,
		private readonly IAppManager $appManager,
		private readonly IConfig $config,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * Check if we can install apps in general.
	 */
	public function canInstall(): bool {
		return $this->appManager->canInstall();
	}

	public function isAppInstalled(string $appId): bool {
		return $this->getInstalledAppInfo($appId) !== null;
	}

	/**
	 * Get application data provided by info.xml.
	 */
	public function getInstalledAppInfo(string $appId): ?array {
		// Einmalig pro Prozess als Map aufbauen: enrichApp fragt das sonst
		// mehrfach pro Katalog-App ab (linearer Scan über alle Apps je Aufruf).
		if ($this->installedAppInfos === null) {
			$infos = [];
			foreach ($this->appManager->getAllApps() as $app) {
				$info = $this->appManager->getAppInfo($app);
				if (isset($info['id'])) {
					$infos[$info['id']] = $info;
				}
			}
			$this->installedAppInfos = $infos;
		}
		return $this->installedAppInfos[$appId] ?? null;
	}

	/**
	 * Installations-Status kann sich innerhalb eines Prozesses ändern
	 * (occ-Befehle, Repair-Steps) — Memo dann verwerfen.
	 */
	private function invalidateInstalledAppInfoCache(): void {
		$this->installedAppInfos = null;
	}

	/**
	 * Install an app for the given app id.
	 *
	 * @throws AppAlreadyInstalledException
	 * @throws AppManagerException
	 * @throws Exception
	 */
	public function installApp(string $appId, bool $skipMigrations = false): void {
		if (!$this->canInstall()) {
			throw new Exception("Installing apps is not supported because the app folder is not writable.");
		}
		$this->invalidateInstalledAppInfoCache();

		$marketInfo = $this->getAppInfo($appId);
		if ($marketInfo === null) {
			throw new AppNotFoundException($this->l10n->t('Unknown app (%s)', [$appId]));
		}

		$platformVersion = $this->versionHelper->getPlatformVersion(2);

		// Platform versions less than 10.5 don't require enterprise-key app
		if ($this->versionHelper->compare($platformVersion, "10.5", "<")) {
			$availableReleases = $marketInfo['releases'];
			if (\array_shift($availableReleases)['license'] === 'ownCloud Commercial License') {
				$license = $this->getLicenseKey();
				if ($license === null) {
					throw new Exception($this->l10n->t('Please enter a license-key in to config.php'));
				}
				if ($appId !== 'enterprise_key') {
					if (!$this->appManager->isEnabledForUser('enterprise_key')) {
						throw new Exception($this->l10n->t('Please install and enable the enterprise_key app and enter a license-key in config.php first.'));
					}
					if (\class_exists('\OCA\Enterprise_Key\EnterpriseKey')) {
						$e = new \OCA\Enterprise_Key\EnterpriseKey($license, $this->config);
						if (!$e->check()) {
							throw new Exception($this->l10n->t('Your license-key is not valid.'));
						}
					}
				}
			}
		}

		$info = $this->getInstalledAppInfo($appId);
		if ($info !== null) {
			throw new AppAlreadyInstalledException($this->l10n->t('App %s is already installed', [$appId]));
		}

		$package = $this->downloadPackage($appId);
		$this->installPackage($package, $skipMigrations);
		try {
			$this->appManager->enableApp($appId);
		} catch (Exception $e) {
			// Rollback: die halb installierte App würde sonst jeden weiteren
			// Installationsversuch mit AppAlreadyInstalledException blockieren.
			try {
				$this->removeDownloadedApp($appId);
			} catch (Exception) {
				// Best effort — der ursprüngliche Fehler hat Vorrang.
			}
			throw $e;
		}
	}

	/**
	 * @throws AppManagerException
	 */
	public function uninstallApp(string $appId): void {
		if (!$this->canInstall()) {
			throw new Exception("Installing apps is not supported because the app folder is not writable.");
		}

		if ($this->appManager->isShipped($appId)) {
			throw new AppManagerException($this->l10n->t('Shipped apps cannot be uninstalled'));
		}

		if ($appId === 'market') {
			throw new AppManagerException($this->l10n->t('Market app can not uninstall itself.'));
		}

		if (!$this->removeDownloadedApp($appId)) {
			throw new AppManagerException($this->l10n->t('App (%s) could not be uninstalled. Please check the server logs.', [$appId]));
		}
	}

	/**
	 * @throws AppManagerException
	 * @throws AppNotFoundException
	 * @throws AppNotInstalledException
	 * @throws AppUpdateNotFoundException
	 */
	public function updateApp(string $appId, ?string $targetVersion = null): void {
		if (!$this->canInstall()) {
			throw new Exception("Installing apps is not supported because the app folder is not writable.");
		}
		$this->invalidateInstalledAppInfoCache();

		$info = $this->getInstalledAppInfo($appId);
		if ($info === null) {
			throw new AppNotInstalledException($this->l10n->t('App (%s) is not installed', [$appId]));
		}

		$package = $this->downloadPackage($appId, $targetVersion);
		$this->updatePackage($package);
	}

	/**
	 * Install downloaded package.
	 */
	public function installPackage(string $package, bool $skipMigrations = false): string|false|null {
		$this->invalidateInstalledAppInfoCache();
		return $this->appManager->installApp($package, $skipMigrations);
	}

	public function updatePackage(string $package): string|false|null {
		$this->invalidateInstalledAppInfoCache();
		return $this->appManager->updateApp($package);
	}

	/**
	 * Get appinfo from package.
	 *
	 * @return array<string, mixed>
	 */
	public function readAppPackage(string $path): array {
		return $this->appManager->readAppPackage($path);
	}

	/**
	 * Get apps which need to be updated.
	 */
	public function getUpdates(): array {
		$result = [];
		foreach ($this->appManager->getAllApps() as $app) {
			$info = $this->appManager->getAppInfo($app);
			if (!isset($info['id'])) {
				continue;
			}
			try {
				$appId = $info['id'];
				$newVersions = $this->getAvailableUpdateVersions($appId);
				if ($newVersions['major'] !== false || $newVersions['minor'] !== false) {
					$result[$app] = \array_merge($newVersions, ['id' => $appId]);
				}
			} catch (AppNotInstalledException|AppNotFoundException) {
				// not installed yet, or app is not published at the marketplace - both are fine
			}
		}
		return $result;
	}

	/**
	 * Get available minor and major versions as string or false.
	 *
	 * @return array{major: string|false, minor: string|false}
	 *
	 * @throws AppNotFoundException
	 * @throws AppNotInstalledException
	 */
	public function getAvailableUpdateVersions(string $appId): array {
		$info = $this->getInstalledAppInfo($appId);
		if ($info === null) {
			throw new AppNotInstalledException($this->l10n->t('App (%s) is not installed', [$appId]));
		}
		$marketInfo = $this->getAppInfo($appId);
		if ($marketInfo === null) {
			throw new AppNotFoundException($this->l10n->t('App (%s) is not known at the marketplace.', [$appId]));
		}
		if (self::isLocked($marketInfo)) {
			// Ohne Support-Vertrag gibt es kein Paket: kein Update melden, das
			// sich nicht installieren lässt.
			return ['major' => false, 'minor' => false];
		}
		$currentVersion = (string) $info['version'];
		return [
			'major' => $this->filterReleases($marketInfo, $currentVersion, true),
			'minor' => $this->filterReleases($marketInfo, $currentVersion, false),
		];
	}

	/**
	 * Choose between major and minor versions.
	 * Major is chosen only if it is allowed; if it is allowed but does not exist falls back to minor.
	 *
	 * @param array{major: string|false, minor: string|false} $updateVersions
	 */
	public function chooseCandidate(array $updateVersions, bool $isMajorAllowed): string|false {
		$updateVersion = $isMajorAllowed ? $updateVersions['major'] : $updateVersions['minor'];
		if ($isMajorAllowed === true && $updateVersion === false) {
			$updateVersion = $updateVersions['minor'];
		}
		return $updateVersion;
	}

	/**
	 * Verify if all requirements are met.
	 */
	public function getMissingDependencies(array $appInfo): array {
		// bad hack - should use OCP
		// Analyzer einmal pro Prozess bauen: läuft sonst für jedes Release
		// jeder Katalog-App neu (Katalog-Apps x Releases Instanzen).
		$this->dependencyAnalyzer ??= new DependencyAnalyzer(
			new Platform($this->config),
			\OC::$server->getL10N('settings')
		);
		return $this->dependencyAnalyzer->analyze($appInfo);
	}

	/**
	 * Get application data provided by marketplace.
	 */
	public function getAppInfo(string $appId): ?array {
		$data = \array_filter(
			$this->getApps(),
			static fn ($element): bool => $element['id'] === $appId
		);
		if (empty($data)) {
			return null;
		}
		return \reset($data);
	}

	/**
	 * @throws AppManagerException
	 */
	public function getBundles(): array {
		return $this->bundles ??= ($this->httpService->getBundles() ?? []);
	}

	/**
	 * @throws AppManagerException
	 */
	public function getCategories(): array {
		return $this->categories ??= ($this->httpService->getCategories() ?? []);
	}

	/**
	 * Get app list from marketplace, optionally filtered by category.
	 */
	public function listApps(?string $category = null): array {
		$apps = $this->getApps();
		if ($category !== null) {
			$apps = \array_filter(
				$apps,
				static fn ($app): bool => \in_array($category, $app['categories'], true)
			);
		}
		return $apps;
	}

	public function getApiKey(): ?string {
		return $this->httpService->getApiKey();
	}

	public function setApiKey(string $apiKey): bool {
		if ($this->isApiKeyChangeableByUser()) {
			$this->config->deleteAppValue('market', 'key');
			$this->config->setAppValue('market', 'key', $apiKey);
			$this->httpService->invalidateCache();
			return true;
		}
		return false;
	}

	public function isApiKeyValid(string $apiKey): bool {
		if ($apiKey === '') {
			return true;
		}
		// Kennt der Marktplatz die Statusabfrage, entscheidet sie: Ein
		// Schlüssel, den er ablehnt, wird gar nicht erst gespeichert.
		$state = $this->getKeyStatus($apiKey)['state'];
		if ($state === 'invalid' || isset($this->keyStatusUnreachable[$apiKey])) {
			// Ohne Verbindung scheiterte auch die Prüfung unten, nur später.
			return false;
		}
		if ($state !== 'unknown') {
			return true;
		}
		try {
			$this->httpService->validateKey($apiKey);
			return true;
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * Stand des Support-Vertrags zum Schlüssel $apiKey (ohne Angabe: zum
	 * gespeicherten), aufbereitet für die Anzeige:
	 *
	 * - state: active, expiring, expired, blocked, pending, system_blocked,
	 *   update, invalid, none, local oder unknown
	 * - label: kurzer Zustand, message: Grund in der Sprache des Nutzers
	 * - rows: weitere Angaben als Liste von [label, value]
	 *
	 * @return array{state: string, label: string, message: string, rows: array<int, array{label: string, value: string}>}
	 */
	public function getKeyStatus(?string $apiKey = null): array {
		$apiKey ??= $this->getApiKey();
		if ($apiKey === null || $apiKey === '') {
			return $this->keyStatusResult('none', '');
		}
		if (isset($this->keyStatus[$apiKey])) {
			return $this->keyStatus[$apiKey];
		}
		if ($this->httpService->isLocalCatalog()) {
			return $this->keyStatusResult('local', (string) $this->l10n->t('This system uses the local app catalog and needs no key.'));
		}

		try {
			$response = $this->httpService->fetchKeyStatus($apiKey);
		} catch (Exception $e) {
			// Auch das merken: Ein zweiter Versuch in derselben Anfrage liefe
			// nur erneut in das Zeitlimit.
			$this->keyStatusUnreachable[$apiKey] = true;
			return $this->keyStatus[$apiKey] = $this->keyStatusResult('unknown', $e->getMessage());
		}
		$code = $response['status'];
		$body = $response['body'];
		$result = match (true) {
			\is_array($body) && \is_string($body['status'] ?? null) => $this->keyStatusFromPayload($body),
			$code === 401 => $this->keyStatusResult('invalid', (string) $this->l10n->t('This key is not valid.')),
			$code === 404 => $this->keyStatusResult('unknown', (string) $this->l10n->t('The marketplace does not show the status of support contracts.')),
			$code === 429 => $this->keyStatusResult('unknown', (string) $this->l10n->t('Too many attempts. Please try again later.')),
			default => $this->keyStatusResult(
				'unknown',
				self::plainText($body['message'] ?? null, 300) ?? (string) $this->l10n->t('No marketplace connection: %s', ['HTTP ' . $code])
			),
		};
		return $this->keyStatus[$apiKey] = $result;
	}

	/**
	 * Kontakt für Support-Verträge aus /api/v1/support.json, ohne Antwort
	 * (alter Marktplatz, lokaler Katalog, keine Verbindung) die Vorgabe.
	 * Adresse und Link werden geprüft, weil sie als mailto:/href in der
	 * Oberfläche landen; Titel und Text sind reiner Text.
	 *
	 * @return array{email: string, url: string, title: string, text: string}
	 */
	public function getSupportContact(): array {
		if ($this->supportContact !== null) {
			return $this->supportContact;
		}
		try {
			$data = $this->httpService->getSupport();
		} catch (Exception) {
			$data = null;
		}
		$data = \is_array($data) ? $data : [];
		$suffix = $this->prefersGerman() ? 'de' : 'en';

		$email = \is_string($data['email'] ?? null) ? \trim($data['email']) : '';
		$url = \is_string($data['url'] ?? null) ? \trim($data['url']) : '';
		return $this->supportContact = [
			'email' => \filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : self::SUPPORT_EMAIL,
			'url' => self::isWebUrl($url) ? $url : self::SUPPORT_URL,
			'title' => self::plainText($data['title_' . $suffix] ?? null, 80) ?? (string) $this->l10n->t('Support contract'),
			'text' => self::plainText($data['text_' . $suffix] ?? null, 500) ?? (string) $this->l10n->t('Available with a support contract.'),
		];
	}

	/**
	 * Meldung, wenn eine App mit Support-Vertrag nicht geladen werden darf;
	 * mit Kontakt, damit auch occ sagt, wohin man sich wendet.
	 */
	public function getSupportContractMessage(): string {
		$contact = $this->getSupportContact();
		return (string) $this->l10n->t(
			'Available with a support contract. Request one at %1$s or %2$s.',
			[$contact['email'], $contact['url']]
		);
	}

	/**
	 * Geschützte App, die dieses System nicht laden darf: Der Marktplatz
	 * listet sie für Market ab 0.11.0 mit entitled=false und ohne
	 * Download-Adresse. Ältere Marktplätze senden das Feld nicht; dann gilt
	 * eine App wie bisher als freigegeben.
	 */
	public static function isLocked(array $app): bool {
		return ($app['entitled'] ?? true) === false;
	}

	public function isAppLocked(string $appId): bool {
		$info = $this->getAppInfo($appId);
		return $info !== null && self::isLocked($info);
	}

	/** Spricht der Nutzer Deutsch (de, de_DE, de_CH, de-AT …)? */
	public function prefersGerman(): bool {
		return \str_starts_with(\strtolower((string) $this->l10n->getLanguageCode()), 'de');
	}

	/**
	 * Kurzbeschreibung und Beschreibung einer Katalog-App in der Sprache des
	 * Nutzers: Deutsch aus summary_de/description_de, solange dort etwas
	 * steht, sonst bleibt der englische Text.
	 */
	public static function localizeTexts(array $app, bool $german): array {
		if (!$german) {
			return $app;
		}
		foreach (['summary', 'description'] as $field) {
			$text = $app[$field . '_de'] ?? null;
			if (\is_string($text) && \trim($text) !== '') {
				$app[$field] = $text;
			}
		}
		return $app;
	}

	/**
	 * @param array<string, mixed> $payload Antwort von /api/v1/key/status
	 */
	private function keyStatusFromPayload(array $payload): array {
		$status = \is_string($payload['status'] ?? null) ? $payload['status'] : '';
		$systemStatus = \is_string($payload['instance_status'] ?? null) ? $payload['instance_status'] : '';
		$expiresAt = \is_string($payload['expires_at'] ?? null) && \strtotime($payload['expires_at']) !== false
			? $payload['expires_at']
			: null;
		$days = null;
		if (\is_int($payload['expires_in_days'] ?? null)) {
			$days = $payload['expires_in_days'];
		} elseif ($expiresAt !== null) {
			$days = (int) \floor((\strtotime($expiresAt) - \time()) / 86400);
		}
		// Wie der Marktplatz: „läuft bald ab“ bei weniger als 30 Tagen. Nennt er
		// den Zustand selbst (contract_status), gilt der.
		$contractStatus = \is_string($payload['contract_status'] ?? null) ? $payload['contract_status'] : null;
		$expiring = $contractStatus !== null
			? $contractStatus === 'expiring'
			: $days !== null && $days < self::EXPIRES_SOON_DAYS;
		// Gilt der Vertrag, lädt dieses System. „Zu viele Systeme: trotzdem
		// zulassen und markieren“ sieht nur die Verwaltung.
		if ($status === 'valid' && $systemStatus === 'pending') {
			$systemStatus = 'approved';
		}

		$state = match ($status) {
			'valid' => $expiring ? 'expiring' : 'active',
			'expired' => 'expired',
			'paused', 'disabled' => 'blocked',
			'instance_pending' => 'pending',
			'instance_blocked' => 'system_blocked',
			'client_update_required' => 'update',
			'invalid', 'revoked' => 'invalid',
			default => 'unknown',
		};

		$german = $this->prefersGerman();
		$message = self::plainText($payload[$german ? 'message_de' : 'message_en'] ?? null, 300)
			?? self::plainText($payload[$german ? 'message_en' : 'message_de'] ?? null, 300)
			?? '';

		$rows = [];
		$contract = self::plainText($payload['contract_label'] ?? null, 120);
		if ($contract !== null) {
			$rows[] = ['label' => (string) $this->l10n->t('Contract'), 'value' => $contract];
		}
		if (!\in_array($state, ['invalid', 'unknown'], true)) {
			$rows[] = [
				'label' => (string) $this->l10n->t('Valid until'),
				'value' => $expiresAt !== null ? $this->formatDate($expiresAt) : (string) $this->l10n->t('No end date'),
			];
		}
		$instances = $payload['instances'] ?? null;
		if (\is_array($instances) && \is_int($instances['used'] ?? null)) {
			$max = \is_int($instances['max'] ?? null) ? $instances['max'] : null;
			$rows[] = [
				'label' => (string) $this->l10n->t('Systems'),
				'value' => $max !== null
					? (string) $this->l10n->t('%1$s of %2$s', [$instances['used'], $max])
					: (string) $this->l10n->t('%s (no limit)', [$instances['used']]),
			];
		}
		$systemLabel = match ($systemStatus) {
			'approved' => $this->l10n->t('Allowed'),
			'pending' => $this->l10n->t('Not allowed yet'),
			'blocked' => $this->l10n->t('Blocked'),
			default => null,
		};
		if ($systemLabel !== null) {
			$rows[] = ['label' => (string) $this->l10n->t('This system'), 'value' => (string) $systemLabel];
		}

		return $this->keyStatusResult($state, $message, $rows);
	}

	/**
	 * @param array<int, array{label: string, value: string}> $rows
	 */
	private function keyStatusResult(string $state, string $message, array $rows = []): array {
		$label = match ($state) {
			'active' => $this->l10n->t('Active'),
			'expiring' => $this->l10n->t('Expires soon'),
			'expired' => $this->l10n->t('Expired'),
			'blocked' => $this->l10n->t('Temporarily blocked'),
			'pending' => $this->l10n->t('Waiting to be allowed'),
			'system_blocked' => $this->l10n->t('System blocked'),
			'update' => $this->l10n->t('Update the Market app'),
			'invalid' => $this->l10n->t('Invalid'),
			'none' => $this->l10n->t('No key saved'),
			'local' => $this->l10n->t('Local catalog'),
			default => $this->l10n->t('Status unknown'),
		};
		return ['state' => $state, 'label' => (string) $label, 'message' => $message, 'rows' => $rows];
	}

	/** Datum des Vertragsendes; der Marktplatz legt es auf das Tagesende in Berlin. */
	private function formatDate(string $isoDate): string {
		try {
			$date = (new \DateTime($isoDate))->setTimezone(new \DateTimeZone('Europe/Berlin'));
		} catch (Exception) {
			return $isoDate;
		}
		return (string) $this->l10n->l('date', $date, ['width' => 'medium']);
	}

	/** Einzeiliger Text ohne Steuerzeichen, gekürzt; null, wenn nichts bleibt. */
	private static function plainText(mixed $value, int $maxLength): ?string {
		if (!\is_string($value)) {
			return null;
		}
		$text = \trim((string) \preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
		if ($text === '') {
			return null;
		}
		return \mb_strlen($text) > $maxLength ? \rtrim(\mb_substr($text, 0, $maxLength - 1)) . '…' : $text;
	}

	private static function isWebUrl(string $url): bool {
		$scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));
		return ($scheme === 'https' || $scheme === 'http')
			&& \filter_var($url, FILTER_VALIDATE_URL) !== false
			&& \preg_match('/[\s<>"\']/', $url) !== 1;
	}

	/**
	 * ApiKey can only be changed by the user when no key is configured in config.php.
	 */
	public function isApiKeyChangeableByUser(): bool {
		return !$this->config->getSystemValue('marketplace.key', null);
	}

	public function hasLicenseKey(): bool {
		return $this->getLicenseKey() !== null;
	}

	/**
	 * @throws LicenseKeyAlreadyAvailableException
	 * @throws MarketException
	 */
	public function requestLicenseKey(): string {
		if ($this->hasLicenseKey()) {
			throw new LicenseKeyAlreadyAvailableException();
		}

		$data = $this->httpService->getDemoKey();
		if (!\is_array($data) || !\array_key_exists('license_key', $data)) {
			throw new MarketException('Marketplace did not return a demo license key.');
		}

		$demoLicenseKey = $data['license_key'];
		if (!$demoLicenseKey) {
			throw new MarketException('Marketplace returned an empty demo license key.');
		}

		$this->config->setAppValue('enterprise_key', 'license-key', $demoLicenseKey);
		$this->httpService->invalidateCache();
		return $demoLicenseKey;
	}

	public function invalidateCache(): void {
		$this->httpService->invalidateCache();
	}

	/**
	 * Returns the version for the app if an update is available.
	 *
	 * @param array<string, mixed> $marketInfo
	 *
	 * @throws AppNotFoundException
	 * @throws AppNotInstalledException
	 */
	private function filterReleases(array $marketInfo, string $currentVersion, bool $isMajorUpdate): string|false {
		$platformVersion = $this->versionHelper->getPlatformVersion();
		$releases = \array_filter(
			$marketInfo['releases'],
			function (array $r) use ($currentVersion, $isMajorUpdate, $platformVersion): bool {
				$marketVersion = $r['version'];
				$isDifferentMajor = !$this->versionHelper->isSameMajorVersion($marketVersion, $currentVersion);
				if ($isMajorUpdate !== $isDifferentMajor) {
					return false;
				}
				if (!\version_compare($marketVersion, $currentVersion, '>')) {
					return false;
				}
				// Nur Plattform-kompatible Releases melden (gleicher Check wie
				// in downloadPackage), sonst entstehen Update-Benachrichtigungen,
				// die beim Installieren in "No compatible version" enden.
				// Fehlendes platformMin/platformMax bedeutet "keine Grenze" —
				// NICHT gegen null vergleichen, da compare($v, null, '>') via
				// version_compare($v, '', '>') === true wäre und dann jedes
				// Release ohne Obergrenze fälschlich als inkompatibel gälte.
				$tooSmall = isset($r['platformMin'])
					&& $this->versionHelper->compare($platformVersion, $r['platformMin'], '<');
				$tooBig = isset($r['platformMax'])
					&& $this->versionHelper->compare($platformVersion, $r['platformMax'], '>');
				return $tooSmall === false && $tooBig === false;
			}
		);
		\usort(
			$releases,
			static fn (array $a, array $b): int => \version_compare($a['version'], $b['version'])
		);
		if (!empty($releases)) {
			return \array_pop($releases)['version'];
		}
		return false;
	}

	private function getLicenseKey(): ?string {
		$licenseKey = $this->config->getSystemValue('license-key');
		if ($licenseKey) {
			return $licenseKey;
		}
		return $this->config->getAppValue('enterprise_key', 'license-key', null) ?: null;
	}

	private function getApps(): array {
		return $this->apps ??= ($this->httpService->getApps() ?? []);
	}

	/**
	 * @throws AppManagerException
	 * @throws AppNotFoundException
	 * @throws AppUpdateNotFoundException
	 */
	private function downloadPackage(string $appId, ?string $targetVersion = null): string {
		$this->httpService->checkInternetConnection();
		$data = $this->getAppInfo($appId);
		if (empty($data)) {
			throw new AppNotFoundException($this->l10n->t('Unknown app (%s)', [$appId]));
		}
		if (self::isLocked($data)) {
			throw new AppManagerException($this->getSupportContractMessage());
		}

		$version = $this->versionHelper->getPlatformVersion();
		$release = \array_filter(
			$data['releases'],
			function ($element) use ($version, $targetVersion): bool {
				if ($targetVersion !== null && $element['version'] !== $targetVersion) {
					return false;
				}
				$tooSmall = $this->versionHelper->compare($version, $element['platformMin'], '<');
				$tooBig = $this->versionHelper->compare($version, $element['platformMax'], '>');
				return $tooSmall === false && $tooBig === false;
			}
		);
		if (empty($release)) {
			throw new AppUpdateNotFoundException($this->l10n->t('No compatible version for %s', [$appId]));
		}
		// Downgrade-Schutz: Ist die App bereits installiert und wurde keine
		// explizite Ziel-Version verlangt, nur echte Updates (> installiert) zulassen.
		// Verhindert, dass ein versehentliches Update auf ein aelteres Katalog-Release
		// zurueckfaellt und dessen (evtl. entfernte) Datei einen 404 wirft.
		if ($targetVersion === null) {
			$installed = $this->getInstalledAppInfo($appId);
			if ($installed !== null && !empty($installed['version'])) {
				$release = \array_filter(
					$release,
					fn ($element): bool => \version_compare($element['version'], $installed['version'], '>')
				);
				if (empty($release)) {
					throw new AppUpdateNotFoundException($this->l10n->t('No newer version available for %s', [$appId]));
				}
			}
		}
		\usort($release, static fn ($a, $b): int => \version_compare($b['version'], $a['version']));
		$release = \array_values($release);
		$downloadLink = $release[0]['download'] ?? '';
		if (!\is_string($downloadLink) || $downloadLink === '') {
			// Eine leere Adresse nie anfragen: Sie träfe sonst die Seite des
			// Marktplatzes oder würde als lokaler Pfad gelesen.
			throw new AppManagerException(
				($data['requiresKey'] ?? false) === true
					? $this->getSupportContractMessage()
					: (string) $this->l10n->t('No download available for %s.', [$appId])
			);
		}

		$pathInfo = \pathinfo($downloadLink);
		$extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
		$path = \OC::$server->getTempManager()->getTemporaryFile($extension);
		$this->httpService->downloadApp($downloadLink, $path);
		$this->verifyDownloadChecksum($appId, $release[0], $path);
		return $path;
	}

	/**
	 * Vergleicht die im Katalog deklarierte sha256-Checksumme ('checksum' =>
	 * 'sha256:<hex>') mit dem heruntergeladenen Paket. Releases ohne
	 * Checksummen-Feld oder mit anderem Algorithmus bleiben erlaubt
	 * (Fremdkataloge, ältere Backends).
	 *
	 * @throws AppManagerException
	 */
	private function verifyDownloadChecksum(string $appId, array $release, string $path): void {
		$checksum = $release['checksum'] ?? null;
		if (!\is_string($checksum) || !\str_starts_with($checksum, 'sha256:')) {
			return;
		}
		$expected = \strtolower(\trim(\substr($checksum, \strlen('sha256:'))));
		$actual = \hash_file('sha256', $path);
		if ($actual === false || !\hash_equals($expected, $actual)) {
			throw new AppManagerException(
				$this->l10n->t('Downloaded package for %s failed the sha256 checksum verification.', [$appId])
			);
		}
	}

	private function removeDownloadedApp(string $appId): bool {
		$appPath = $this->appManager->getAppPath($appId);
		if ($appPath === false) {
			return false;
		}

		$realPath = \realpath($appPath);
		if ($realPath === false || \basename($realPath) !== $appId || !\is_dir($realPath)) {
			return false;
		}
		if (\is_dir($realPath . '/.git')) {
			throw new AppAlreadyInstalledException("App <$appId> is a git clone - it will not be deleted.");
		}

		try {
			if ($this->appManager->isEnabledForUser($appId)) {
				$this->appManager->disableApp($appId);
			}
		} catch (Exception) {
			// Continue with file removal; disabled or broken apps can still be removed.
		}

		$this->removeDirectory($realPath);
		$this->appManager->clearAppsCache();
		$this->invalidateInstalledAppInfoCache();
		return !\file_exists($realPath);
	}

	private function removeDirectory(string $path): void {
		if (!\is_dir($path)) {
			throw new RuntimeException("Path <$path> is not a directory.");
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			$itemPath = $item->getPathname();
			if ($item->isDir() && !$item->isLink()) {
				if (!@\rmdir($itemPath)) {
					throw new RuntimeException("Could not remove directory <$itemPath>.");
				}
				continue;
			}
			if (!@\unlink($itemPath)) {
				throw new RuntimeException("Could not remove file <$itemPath>.");
			}
		}
		if (!@\rmdir($path)) {
			throw new RuntimeException("Could not remove directory <$path>.");
		}
	}
}
