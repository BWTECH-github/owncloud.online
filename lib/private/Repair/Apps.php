<?php
/**
 * @author Viktar Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
 * Modified by BW-Tech GmbH on 2026-02-26.
 * Changes:
 *   - doctrine/dbal:3 (#41450)
 */

namespace OC\Repair;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use OC\RepairException;
use OC_App;
use OCP\App\AppAlreadyInstalledException;
use OCP\App\AppManagerException;
use OCP\App\AppNotFoundException;
use OCP\App\AppNotInstalledException;
use OCP\App\AppUpdateNotFoundException;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\ILogger;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Util;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class Apps implements IRepairStep {
	public const KEY_COMPATIBLE = 'compatible';
	public const KEY_INCOMPATIBLE = 'incompatible';
	public const KEY_MISSING = 'missing';

	/** Der Markt hat geantwortet: Diese App führt er nicht. */
	private const MARKET_UNKNOWN = 'unknown';
	/** Der Markt führt die App, hat aber keine Fassung für diesen Server. */
	private const MARKET_NO_VERSION = 'no-version';

	/**
	 * Was der Markt in getAppsFromMarket() zu einer App gesagt hat, je App-ID:
	 * ['verdict' => self::MARKET_*, 'reason' => Meldung des Markts]. Fehlt eine
	 * App, wurde der Markt nicht gefragt oder der Versuch scheiterte aus einem
	 * anderen Grund – dann ist offen, ob er sie führt.
	 *
	 * @var array<string, array{verdict: string, reason: string}>
	 */
	private $marketVerdicts = [];

	/** @var  IAppManager */
	private $appManager;

	/** @var  EventDispatcherInterface */
	private $eventDispatcher;

	/** @var IConfig */
	private $config;

	/** @var \OC_Defaults */
	private $defaults;

	/** @var bool */
	private $forceMajorUpgrade;

	/**
	 * Apps constructor.
	 *
	 * @param IAppManager $appManager
	 * @param EventDispatcherInterface $eventDispatcher
	 * @param IConfig $config
	 * @param \OC_Defaults $defaults
	 */
	/** @var ILogger|null */
	private $logger;

	public function __construct(IAppManager $appManager, EventDispatcherInterface $eventDispatcher, IConfig $config, \OC_Defaults $defaults, $forceMajorUpgrade = false, ?ILogger $logger = null) {
		$this->appManager = $appManager;
		$this->eventDispatcher = $eventDispatcher;
		$this->config = $config;
		$this->defaults = $defaults;
		$this->forceMajorUpgrade = $forceMajorUpgrade;
		$this->logger = $logger;
	}

	/**
	 * App-Pfade als Array aus path/url - Huelle, damit Tests sie ersetzen koennen.
	 * @return string[][]
	 */
	protected function getAppRoots() {
		return \OC::$APPSROOTS;
	}

	protected function getLogger(): ILogger {
		return $this->logger ?? \OC::$server->getLogger();
	}

	/**
	 * @return string
	 */
	public function getName() {
		return 'Upgrade app code from the marketplace';
	}

	/**
	 * Are we updating from an older version?
	 * @return bool
	 */
	private function isCoreUpdate() {
		$installedVersion = $this->config->getSystemValue('version', '0.0.0');
		$currentVersion = \implode('.', $this->getSourcesVersion());
		$versionDiff = \version_compare($currentVersion, $installedVersion);
		if ($versionDiff > 0) {
			return true;
		}
		return false;
	}

	/**
	 * Is it a major core update
	 *
	 * @return bool
	 */
	private function isMajorCoreUpdate() {
		if ($this->forceMajorUpgrade === true) {
			return true;
		}

		$installedVersion = $this->config->getSystemValue('version', '0.0.0');
		$installedVersionArray = \explode('.', $installedVersion);
		$installedVersionMajor = (int) $installedVersionArray[0];
		$targetVersionArray = $this->getSourcesVersion();
		$targetVersionMajor = (int) $targetVersionArray[0];
		$majorUpgrade = $targetVersionMajor !== $installedVersionMajor;

		return $majorUpgrade;
	}

	/**
	 * If we are updating from <= 10.0.0 we need to enable the marketplace before running the update
	 * @return bool
	 */
	private function requiresMarketEnable() {
		$installedVersion = $this->config->getSystemValue('version', '0.0.0');
		$versionDiff = \version_compare('10.0.0', $installedVersion);
		if ($versionDiff < 0) {
			return false;
		}
		return true;
	}

	/**
	 * @param IOutput $output
	 * @throws RepairException
	 */
	public function run(IOutput $output) {
		$this->marketVerdicts = [];
		if ($this->config->getSystemValue('has_internet_connection', true) !== true) {
			$link = $this->defaults->buildDocLinkToKey('admin-marketplace-apps');
			$output->info('No internet connection available - no app updates will be taken from the marketplace.');
			$output->info("How to update apps in such situation please see $link");
			$this->appManager->disableApp('market');
		}
		$appsToUpgrade = $this->getAppsToUpgrade();
		$failedCompatibleApps = [];
		$failedMissingApps = $appsToUpgrade[self::KEY_MISSING];
		$failedIncompatibleApps = $appsToUpgrade[self::KEY_INCOMPATIBLE];
		$hasNotUpdatedCompatibleApps = 0;

		// fix market app state
		$shallContactMarketplace = $this->fixMarketAppState($output);

		// market might be enabled but admin does not want to automatically update apps through it
		// (they might want to manually click through the updates in the web UI so keeping the
		// market enabled here is a legitimate use case)
		if ($this->config->getSystemValue('upgrade.automatic-app-update', true) !== true) {
			$shallContactMarketplace = false;
		}

		if ($shallContactMarketplace) {
			// Check if we can use the marketplace to update apps as needed?
			if ($this->appManager->isEnabledForUser('market')) {
				// Use market to fix missing / old apps
				$this->loadApp('market');
				$output->info('Using market to update existing apps');
				try {
					// Try to update incompatible apps
					if (!empty($appsToUpgrade[self::KEY_INCOMPATIBLE])) {
						$output->info('Attempting to update the following existing but incompatible app from market: ' . \implode(', ', $appsToUpgrade[self::KEY_INCOMPATIBLE]));
						$failedIncompatibleApps = $this->getAppsFromMarket(
							$output,
							$appsToUpgrade[self::KEY_INCOMPATIBLE],
							'upgradeAppStoreApp'
						);
					}

					// Try to download missing apps
					if (!empty($appsToUpgrade[self::KEY_MISSING])) {
						$output->info('Attempting to update the following missing apps from market: ' . \implode(', ', $appsToUpgrade[self::KEY_MISSING]));
						$failedMissingApps = $this->getAppsFromMarket(
							$output,
							$appsToUpgrade[self::KEY_MISSING],
							'reinstallAppStoreApp'
						);
					}

					// Try to update compatible apps
					if (!empty($appsToUpgrade[self::KEY_COMPATIBLE])) {
						$output->info('Attempting to update the following existing compatible apps from market: ' . \implode(', ', $appsToUpgrade[self::KEY_COMPATIBLE]));
						$failedCompatibleApps = $this->getAppsFromMarket(
							$output,
							$appsToUpgrade[self::KEY_COMPATIBLE],
							'upgradeAppStoreApp'
						);
					}

					$hasNotUpdatedCompatibleApps = \count($failedCompatibleApps);
				} catch (AppManagerException $e) {
					$output->warning($e->getMessage());
				}
			} else {
				// No market available, output error and continue attempt
				$link = $this->defaults->buildDocLinkToKey('admin-marketplace-apps');
				$output->warning("Market app is unavailable for updating of apps. Please update manually, see $link");
			}
		}

		/*
		 * Apps ohne Code werden abgeschaltet, nicht zum Abbruchgrund gemacht.
		 *
		 * Eine App steht hier als "missing", wenn sie in der Datenbank
		 * eingeschaltet ist, auf diesem Server aber keinen Code hat - und der
		 * Markt sie auch nicht liefern konnte. Genau so sieht jede migrierte
		 * Instanz aus: die Datenbank kommt von einem alten oc10 mit
		 * Enterprise-Apps (systemtags_management, files_classifier ...) und
		 * Apps der alten Plattform (account), der Code kommt von der neuen.
		 * Der Kern brach dann mit "Upgrade is not possible" ab und verlangte
		 * "occ app:disable account" - fuer jede App einzeln, von Hand, auf
		 * einer Instanz, die bis dahin im Wartungsmodus steht.
		 *
		 * Eine App ohne Code kann aber ohnehin nichts tun; der Eintrag
		 * "enabled" ist das Einzige, was von ihr uebrig ist, und er blockiert
		 * nur. Er wird deshalb hier gesetzt, jede App wird genannt, und das
		 * Upgrade laeuft weiter. Wie die App zurückkommt, hängt davon ab, was
		 * der Markt zu ihr gesagt hat (describeMissingApp()): Nur wenn er sie
		 * führt, verweist der Hinweis auf ihn. Eigene Apps wie das Theme stehen
		 * in keinem Markt – dort hieße der Rat „aus dem Markt installieren“ eine
		 * Suche, die nichts findet.
		 *
		 * Apps MIT Code, die nur nicht zur Version passen ("incompatible"),
		 * bleiben ein Abbruchgrund: dort gibt es etwas zu reparieren, und ein
		 * stilles Abschalten wuerde es verdecken.
		 *
		 * "missing" heisst dabei nur: getAppInfo() liefert keine id. Das trifft
		 * auch Apps, deren Code bloss gerade nicht lesbar ist - nicht
		 * eingehaengter Pfad aus apps_paths, falsche Rechte, halb fertiger rsync,
		 * kaputte info.xml. Die duerfen nicht still und dauerhaft abgeschaltet
		 * werden. Abgeschaltet wird deshalb nur, wenn jeder App-Pfad lesbar ist
		 * UND die App in keinem davon einen Ordner hat. Jede Abschaltung landet
		 * mit dem alten "enabled"-Wert im Serverprotokoll, damit sie auch bei
		 * --no-warnings nachvollziehbar bleibt.
		 */
		$disabledMissingApps = [];
		if ($failedMissingApps !== []) {
			$unreadableRoots = [];
			foreach ($this->getAppRoots() as $root) {
				if (!\is_dir($root['path']) || !\is_readable($root['path'])) {
					$unreadableRoots[] = $root['path'];
				}
			}
			if ($unreadableRoots !== []) {
				$output->warning(
					'Apps without code are NOT disabled automatically because an app directory is missing or not readable: '
					. \implode(', ', $unreadableRoots)
					. '. Fix the path (mount, permissions, apps_paths) and run the upgrade again.'
				);
			} else {
				foreach ($failedMissingApps as $app) {
					$appDirs = [];
					foreach ($this->getAppRoots() as $root) {
						if (\is_dir($root['path'] . '/' . $app)) {
							$appDirs[] = $root['path'] . '/' . $app;
						}
					}
					if ($appDirs !== []) {
						// Code liegt da, ist aber nicht lesbar oder nicht parsebar: ein
						// Installationsfehler, den der Admin sehen muss.
						$output->warning(
							"App $app has a directory (" . \implode(', ', $appDirs)
							. ') but no readable appinfo/info.xml; it is not disabled automatically. Repair or remove the directory.'
						);
						continue;
					}
					$previous = $this->config->getAppValue($app, 'enabled', 'yes');
					try {
						$this->appManager->disableApp($app);
						$disabledMissingApps[] = $app;
						$this->getLogger()->warning(
							"Upgrade: disabled app $app - it is enabled in the database (enabled=$previous) but has no code in any app directory"
							. $this->describeMissingApp($app),
							['app' => 'core']
						);
					} catch (\Exception $e) {
						// isAlwaysEnabled - kann bei einer App ohne Code nicht
						// vorkommen, aber wenn doch, bleibt sie ein Abbruchgrund.
						$output->warning("Could not disable missing app $app: " . $e->getMessage());
					}
				}
			}
		}
		if ($disabledMissingApps !== []) {
			$output->warning(
				'The following apps were enabled but have no code on this server. '
				. 'They have been disabled so the upgrade can continue; their data stays in the database: '
				. \implode(', ', $disabledMissingApps)
			);
			foreach ($this->describeMissingAppGroups($disabledMissingApps) as $hint) {
				$output->warning($hint);
			}
			$failedMissingApps = \array_values(\array_diff($failedMissingApps, $disabledMissingApps));
		}

		$hasBlockingMissingApps = \count($failedMissingApps);
		$hasBlockingIncompatibleApps = $this->hasBlockingIncompatibleApps($failedIncompatibleApps);

		if ($hasBlockingIncompatibleApps || $hasBlockingMissingApps) {
			// fail
			$output->warning('You have incompatible or missing apps enabled that could not be found or updated via the marketplace.');
			$output->warning(
				'Please install or update the following apps manually or disable them with:'
				. $this->getOccDisableMessage(\array_merge($failedIncompatibleApps, $failedMissingApps))
			);
			$link = $this->defaults->buildDocLinkToKey('admin-marketplace-apps');
			$output->warning("For manually updating, see $link");

			throw new RepairException('Upgrade is not possible');
		} elseif ($hasNotUpdatedCompatibleApps) {
			foreach ($failedCompatibleApps as $app) {
				// TODO: Show reason
				$output->info("App was not updated: $app");
			}
		}
	}

	/**
	 * Upgrade appList from market
	 * Return an array of apps that were not upgraded successfully
	 *
	 * @param IOutput $output
	 * @param string[] $appList
	 * @param string $event
	 * @return array
	 * @throws AppManagerException
	 */
	protected function getAppsFromMarket(IOutput $output, $appList, $event) {
		$failedApps = [];
		foreach ($appList as $app) {
			$output->info("Fetching app from market: $app");
			try {
				$this->eventDispatcher->dispatch(
					new GenericEvent(
						$app,
						['isMajorUpdate' => $this->isMajorCoreUpdate()]
					),
					\sprintf('%s::%s', IRepairStep::class, $event)
				);
			} catch (AppAlreadyInstalledException $e) {
				$output->info($e->getMessage());
				$failedApps[] = $app;
			} catch (AppNotInstalledException $e) {
				$output->info($e->getMessage());
				$failedApps[] = $app;
			} catch (AppNotFoundException $e) {
				$output->info($e->getMessage());
				$failedApps[] = $app;
				$this->marketVerdicts[$app] = ['verdict' => self::MARKET_UNKNOWN, 'reason' => $e->getMessage()];
			} catch (AppUpdateNotFoundException $e) {
				$output->info($e->getMessage());
				$failedApps[] = $app;
				$this->marketVerdicts[$app] = ['verdict' => self::MARKET_NO_VERSION, 'reason' => $e->getMessage()];
			} catch (AppManagerException $e) {
				// No connection to market. Abort.
				throw $e;
			} catch (\Exception $e) {
				// TODO: check the reason
				$failedApps[] = $app;
				$output->warning(\get_class($e));

				$output->warning($e->getMessage());
			}
		}
		return $failedApps;
	}

	/**
	 * Get app list separated as compatible/incompatible/missing
	 *
	 * @return array
	 */
	protected function getAppsToUpgrade() {
		$installedApps = $this->appManager->getInstalledApps();
		$appsToUpgrade = [
			self::KEY_COMPATIBLE => [],
			self::KEY_INCOMPATIBLE => [],
			self::KEY_MISSING => []
		];

		foreach ($installedApps as $appId) {
			$info = $this->appManager->getAppInfo($appId);
			if (!isset($info['id']) || $info['id'] === null) {
				$appsToUpgrade[self::KEY_MISSING][] = $appId;
				continue;
			}
			$version = Util::getVersion();
			$key = (\OC_App::isAppCompatible($version, $info)) ? self::KEY_COMPATIBLE : self::KEY_INCOMPATIBLE;
			$appsToUpgrade[$key][] = $appId;
		}
		return $appsToUpgrade;
	}

	/**
	 * Rest der Protokollzeile zu einer abgeschalteten App ohne Code: was der
	 * Markt zu ihr gesagt hat und wie sie zurückkommt. „Install it from the
	 * marketplace“ steht nur da, wenn der Markt die App tatsächlich führt.
	 *
	 * Zurück kommt eine App ohne Markt über ihren Code: Code in ein App-
	 * Verzeichnis, app:enable, dann occ upgrade – installed_version steht noch
	 * auf der alten Fassung, bis dahin meldet der Server „Upgrade nötig“.
	 *
	 * @param string $app
	 * @return string
	 */
	private function describeMissingApp($app) {
		$verdict = $this->marketVerdicts[$app]['verdict'] ?? null;
		$reason = $this->marketVerdicts[$app]['reason'] ?? '';
		$reasonText = $reason !== '' ? " ($reason)" : '';
		$restore = "To use it again, put its code into an app directory, then run occ app:enable $app and occ upgrade.";

		if ($verdict === self::MARKET_UNKNOWN) {
			return ", and the marketplace does not offer it. Its data stays in the database. $restore";
		}
		if ($verdict === self::MARKET_NO_VERSION) {
			return ", and the marketplace has no version of it for this server$reasonText. Its data stays in the database."
				. ' Install it from the marketplace once a suitable version is available, then run occ upgrade.';
		}
		return "; the marketplace was not consulted or could not provide it. Its data stays in the database. $restore";
	}

	/**
	 * Hinweise für die Konsole und den Web-Updater, gruppiert nach der Antwort
	 * des Markts – gleiche Aussage wie describeMissingApp(), nur je Gruppe.
	 *
	 * @param string[] $apps abgeschaltete Apps ohne Code
	 * @return string[]
	 */
	private function describeMissingAppGroups(array $apps) {
		$groups = [self::MARKET_UNKNOWN => [], self::MARKET_NO_VERSION => [], 'open' => []];
		foreach ($apps as $app) {
			$verdict = $this->marketVerdicts[$app]['verdict'] ?? 'open';
			$groups[$verdict][] = $app;
		}

		$restore = 'To use one of them again, put its code into an app directory, then run occ app:enable <app> and occ upgrade.';
		$hints = [];
		if ($groups[self::MARKET_UNKNOWN] !== []) {
			$hints[] = 'Not offered by the marketplace: ' . \implode(', ', $groups[self::MARKET_UNKNOWN]) . ". $restore";
		}
		if ($groups[self::MARKET_NO_VERSION] !== []) {
			$hints[] = 'Offered by the marketplace, but without a version for this server: '
				. \implode(', ', $groups[self::MARKET_NO_VERSION])
				. '. Install them from the marketplace once a suitable version is available, then run occ upgrade.';
		}
		if ($groups['open'] !== []) {
			$hints[] = 'The marketplace was not consulted for, or could not provide: ' . \implode(', ', $groups['open']) . ". $restore";
		}
		return $hints;
	}

	protected function getOccDisableMessage($appList) {
		if (!\count($appList)) {
			return '';
		}
		$appList = \array_map(
			function ($appId) {
				return "occ app:disable $appId";
			},
			$appList
		);
		return "\n" . \implode("\n", $appList);
	}

	/**
	 * @param string[] $failedIncompatibleApps
	 *
	 * @return bool
	 */
	protected function hasBlockingIncompatibleApps($failedIncompatibleApps) {
		$skipBlockingAppsCheck = \in_array(Util::getChannel(), ['git', 'daily'], true);
		$hasBlockingIncompatibleApps = $skipBlockingAppsCheck === false && \count($failedIncompatibleApps);
		return $hasBlockingIncompatibleApps;
	}

	/**
	 * @codeCoverageIgnore
	 * @param string $app
	 */
	protected function loadApp($app) {
		OC_App::loadApp($app, false);
	}

	/**
	 * @return bool
	 */
	private function isAppStoreEnabled() {
		// if appstoreenabled was explicitly disabled we shall not use the market app for upgrade
		$appStoreEnabled = $this->config->getSystemValue('appstoreenabled', null);
		if ($appStoreEnabled === false) {
			return false;
		}
		return true;
	}

	private function fixMarketAppState(IOutput $output) {
		// no core update -> nothing to do
		if (!$this->isCoreUpdate()) {
			return false;
		}

		// no update from a version before 10.0 -> nothing to do, but allow apps to be updated
		if (!$this->requiresMarketEnable()) {
			return true;
		}
		// if the appstore was explicitly disabled -> disable market app as well
		if (!$this->isAppStoreEnabled()) {
			$this->appManager->disableApp('market');
			$link = $this->defaults->buildDocLinkToKey('admin-marketplace-apps');
			$output->info('Appstore was disabled in past versions and marketplace interactions are disabled for now as well.');
			$output->info('If you would like to get automated app updates on upgrade please enable the market app and remove "appstoreenabled" from your config.');
			$output->info("Please note that the market app is not recommended for clustered setups - see $link");
			return false;
		}

		// Then we need to enable the market app to support app updates / downloads during upgrade
		$output->info('Enabling market app to assist with update');
		try {
			// Prepare oc_jobs for older ownCloud version fixes https://github.com/owncloud/update-testing/issues/5
			$connection = \OC::$server->getDatabaseConnection();
			// IDBConnection does not have getPrefix, but Conection does
			'@phan-var \OC\DB\Connection $connection';
			$toSchema = $connection->createSchema();
			$this->changeSchema($toSchema, ['tablePrefix' => $connection->getPrefix()]);
			$connection->migrateToSchema($toSchema);

			$this->appManager->enableApp('market');
			return true;
		} catch (\Exception $ex) {
			$output->warning($ex->getMessage());
			return false;
		}
	}

	/**
	 * DB update for oc_jobs table
	 * it is intentionally duplicates 20170213215145 and a part of 20170101215145
	 * to allow seamless market app installation
	 *
	 * @param Schema $schema
	 * @param array $options
	 * @throws \Doctrine\DBAL\Schema\SchemaException
	 */
	private function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];
		if ($schema->hasTable("{$prefix}jobs")) {
			$jobsTable = $schema->getTable("{$prefix}jobs");

			if (!$jobsTable->hasColumn('last_checked')) {
				$jobsTable->addColumn(
					'last_checked',
					Types::INTEGER,
					[
						'default' => 0,
						'notnull' => false
					]
				);
			}

			if (!$jobsTable->hasColumn('reserved_at')) {
				$jobsTable->addColumn(
					'reserved_at',
					Types::INTEGER,
					[
						'default' => 0,
						'notnull' => false
					]
				);
			}

			if (!$jobsTable->hasColumn('execution_duration')) {
				$jobsTable->addColumn('execution_duration', Types::INTEGER, [
					'notnull' => true,
					'length' => 5,
					'default' => -1,
				]);
			}
		}
	}

	/**
	 * @return array
	 */
	protected function getSourcesVersion() {
		return Util::getVersion();
	}
}
