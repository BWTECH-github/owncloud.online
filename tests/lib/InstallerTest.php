<?php
/**
 * Copyright (c) 2014 Georg Ehrke <georg@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test;

use OC\Installer;

class InstallerTest extends TestCase {
	private static $appid = 'testapp';

	protected function setUp(): void {
		parent::setUp();

		Installer::removeApp(self::$appid);
		\OC::$server->getConfig()->deleteAppValues(self::$appid);
	}

	protected function tearDown(): void {
		Installer::removeApp(self::$appid);
		\OC::$server->getConfig()->deleteAppValues(self::$appid);
		parent::tearDown();
	}

	public function testInstallApp() {
		$pathOfTestApp  = __DIR__;
		$pathOfTestApp .= '/../data/';
		$pathOfTestApp .= 'testapp.zip';

		$tmp = \OC::$server->getTempManager()->getTemporaryFile('.zip');
		\OC_Helper::copyr($pathOfTestApp, $tmp);

		$data = [
			'path' => $tmp,
			'source' => 'path',
			'appdata' => [
				'id' => 'Bar',
				'level' => 100,
			]
		];

		Installer::installApp($data);
		$isInstalled = Installer::isInstalled(self::$appid);

		$this->assertTrue($isInstalled);
	}

	public function testUpdateApp() {
		$pathOfOldTestApp  = __DIR__;
		$pathOfOldTestApp .= '/../data/';
		$pathOfOldTestApp .= 'testapp.zip';

		$oldTmp = \OC::$server->getTempManager()->getTemporaryFile('.zip');
		\OC_Helper::copyr($pathOfOldTestApp, $oldTmp);

		$oldData = [
			'path' => $oldTmp,
			'source' => 'path',
			'appdata' => [
				'id' => 'Bar',
				'level' => 100,
			]
		];

		$pathOfNewTestApp  = __DIR__;
		$pathOfNewTestApp .= '/../data/';
		$pathOfNewTestApp .= 'testapp2.zip';

		$newTmp = \OC::$server->getTempManager()->getTemporaryFile('.zip');
		\OC_Helper::copyr($pathOfNewTestApp, $newTmp);

		$newData = [
			'path' => $newTmp,
			'source' => 'path',
			'appdata' => [
				'id' => 'Bar',
				'level' => 100,
			]
		];

		Installer::installApp($oldData);
		$oldVersionNumber = \OC_App::getAppVersion(self::$appid);

		Installer::updateApp($newData);
		$newVersionNumber = \OC_App::getAppVersion(self::$appid);

		$this->assertNotEquals($oldVersionNumber, $newVersionNumber);
	}

	/**
	 * Tests that update is installed into writable app dir if the original app dir is not writable
	 */
	public function testUpdateIntoWritableAppDir() {
		$oldAppRoots = \OC::$APPSROOTS;
		$relativePath = "/anotherdir";

		// Install old version
		$pathOfOldTestApp = __DIR__ . '/../data/testapp.zip';
		$oldTmp = \OC::$server->getTempManager()->getTemporaryFile('.zip');
		\OC_Helper::copyr($pathOfOldTestApp, $oldTmp);
		$oldData = [
			'path' => $oldTmp,
			'source' => 'path',
			'appdata' => [
				'id' => 'testapp',
				'level' => 100,
			]
		];
		$installResult = Installer::installApp($oldData);
		$this->assertEquals('testapp', $installResult);
		$oldAppPath = \OC_App::getAppPath(self::$appid);

		// Mark the first app as dir non-writable and create the second as writable
		$firstAppDir = \array_shift(\OC::$APPSROOTS);
		$firstAppDir['writable'] = false;

		$path = \dirname($firstAppDir['path']) . $relativePath;
		\mkdir($path);
		\clearstatcache();
		\OC::$APPSROOTS = [
			$firstAppDir,
			[
			'path' => $path,
			'url'  => $relativePath,
			'writable' => true
			]
		];
		\OC::$server->getAppManager()->clearAppsCache();

		// Update app
		$pathOfNewTestApp  = __DIR__ . '/../data/testapp2.zip';
		$newTmp = \OC::$server->getTempManager()->getTemporaryFile('.zip');
		\OC_Helper::copyr($pathOfNewTestApp, $newTmp);

		$newData = [
			'path' => $newTmp,
			'source' => 'path',
			'appdata' => [
				'id' => 'testapp',
				'level' => 100,
			]
		];
		$updateResult = Installer::updateApp($newData);
		$this->assertTrue($updateResult);
		$newAppPath = \OC_App::getAppPath(self::$appid);

		$this->assertNotEquals($oldAppPath, $newAppPath);
		$this->assertStringStartsWith($path, $newAppPath);

		\OC_Helper::rmdirr($path);
		\OC::$APPSROOTS = $oldAppRoots;
		\OC::$server->getAppManager()->clearAppsCache();
	}

	/**
	 * Legt ein entpacktes Archiv an: je Eintrag ein Ordner (relativ zur Wurzel,
	 * '' für die Wurzel selbst) mit appinfo/info.xml und der angegebenen id.
	 *
	 * @param array $folders Ordner => id
	 * @return string Wurzel des entpackten Archivs
	 */
	private function createExtractedPackage(array $folders) {
		$root = \OC::$server->getTempManager()->getTemporaryFolder();
		foreach ($folders as $folder => $id) {
			$dir = $folder === '' ? $root : "$root/$folder";
			@\mkdir("$dir/appinfo", 0777, true);
			\file_put_contents(
				"$dir/appinfo/info.xml",
				"<?xml version=\"1.0\"?>\n<info><id>$id</id><name>Test app</name>"
				. "<licence>AGPL</licence><author></author><version>0.9</version></info>"
			);
		}
		return $root;
	}

	public function testCheckAppsIntegrityAcceptsSingleFolderNamedAfterId() {
		$root = $this->createExtractedPackage(['testapp' => 'testapp']);
		$info = Installer::checkAppsIntegrity(['source' => 'path'], $root, $root . '.zip');
		$this->assertSame('testapp', $info['id']);
		\OC_Helper::rmdirr($root);
	}

	public function providesInvalidPackages() {
		return [
			'info.xml in der Wurzel' => [['' => 'testapp']],
			'zwei App-Ordner' => [['testapp' => 'testapp', 'otherapp' => 'otherapp']],
			'Ordner heißt anders als die id' => [['otherapp' => 'testapp']],
			'id core' => [['core' => 'core']],
			'id mit Punktfolge' => [['co..re' => 'co..re']],
			'id mit Leerzeichen' => [['test app' => 'test app']],
			'id zu lang' => [[\str_repeat('a', 33) => \str_repeat('a', 33)]],
			'leere id' => [['testapp' => '']],
		];
	}

	/**
	 * @dataProvider providesInvalidPackages
	 */
	public function testCheckAppsIntegrityRejectsInvalidPackage(array $folders) {
		$root = $this->createExtractedPackage($folders);
		$rejected = false;
		try {
			Installer::checkAppsIntegrity(['source' => 'path'], $root, $root . '.zip');
		} catch (\Exception $e) {
			$rejected = true;
		}
		$this->assertTrue($rejected, 'Package was accepted');
		// Bei einer Ablehnung wird das ganze entpackte Archiv entfernt.
		$this->assertDirectoryDoesNotExist($root);
	}

	public function testCheckAppsIntegrityRemovesDownloadedPackageOnReject() {
		$root = $this->createExtractedPackage(['otherapp' => 'testapp']);
		$download = \OC::$server->getTempManager()->getTemporaryFile('.tar.gz');
		$rejected = false;
		try {
			Installer::checkAppsIntegrity(['source' => 'http'], $root, $download);
		} catch (\Exception $e) {
			$rejected = true;
		}
		$this->assertTrue($rejected, 'Package was accepted');
		$this->assertDirectoryDoesNotExist($root);
		// Ein heruntergeladenes Paket wird bei einer Ablehnung ebenfalls gelöscht.
		$this->assertFileDoesNotExist($download);
	}

	public function testCheckAppsIntegrityKeepsLocalPackageOnReject() {
		$root = $this->createExtractedPackage(['otherapp' => 'testapp']);
		$local = \OC::$server->getTempManager()->getTemporaryFile('.tar.gz');
		try {
			Installer::checkAppsIntegrity(['source' => 'path'], $root, $local);
		} catch (\Exception $e) {
		}
		// Ein lokal angegebenes Paket gehört dem Aufrufer und bleibt liegen.
		$this->assertFileExists($local);
		\unlink($local);
	}
}
