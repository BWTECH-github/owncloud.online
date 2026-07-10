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
	private ?array $apps = null;
	private ?array $categories = null;
	private ?array $bundles = null;
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
		try {
			$this->httpService->validateKey($apiKey);
			return true;
		} catch (Exception) {
			return false;
		}
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
		$downloadLink = $release[0]['download'];

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
