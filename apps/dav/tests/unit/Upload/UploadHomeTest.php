<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
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

namespace OCA\DAV\Tests\unit\Upload;

use OC\Files\Cache\CacheEntry;
use OC\Files\Cache\Scanner;
use OC\Files\Cache\Updater;
use OC\Files\Cache\Watcher;
use OC\Files\Filesystem;
use OC\Files\Storage\Temporary;
use OCA\DAV\Upload\UploadHome;
use OCP\Files\Cache\ICache;
use OCP\Lock\LockedException;
use Sabre\DAV\Exception\ServiceUnavailable;
use Test\TestCase;
use Test\Traits\UserTrait;

/**
 * Erster Zugriff auf den Upload-Ordner eines Kontos, auch wenn eine parallele Anfrage ihn
 * gerade in den Datei-Cache einliest und die Scanner-Sperre hält.
 *
 * @group DB
 */
class UploadHomeTest extends TestCase {
	use UserTrait;

	/** @var \OCP\IUser */
	private $user;
	/** @var string */
	private $mountPath;
	/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject|null */
	private $storage;

	/** @var bool Ordner liegt auf der Platte */
	private $onDisk = false;
	/** @var bool Ordner steht im Datei-Cache */
	private $inCache = false;
	/** @var int so viele eigene Scans treffen noch auf die Sperre der parallelen Anfrage */
	private $lockedScans = 0;
	/** @var int eigene Scan-Versuche */
	private $scanAttempts = 0;
	/** @var int Leseversuche auf den Cache-Eintrag */
	private $cacheReads = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->user = $this->createUser('upload-home-test');
		\OC::$server->getUserFolder($this->user->getUID());
		$this->mountPath = '/' . $this->user->getUID() . '/uploads/';
	}

	protected function tearDown(): void {
		if ($this->storage !== null) {
			Filesystem::getMountManager()->removeMount($this->mountPath);
			$this->storage->cleanUp();
		}
		parent::tearDown();
	}

	/**
	 * Hängt einen Speicher als Upload-Ordner ein, dessen Zustand der Test steuert.
	 * UploadHome arbeitet dabei mit seinen echten Views.
	 *
	 * @param bool $onDisk Ordner liegt schon auf der Platte
	 * @param bool $inCache Ordner steht schon im Datei-Cache
	 * @param int $lockedScans so viele Scans scheitern an der Sperre einer parallelen Anfrage;
	 *            danach hat diese den Ordner eingelesen (PHP_INT_MAX = sie wird nie fertig)
	 * @param bool|\Exception $mkdirResult Ergebnis von mkdir() oder die Ausnahme, die es wirft
	 * @param bool $createdByOther mkdir() scheitert, weil eine parallele Anfrage den Ordner
	 *            in diesem Moment angelegt und eingelesen hat
	 * @return Temporary|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mountUploads($onDisk, $inCache, $lockedScans = 0, $mkdirResult = true, $createdByOther = false) {
		$this->onDisk = $onDisk;
		$this->inCache = $inCache;
		$this->lockedScans = $lockedScans;
		$this->scanAttempts = 0;
		$this->cacheReads = 0;

		$entry = new CacheEntry([
			'fileid' => 1,
			'path' => '',
			'name' => 'uploads',
			'mimetype' => 'httpd/unix-directory',
			'size' => 0,
			'mtime' => 123456789,
			'permissions' => \OCP\Constants::PERMISSION_ALL,
		]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function () use ($entry) {
			$this->cacheReads++;
			return $this->inCache ? $entry : false;
		});
		$watcher = $this->createMock(Watcher::class);
		$watcher->method('needsUpdate')->willReturn(false);
		// Der Weg aus der Produktion: Der eigene Scan scheitert an der Scanner-Sperre der
		// parallelen Anfrage, View::getCacheEntry() schluckt die LockedException.
		$scanner = $this->createMock(Scanner::class);
		$scanner->method('scan')->willReturnCallback(function () {
			$this->scanAttempts++;
			if ($this->scanAttempts <= $this->lockedScans) {
				if ($this->scanAttempts === $this->lockedScans) {
					// Die parallele Anfrage ist jetzt fertig, der nächste Leseversuch findet den Eintrag
					$this->inCache = true;
				}
				throw new LockedException('scanner::');
			}
			$this->inCache = true;
			return null;
		});

		$this->storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods(['getCache', 'getWatcher', 'getUpdater', 'getScanner', 'getOwner', 'file_exists', 'mkdir'])
			->getMock();
		$this->storage->method('getCache')->willReturn($cache);
		$this->storage->method('getWatcher')->willReturn($watcher);
		$this->storage->method('getUpdater')->willReturn($this->createMock(Updater::class));
		$this->storage->method('getScanner')->willReturn($scanner);
		$this->storage->method('getOwner')->willReturn($this->user->getUID());
		$this->storage->method('file_exists')->willReturnCallback(function () {
			return $this->onDisk;
		});
		$this->storage->method('mkdir')->willReturnCallback(function () use ($mkdirResult, $createdByOther) {
			if ($mkdirResult === true || $createdByOther) {
				$this->onDisk = true;
				$this->inCache = true;
			}
			if ($mkdirResult instanceof \Exception) {
				throw $mkdirResult;
			}
			return $mkdirResult;
		});
		Filesystem::mount($this->storage, [], $this->mountPath);
		return $this->storage;
	}

	public function testCreatesUploadHomeOnFirstUse(): void {
		$storage = $this->mountUploads(false, false);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testReusesExistingUploadHomeWithoutWaiting(): void {
		$storage = $this->mountUploads(true, true);
		$storage->expects($this->never())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
		self::assertSame(1, $this->cacheReads, 'Der Normalfall darf nicht warten oder mehrfach lesen');
		self::assertSame(0, $this->scanAttempts);
	}

	public function testUsesHomeCreatedConcurrentlyWhenMkdirReturnsFalse(): void {
		$storage = $this->mountUploads(false, false, 0, false, true);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testToleratesLockedMkdir(): void {
		$storage = $this->mountUploads(false, false, 0, new LockedException('uploads'), true);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testWaitsWhileConcurrentRequestHoldsTheScannerLock(): void {
		// Ordner liegt auf der Platte, steht aber noch nicht im Cache: Drei eigene Scans
		// treffen auf die Scanner-Sperre der parallelen Anfrage, dann ist diese fertig.
		$storage = $this->mountUploads(true, false, 3);
		$storage->expects($this->never())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
		self::assertSame(3, $this->scanAttempts);
	}

	public function testPermanentScannerLockIsReportedAsTemporaryStorageError(): void {
		$this->mountUploads(true, false, PHP_INT_MAX);

		$home = new UploadHome(['user' => $this->user]);
		try {
			$home->getLastModified();
			self::fail('Ein nicht lesbarer Upload-Ordner darf nicht an Directory gehen');
		} catch (ServiceUnavailable $exception) {
			self::assertSame(503, $exception->getHTTPCode());
			// Auf diesen Wortlaut prüfen die Desktop-Clients, siehe ObjectTree
			self::assertSame('Storage is temporarily not available', $exception->getMessage());
			self::assertSame(11, $this->scanAttempts, 'Ein Versuch und zehn Wiederholungen');
		}
	}

	public function testMissingHomeIsReportedWithoutWaiting(): void {
		// Anlegen scheitert (Rechte, Platte voll): Warten hilft nicht
		$storage = $this->mountUploads(false, false, 0, false);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		$start = \microtime(true);
		try {
			$home->getLastModified();
			self::fail('Ein fehlender Upload-Ordner darf nicht an Directory gehen');
		} catch (ServiceUnavailable $exception) {
			self::assertSame(503, $exception->getHTTPCode());
			self::assertSame(1, $this->cacheReads);
			self::assertLessThan(0.5, \microtime(true) - $start, 'Ohne Ordner auf der Platte wird nicht gewartet');
		}
	}
}
