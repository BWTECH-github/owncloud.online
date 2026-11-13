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
 * Erster Zugriff auf den Upload-Ordner eines Kontos, auch wenn eine parallele
 * Anfrage ihn gerade anlegt.
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
	/** @var int Anzahl der Leseversuche auf den Cache-Eintrag des Ordners */
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
	 * Hängt einen Speicher als Upload-Ordner ein, dessen Sichtbarkeit der Test steuert.
	 * UploadHome arbeitet dabei mit seinen echten Views.
	 *
	 * @param bool $existsOnDisk Ordner ist für file_exists() schon da
	 * @param int $readableFromRead ab dem wievielten Leseversuch der Cache-Eintrag da ist
	 *            (1 = sofort, PHP_INT_MAX = nie)
	 * @param bool|\Exception $mkdirResult Ergebnis von mkdir() oder die Ausnahme, die es wirft
	 * @return Temporary|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mountUploads($existsOnDisk, $readableFromRead = 1, $mkdirResult = true) {
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
		$cache->method('get')->willReturnCallback(function () use ($entry, $readableFromRead) {
			$this->cacheReads++;
			return $this->cacheReads >= $readableFromRead ? $entry : false;
		});
		$watcher = $this->createMock(Watcher::class);
		$watcher->method('needsUpdate')->willReturn(false);

		$this->storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods(['getCache', 'getWatcher', 'getUpdater', 'getOwner', 'file_exists', 'mkdir'])
			->getMock();
		$this->storage->method('getCache')->willReturn($cache);
		$this->storage->method('getWatcher')->willReturn($watcher);
		$this->storage->method('getUpdater')->willReturn($this->createMock(Updater::class));
		$this->storage->method('getOwner')->willReturn($this->user->getUID());
		// Ohne Cache-Eintrag und ohne Ordner auf der Platte liefert View::getFileInfo() false,
		// genau wie hinter der Sperre einer parallelen Anfrage.
		$this->storage->method('file_exists')->willReturn($existsOnDisk);
		$this->storage->method('mkdir')->willReturnCallback(function () use ($mkdirResult) {
			if ($mkdirResult instanceof \Exception) {
				throw $mkdirResult;
			}
			return $mkdirResult;
		});
		Filesystem::mount($this->storage, [], $this->mountPath);
		return $this->storage;
	}

	public function testCreatesUploadHomeOnFirstUse(): void {
		$storage = $this->mountUploads(false);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testReusesExistingUploadHomeWithoutCreatingAgain(): void {
		$storage = $this->mountUploads(true);
		$storage->expects($this->never())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
		self::assertSame(1, $this->cacheReads, 'Der Normalfall darf nicht warten oder mehrfach lesen');
	}

	public function testUsesHomeCreatedConcurrentlyWhenMkdirReturnsFalse(): void {
		$storage = $this->mountUploads(false, 1, false);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testUsesHomeCreatedConcurrentlyWhenMkdirIsLocked(): void {
		$storage = $this->mountUploads(false, 1, new LockedException('uploads'));
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testWaitsUntilConcurrentCreationIsReadable(): void {
		// Die ersten drei Leseversuche treffen noch auf die Sperre der anlegenden Anfrage
		$storage = $this->mountUploads(false, 4, false);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
		self::assertSame(4, $this->cacheReads);
	}

	public function testUnreadableHomeIsReportedAsTemporaryStorageError(): void {
		$storage = $this->mountUploads(false, PHP_INT_MAX, false);
		$storage->expects($this->once())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		try {
			$home->getLastModified();
			self::fail('Ein nicht lesbarer Upload-Ordner darf nicht an Directory gehen');
		} catch (ServiceUnavailable $exception) {
			self::assertSame(503, $exception->getHTTPCode());
			// Auf diesen Wortlaut prüfen die Desktop-Clients, siehe ObjectTree
			self::assertSame('Storage is temporarily not available', $exception->getMessage());
		}
	}
}
