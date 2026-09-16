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
			->setMethods(['getAppRoots'])
			->getMock();
		$repair->method('getAppRoots')->willReturn($roots);
		return $repair;
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
