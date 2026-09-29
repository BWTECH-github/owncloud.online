<?php
/**
 * Copyright (c) 2014 Lukas Reschke <lukas@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test\Security;

use OC\Files\View;
use OC\Security\CertificateManager;
use OCP\IConfig;
use OCP\ILogger;
use OCP\Util;

/**
 * Class CertificateManagerTest
 *
 * @group DB
 */
class CertificateManagerTest extends \Test\TestCase {
	use \Test\Traits\UserTrait;
	use \Test\Traits\MountProviderTrait;

	private const SYSTEM_BUNDLE = '/files_external/rootcerts.crt';

	/** @var CertificateManager */
	private $certificateManager;
	/** @var String */
	private $username;

	protected function setUp(): void {
		parent::setUp();

		$this->username = self::getUniqueID('', 20);
		$this->createUser($this->username);

		$storage = new \OC\Files\Storage\Temporary();
		$this->registerMount($this->username, $storage, '/' . $this->username . '/');

		\OC_Util::tearDownFS();
		\OC_User::setUserId('');
		\OC\Files\Filesystem::tearDown();
		\OC_Util::setupFS($this->username);

		$config = $this->createMock('OCP\IConfig');
		$config->expects($this->any())->method('getSystemValue')
			->with('installed', false)->willReturn(true);

		$this->certificateManager = new CertificateManager($this->username, new \OC\Files\View(), $config);
	}

	protected function assertEqualsArrays($expected, $actual) {
		\sort($expected);
		\sort($actual);

		$this->assertEquals($expected, $actual);
	}

	public function testListCertificates() {
		// Test empty certificate bundle
		$this->assertSame([], $this->certificateManager->listCertificates());

		// Add some certificates
		$this->certificateManager->addCertificate(\file_get_contents(__DIR__ . '/../../data/certificates/goodCertificate.crt'), 'GoodCertificate');
		$certificateStore = [];
		$certificateStore[] = new \OC\Security\Certificate(\file_get_contents(__DIR__ . '/../../data/certificates/goodCertificate.crt'), 'GoodCertificate');
		$this->assertEqualsArrays($certificateStore, $this->certificateManager->listCertificates());

		// Add another certificates
		$this->certificateManager->addCertificate(\file_get_contents(__DIR__ . '/../../data/certificates/expiredCertificate.crt'), 'ExpiredCertificate');
		$certificateStore[] = new \OC\Security\Certificate(\file_get_contents(__DIR__ . '/../../data/certificates/expiredCertificate.crt'), 'ExpiredCertificate');
		$this->assertEqualsArrays($certificateStore, $this->certificateManager->listCertificates());
	}

	/**
	 */
	public function testAddInvalidCertificate() {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Certificate could not get parsed.');

		$this->certificateManager->addCertificate('InvalidCertificate', 'invalidCertificate');
	}

	/**
	 * @return array
	 */
	public function dangerousFileProvider() {
		return [
			['.htaccess'],
			['../../foo.txt'],
			['..\..\foo.txt'],
		];
	}

	/**
	 * @dataProvider dangerousFileProvider
	 * @param string $filename
	 */
	public function testAddDangerousFile($filename) {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Filename is not valid');

		$this->certificateManager->addCertificate(\file_get_contents(__DIR__ . '/../../data/certificates/expiredCertificate.crt'), $filename);
	}

	public function testRemoveDangerousFile() {
		$this->assertFalse($this->certificateManager->removeCertificate('../../foo.txt'));
	}

	public function testRemoveCertificate() {
		$this->certificateManager->addCertificate(\file_get_contents(__DIR__ . '/../../data/certificates/goodCertificate.crt'), 'GoodCertificate');
		$this->assertTrue($this->certificateManager->removeCertificate('GoodCertificate'));
	}

	public function testRemoveNonExistentCertificate() {
		$this->assertFalse($this->certificateManager->removeCertificate('NonExistentCertificate'));
	}

	public function testGetCertificateBundle() {
		$this->assertSame('/' . $this->username . '/files_external/rootcerts.crt', $this->certificateManager->getCertificateBundle());
	}

	/**
	 * View, in der nichts existiert außer den übergebenen Pfaden und in die nichts
	 * geschrieben werden kann (fopen liefert false, wie hinter einer Nur-Lese-Hülle)
	 *
	 * @param string[] $existing
	 * @return View|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function createUnwritableView(array $existing = []) {
		$view = $this->createMock(View::class);
		$view->method('file_exists')->willReturnCallback(function ($path) use ($existing) {
			return \in_array($path, $existing, true);
		});
		$view->method('filemtime')->willReturn(1);
		$view->method('fopen')->willReturn(false);
		return $view;
	}

	public function testCreateCertificateBundleThrowsWhenTheBundleCannotBeOpened() {
		$manager = new CertificateManager(null, $this->createUnwritableView(), $this->createMock(IConfig::class));

		$this->expectException(\RuntimeException::class);
		$manager->createCertificateBundle();
	}

	public function testCreateCertificateBundleRemovesTheTemporaryFileWhenItCannotBeMovedIntoPlace() {
		$view = $this->createMock(View::class);
		$view->method('file_exists')->willReturn(true);
		$view->method('fopen')->willReturn(\fopen('php://memory', 'w+'));
		$view->method('rename')->willReturn(false);
		$view->expects($this->once())->method('unlink')
			->with($this->matchesRegularExpression('#^/files_external/rootcerts\.crt\.[0-9a-f]+\.part$#'));
		$manager = new CertificateManager(null, $view, $this->createMock(IConfig::class));

		$this->expectException(\RuntimeException::class);
		$manager->createCertificateBundle();
	}

	public function testGetAbsoluteBundlePathFallsBackToTheShippedBundleWhenNoBundleCanBeWritten() {
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->once())->method('logException')
			->with(
				$this->isInstanceOf(\RuntimeException::class),
				$this->callback(function (array $context) {
					return $context['level'] === Util::WARN;
				})
			);
		$manager = new CertificateManager(null, $this->createUnwritableView(), $this->createMock(IConfig::class), $logger);

		$this->assertSame(
			\OC::$SERVERROOT . '/resources/config/ca-bundle.crt',
			$manager->getAbsoluteBundlePath(null)
		);
	}

	public function testGetAbsoluteBundlePathKeepsTheOutdatedBundleWhenItCannotBeRebuilt() {
		// vorhandenes, aber älteres Bündel als ca-bundle.crt (wie nach einem Update)
		$view = $this->createUnwritableView(['/files_external/', self::SYSTEM_BUNDLE]);
		$existingBundle = \OC::$SERVERROOT . '/tests/data/certificates/goodCertificate.crt';
		$view->method('getLocalFile')->with(self::SYSTEM_BUNDLE)->willReturn($existingBundle);
		$manager = new CertificateManager(null, $view, $this->createMock(IConfig::class), $this->createMock(ILogger::class));

		$this->assertSame($existingBundle, $manager->getAbsoluteBundlePath(null));
	}

	public function testGetAbsoluteBundlePathFallsBackToTheSystemBundleWhenTheUserBundleCannotBeWritten() {
		$view = $this->createUnwritableView([self::SYSTEM_BUNDLE]);
		$systemBundle = \OC::$SERVERROOT . '/tests/data/certificates/goodCertificate.crt';
		$view->method('getLocalFile')->with(self::SYSTEM_BUNDLE)->willReturn($systemBundle);
		$manager = new CertificateManager('alice', $view, $this->createMock(IConfig::class), $this->createMock(ILogger::class));

		$this->assertSame($systemBundle, $manager->getAbsoluteBundlePath());
	}

	public function testSystemBundleContainsTheDefaultCertificatesOnlyOnce() {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('installed', false)->willReturn(true);
		$view = new View();
		$manager = new CertificateManager(null, $view, $config);

		// zweimal: das Systembündel darf sich beim Neubau nicht selbst anhängen
		$manager->createCertificateBundle();
		$manager->createCertificateBundle();

		$defaultCertificates = \file_get_contents(\OC::$SERVERROOT . '/resources/config/ca-bundle.crt');
		$this->assertSame(1, \substr_count($view->file_get_contents(self::SYSTEM_BUNDLE), $defaultCertificates));
		$leftovers = \array_filter($view->getDirectoryContent('/files_external'), function ($info) {
			return \substr($info->getName(), -5) === '.part';
		});
		$this->assertSame([], \array_values($leftovers));
	}
}
