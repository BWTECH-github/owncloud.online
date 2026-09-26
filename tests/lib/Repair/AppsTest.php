<?php
/**
 * @author Tom Needham <tom@owncloud.com>
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
 */
namespace Test\Repair;

use OC\Repair\Apps;
use OCP\App\AppManagerException;
use OCP\App\AppNotFoundException;
use OCP\App\AppUpdateNotFoundException;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\ILogger;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

/**
 * Tests to check version comparison
 *
 * @see \OC\Repair\AppsTest
 */
class AppsTest extends TestCase {
	/** @var Apps | \PHPUnit\Framework\MockObject\MockObject */
	protected $repair;
	/** @var IAppManager | \PHPUnit\Framework\MockObject\MockObject */
	protected $appManager;
	/** @var  EventDispatcherInterface | \PHPUnit\Framework\MockObject\MockObject */
	protected $eventDispatcher;
	/** @var  IConfig | \PHPUnit\Framework\MockObject\MockObject*/
	protected $config;
	/** @var \OC_Defaults | \PHPUnit\Framework\MockObject\MockObject */
	private $defaults;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->defaults = $this->createMock(\OC_Defaults::class);
		$this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->eventDispatcher->expects($this->any())->method('dispatch')
			->will(
				$this->returnCallback(function ($object) {
					return $object;
				})
			);
		$this->config = $this->createMock(IConfig::class);
		$this->repair = new Apps(
			$this->appManager,
			$this->eventDispatcher,
			$this->config,
			$this->defaults
		);
	}

	public function testMarketEnableVersionCompare10() {
		$this->config->expects($this->once())->method('getSystemValue')->with('version', '0.0.0')->willReturn('10.0.0');
		$this->assertTrue(self::invokePrivate($this->repair, 'requiresMarketEnable'));
	}

	public function testMarketEnableVersionCompare9() {
		$this->config->expects($this->once())->method('getSystemValue')->with('version', '0.0.0')->willReturn('9.1.5');
		$this->assertTrue(self::invokePrivate($this->repair, 'requiresMarketEnable'));
	}

	public function testMarketEnableVersionCompareFuture() {
		$this->config->expects($this->once())->method('getSystemValue')->with('version', '0.0.0')->willReturn('10.0.2');
		$this->assertFalse(self::invokePrivate($this->repair, 'requiresMarketEnable'));
	}

	public function testMarketEnableVersionCompareCurrent() {
		$this->config->expects($this->once())->method('getSystemValue')->with('version', '0.0.0')->willReturn('10.0.1');
		$this->assertFalse(self::invokePrivate($this->repair, 'requiresMarketEnable'));
	}

	public function dataTestHasBlockingIncompatibleApps() {
		return [
			['git', [], false],
			['daily', [], false],
			['stable', [], false],
			['git', ['someapp'], false],
			['daily', ['someapp'], false],
			['stable', ['someapp'], true],
		];
	}

	/**
	 * @dataProvider dataTestHasBlockingIncompatibleApps
	 * @param string $channel
	 * @param string[] $blockingApps
	 * @param bool $expectedResult
	 */
	public function testHasBlockingIncompatibleApps($channel, $blockingApps, $expectedResult) {
		$oldChannel = \OCP\Util::getChannel();
		\OCP\Util::setChannel($channel);
		$this->assertEquals(
			$expectedResult,
			self::invokePrivate($this->repair, 'hasBlockingIncompatibleApps', [$blockingApps])
		);
		\OCP\Util::setChannel($oldChannel);
	}

	public function dataTestUpdateEvent() {
		return [
			['10.1.0.0', [10, 1, 3, 7], false, false], // same major version
			['10.1.0.0', [10, 1, 3, 7], true, true],   // same major version, but major forced
			['10.1.0.0', [11, 0, 2, 0], false, true],  // different major version
			['10.1.0.0', [10, 0, 2, 0], true, true],  // different major version, major forced
		];
	}

	/**
	 * @dataProvider dataTestUpdateEvent
	 * @param string $installedVersion
	 * @param int[] $sourcesVersion
	 * @param bool $forceMajorUpdate
	 * @param bool $expectedIsMajorUpdate
	 */
	public function testUpdateAppEvent($installedVersion, $sourcesVersion, $forceMajorUpdate, $expectedIsMajorUpdate) {
		$appName = 'fakeapp';

		$this->config->method('getSystemValue')
			->with('version', '0.0.0')
			->willReturn($installedVersion);
		$this->configureRepair(['getSourcesVersion'], $forceMajorUpdate);

		$this->repair->method('getSourcesVersion')
			->willReturn($sourcesVersion);

		$this->eventDispatcher->expects($this->once())->method('dispatch')
			->willReturnCallback(
				function ($event, $eventName) use ($appName) {
					$this->assertEquals($appName, $event->getSubject());
					$this->assertEquals(true, $event->getArgument('isMajorUpdate'));
				}
			);
		self::invokePrivate(
			$this->repair,
			'getAppsFromMarket',
			[
				new \OC\Migration\ConsoleOutput(new NullOutput()),
				[ $appName ],
				'john'
			]
		);
	}

	/**
	 * Reparaturschritt mit ersetzbaren App-Pfaden und eigenem Logger, damit
	 * die Tests weder vom Dateisystem der Testinstanz noch vom Serverlog
	 * abhaengen.
	 */
	private function repairMitAppRoots(array $roots, ?ILogger $logger = null) {
		$repair = $this->getMockBuilder(Apps::class)
			->setConstructorArgs([$this->appManager, $this->eventDispatcher, $this->config, $this->defaults, false, $logger])
			->setMethods(['getAppRoots', 'loadApp'])
			->getMock();
		$repair->method('getAppRoots')->willReturn($roots);
		return $repair;
	}

	/**
	 * Konfiguration, bei der der Reparaturschritt den Markt fragt: Update von
	 * 10.16 auf 11, automatische App-Updates an, Markt-App eingeschaltet.
	 */
	private function konfigMitMarkt() {
		$this->config->method('getSystemValue')
			->willReturnCallback(function ($key, $default = null) {
				$werte = [
					'has_internet_connection' => true,
					'version' => '10.16.2.0',
					'upgrade.automatic-app-update' => true,
					'appstoreenabled' => null,
				];
				return \array_key_exists($key, $werte) ? $werte[$key] : $default;
			});
		$this->config->method('getAppValue')->willReturn('yes');
		$this->appManager->method('isEnabledForUser')->willReturnCallback(function ($app) {
			return $app === 'market';
		});
	}

	/**
	 * Markt-Attrappe: je App-ID eine Ausnahme, die der Markt beim
	 * Nachinstallieren wirft (wie OCA\Market\Listener). Apps ohne Eintrag
	 * gehen still durch.
	 *
	 * @param \Exception[] $antworten
	 */
	private function marktAntwortet(array $antworten) {
		$this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->eventDispatcher->method('dispatch')
			->willReturnCallback(function ($event) use ($antworten) {
				$app = $event->getSubject();
				if (isset($antworten[$app])) {
					throw $antworten[$app];
				}
				return $event;
			});
	}

	/**
	 * Installierte Apps: "files" mit passendem Code, alle übrigen ohne Code.
	 */
	private function appsOhneCode(array $ohneCode) {
		$this->appManager->method('getInstalledApps')
			->willReturn(\array_merge(['files'], $ohneCode));
		$this->appManager->method('getAppInfo')
			->willReturnCallback(function ($appId) {
				if ($appId === 'files') {
					return [
						'id' => 'files',
						'dependencies' => ['owncloud' => ['@attributes' => ['min-version' => '10', 'max-version' => '99']]],
					];
				}
				return [];
			});
	}

	/**
	 * Führt den Reparaturschritt mit leerem App-Pfad aus und liefert
	 * [abgeschaltete Apps, Serverprotokoll je App, Warnungen der Ausgabe].
	 */
	private function laufMitProtokoll() {
		$abgeschaltet = [];
		$this->appManager->method('disableApp')
			->willReturnCallback(function ($appId) use (&$abgeschaltet) {
				$abgeschaltet[] = $appId;
			});

		$protokoll = [];
		$logger = $this->createMock(ILogger::class);
		$logger->method('warning')->willReturnCallback(function ($text) use (&$protokoll) {
			if (\preg_match('/^Upgrade: disabled app (\S+)/', $text, $treffer)) {
				$protokoll[$treffer[1]] = $text;
			}
		});

		$meldungen = [];
		$output = $this->createMock(\OCP\Migration\IOutput::class);
		$output->method('warning')->willReturnCallback(function ($text) use (&$meldungen) {
			$meldungen[] = $text;
		});

		$dir = $this->leeresAppVerzeichnis();
		try {
			$this->repairMitAppRoots([['path' => $dir, 'url' => '/apps']], $logger)->run($output);
		} finally {
			\rmdir($dir);
		}
		return [$abgeschaltet, $protokoll, $meldungen];
	}

	private function meldungMit(array $meldungen, string $teil) {
		foreach ($meldungen as $m) {
			if (\strpos($m, $teil) !== false) {
				return $m;
			}
		}
		$this->fail("Keine Warnung enthält \"$teil\":\n" . \implode("\n", $meldungen));
	}

	/**
	 * Kennt der Markt eine App nicht (eigene Apps wie das Theme, Enterprise-
	 * Apps wie admin_audit), darf weder das Serverprotokoll noch die Ausgabe
	 * auf den Markt verweisen - dort ist nichts zu finden. Stattdessen steht
	 * da, wie die App tatsächlich zurückkommt.
	 */
	public function testAppUnknownToMarketGetsNoMarketplaceHint() {
		$this->konfigMitMarkt();
		$this->appsOhneCode(['theme-owncloudonline']);
		$this->marktAntwortet([
			'theme-owncloudonline' => new AppNotFoundException('Unknown app (theme-owncloudonline)'),
		]);

		[$abgeschaltet, $protokoll, $meldungen] = $this->laufMitProtokoll();

		$this->assertEquals(['theme-owncloudonline'], $abgeschaltet);
		$zeile = $protokoll['theme-owncloudonline'];
		$this->assertStringContainsString('enabled=yes', $zeile);
		$this->assertStringContainsString('the marketplace does not offer it', $zeile);
		$this->assertStringContainsString('Its data stays in the database', $zeile);
		$this->assertStringContainsString('occ app:enable theme-owncloudonline and occ upgrade', $zeile);
		$this->assertStringNotContainsStringIgnoringCase('install it from the marketplace', $zeile);

		$this->meldungMit($meldungen, 'have no code on this server');
		$unbekannt = $this->meldungMit($meldungen, 'Not offered by the marketplace: theme-owncloudonline');
		$this->assertStringContainsString('occ app:enable <app> and occ upgrade', $unbekannt);
		foreach ($meldungen as $m) {
			$this->assertStringNotContainsStringIgnoringCase('install them from the marketplace', $m);
			$this->assertStringNotContainsStringIgnoringCase('install it from the marketplace', $m);
		}
	}

	/**
	 * Kennt der Markt die App, hat aber keine passende Fassung, bleibt der
	 * Verweis auf den Markt richtig - samt Grund aus seiner Antwort.
	 */
	public function testAppKnownToMarketKeepsMarketplaceHint() {
		$this->konfigMitMarkt();
		$this->appsOhneCode(['oldapp']);
		$this->marktAntwortet([
			'oldapp' => new AppUpdateNotFoundException('No compatible version for oldapp'),
		]);

		[$abgeschaltet, $protokoll, $meldungen] = $this->laufMitProtokoll();

		$this->assertEquals(['oldapp'], $abgeschaltet);
		$zeile = $protokoll['oldapp'];
		$this->assertStringContainsString('the marketplace has no version of it for this server (No compatible version for oldapp)', $zeile);
		$this->assertStringContainsString('Install it from the marketplace', $zeile);

		$this->assertStringContainsString(
			'Install them from the marketplace',
			$this->meldungMit($meldungen, 'Offered by the marketplace, but without a version for this server: oldapp')
		);
	}

	/**
	 * Gemischter Lauf: jede App landet in genau der Gruppe, die der Antwort
	 * des Markts entspricht.
	 */
	public function testMissingAppsAreGroupedByMarketAnswer() {
		$this->konfigMitMarkt();
		$this->appsOhneCode(['admin_audit', 'oldapp', 'oco_selfservice']);
		$this->marktAntwortet([
			'admin_audit' => new AppNotFoundException('Unknown app (admin_audit)'),
			'oldapp' => new AppUpdateNotFoundException('No compatible version for oldapp'),
			'oco_selfservice' => new AppNotFoundException('Unknown app (oco_selfservice)'),
		]);

		[$abgeschaltet, $protokoll, $meldungen] = $this->laufMitProtokoll();

		$this->assertEquals(['admin_audit', 'oldapp', 'oco_selfservice'], $abgeschaltet);
		$this->assertStringContainsString(
			'admin_audit, oldapp, oco_selfservice',
			$this->meldungMit($meldungen, 'have no code on this server')
		);
		$this->meldungMit($meldungen, 'Not offered by the marketplace: admin_audit, oco_selfservice.');
		$this->meldungMit($meldungen, 'Offered by the marketplace, but without a version for this server: oldapp.');
		$this->assertStringNotContainsStringIgnoringCase('install it from the marketplace', $protokoll['admin_audit']);
		$this->assertStringContainsString('Install it from the marketplace', $protokoll['oldapp']);
	}

	/**
	 * Bricht die Verbindung zum Markt ab, weiß der Kern nicht, ob der Markt
	 * die App führt - dann auch kein Verweis auf ihn, sondern ehrlich "nicht
	 * geklärt".
	 */
	public function testMarketFailureGivesNeutralHint() {
		$this->konfigMitMarkt();
		$this->appsOhneCode(['someapp']);
		$this->marktAntwortet([
			'someapp' => new AppManagerException('No internet connection'),
		]);

		[$abgeschaltet, $protokoll, $meldungen] = $this->laufMitProtokoll();

		$this->assertEquals(['someapp'], $abgeschaltet);
		$this->assertStringContainsString('the marketplace was not consulted or could not provide it', $protokoll['someapp']);
		$this->assertStringContainsString('occ app:enable someapp and occ upgrade', $protokoll['someapp']);
		$this->assertStringNotContainsStringIgnoringCase('install it from the marketplace', $protokoll['someapp']);
		$this->meldungMit($meldungen, 'The marketplace was not consulted for, or could not provide: someapp.');
	}

	private function konfigOhneMarkt() {
		$this->config->method('getSystemValue')
			->willReturnCallback(function ($key, $default = null) {
				$werte = [
					'has_internet_connection' => true,
					'version' => '10.16.2.0',
					// Kein Marktbesuch: die Apps sollen als "nicht beschaffbar" ankommen.
					'upgrade.automatic-app-update' => false,
					'appstoreenabled' => null,
				];
				return \array_key_exists($key, $werte) ? $werte[$key] : $default;
			});
		$this->config->method('getAppValue')->willReturn('yes');
	}

	private function leeresAppVerzeichnis() {
		$dir = \sys_get_temp_dir() . '/repair-apps-' . \uniqid();
		\mkdir($dir);
		return $dir;
	}

	/**
	 * Eine eingeschaltete App ohne Code darf das Upgrade nicht mehr
	 * blockieren: sie wird abgeschaltet, genannt und protokolliert, der Lauf
	 * geht weiter.
	 *
	 * Das ist der Fall jeder migrierten Instanz - Datenbank vom alten oc10
	 * mit Enterprise-Apps und Apps der alten Plattform, Code von der neuen.
	 */
	public function testMissingAppsAreDisabledInsteadOfBlocking() {
		$this->konfigOhneMarkt();
		$this->appManager->method('getInstalledApps')
			->willReturn(['account', 'systemtags_management', 'files']);
		$this->appManager->method('getAppInfo')
			->willReturnCallback(function ($appId) {
				if ($appId === 'files') {
					return [
						'id' => 'files',
						'dependencies' => ['owncloud' => ['@attributes' => ['min-version' => '10', 'max-version' => '99']]],
					];
				}
				// kein Code -> keine info.xml -> keine id
				return [];
			});

		$abgeschaltet = [];
		$this->appManager->expects($this->exactly(2))
			->method('disableApp')
			->willReturnCallback(function ($appId) use (&$abgeschaltet) {
				$abgeschaltet[] = $appId;
			});

		$protokoll = [];
		$logger = $this->createMock(ILogger::class);
		$logger->method('warning')->willReturnCallback(function ($text) use (&$protokoll) {
			$protokoll[] = $text;
		});

		$output = $this->createMock(\OCP\Migration\IOutput::class);
		$meldungen = [];
		$output->method('warning')->willReturnCallback(function ($text) use (&$meldungen) {
			$meldungen[] = $text;
		});

		$dir = $this->leeresAppVerzeichnis();
		try {
			// Kein RepairException mehr.
			$this->repairMitAppRoots([['path' => $dir, 'url' => '/apps']], $logger)->run($output);
		} finally {
			\rmdir($dir);
		}

		$this->assertEquals(['account', 'systemtags_management'], $abgeschaltet);
		$this->assertNotEmpty(
			\array_filter($meldungen, function ($m) {
				return \strpos($m, 'have no code on this server') !== false
					&& \strpos($m, 'account, systemtags_management') !== false;
			}),
			'Die abgeschalteten Apps muessen in der Warnung genannt werden'
		);
		$this->assertCount(2, $protokoll, 'Jede Abschaltung steht im Serverprotokoll');
		$this->assertStringContainsString('disabled app account', $protokoll[0]);
		$this->assertStringContainsString('enabled=yes', $protokoll[0]);
		// Der Markt wurde nicht gefragt - also auch kein Verweis auf ihn.
		$this->assertStringContainsString('the marketplace was not consulted or could not provide it', $protokoll[0]);
		$this->assertStringContainsString('occ app:enable account and occ upgrade', $protokoll[0]);
		$this->assertStringNotContainsStringIgnoringCase('install it from the marketplace', $protokoll[0]);
		$this->assertNotEmpty(
			\array_filter($meldungen, function ($m) {
				return \strpos($m, 'The marketplace was not consulted for, or could not provide: account, systemtags_management.') !== false;
			}),
			'Die Apps ohne Antwort des Markts müssen als solche genannt werden'
		);
	}

	/**
	 * Liegt ein Ordner der App da, ist der Code nur nicht lesbar oder nicht
	 * parsebar - das ist ein Installationsfehler und bleibt Abbruchgrund.
	 */
	public function testMissingAppWithDirectoryIsNotDisabled() {
		$this->konfigOhneMarkt();
		$this->appManager->method('getInstalledApps')->willReturn(['brokenapp']);
		$this->appManager->method('getAppInfo')->willReturn([]);
		$this->appManager->expects($this->never())->method('disableApp');

		$output = $this->createMock(\OCP\Migration\IOutput::class);
		$meldungen = [];
		$output->method('warning')->willReturnCallback(function ($text) use (&$meldungen) {
			$meldungen[] = $text;
		});

		$dir = $this->leeresAppVerzeichnis();
		\mkdir($dir . '/brokenapp');
		try {
			$this->expectException(\OC\RepairException::class);
			$this->repairMitAppRoots([['path' => $dir, 'url' => '/apps']])->run($output);
		} finally {
			\rmdir($dir . '/brokenapp');
			\rmdir($dir);
			$this->assertNotEmpty(\array_filter($meldungen, function ($m) {
				return \strpos($m, 'has a directory') !== false && \strpos($m, 'brokenapp') !== false;
			}), 'Der vorhandene Ordner muss genannt werden');
		}
	}

	/**
	 * Ist ein App-Pfad nicht lesbar (Mount fehlt, Rechte), waere jede App
	 * dort "missing" - dann wird nichts abgeschaltet und der Lauf bricht wie
	 * frueher ab.
	 */
	public function testUnreadableAppRootPreventsAutoDisable() {
		$this->konfigOhneMarkt();
		$this->appManager->method('getInstalledApps')->willReturn(['account']);
		$this->appManager->method('getAppInfo')->willReturn([]);
		$this->appManager->expects($this->never())->method('disableApp');

		$output = $this->createMock(\OCP\Migration\IOutput::class);
		$meldungen = [];
		$output->method('warning')->willReturnCallback(function ($text) use (&$meldungen) {
			$meldungen[] = $text;
		});

		$fehlt = \sys_get_temp_dir() . '/repair-apps-gibt-es-nicht-' . \uniqid();
		try {
			$this->expectException(\OC\RepairException::class);
			$this->repairMitAppRoots([['path' => $fehlt, 'url' => '/apps']])->run($output);
		} finally {
			$this->assertNotEmpty(\array_filter($meldungen, function ($m) use ($fehlt) {
				return \strpos($m, 'NOT disabled automatically') !== false && \strpos($m, $fehlt) !== false;
			}), 'Der unlesbare Pfad muss genannt werden');
		}
	}

	/**
	 * Eine App MIT Code, die nicht zur Version passt, bleibt ein Abbruchgrund.
	 */
	public function testIncompatibleAppsStillBlock() {
		$oldChannel = \OCP\Util::getChannel();
		\OCP\Util::setChannel('stable');

		$this->konfigOhneMarkt();
		$this->appManager->method('getInstalledApps')->willReturn(['oldapp']);
		$this->appManager->method('getAppInfo')->willReturn([
			'id' => 'oldapp',
			'dependencies' => ['owncloud' => ['@attributes' => ['min-version' => '9', 'max-version' => '9']]],
		]);
		$this->appManager->expects($this->never())->method('disableApp');

		try {
			$this->expectException(\OC\RepairException::class);
			$this->repair->run($this->createMock(\OCP\Migration\IOutput::class));
		} finally {
			\OCP\Util::setChannel($oldChannel);
		}
	}

	private function configureRepair($mockedMethods, $forceMajorUpgrade = false) {
		$this->repair = $this->getMockBuilder(Apps::class)
			->setConstructorArgs(
				[
					$this->appManager,
					$this->eventDispatcher,
					$this->config,
					$this->defaults,
					$forceMajorUpgrade
				]
			)
			->setMethods($mockedMethods)
			->getMock();
	}
}
