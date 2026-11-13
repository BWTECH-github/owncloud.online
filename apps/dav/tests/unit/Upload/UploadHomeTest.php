<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 */

namespace OCA\DAV\Tests\unit\Upload;

use OC\Files\Cache\CacheEntry;
use OC\Files\Cache\Updater;
use OC\Files\Cache\Watcher;
use OC\Files\Filesystem;
use OC\Files\Storage\Temporary;
use OCA\DAV\Upload\UploadHome;
use OCP\Files\Cache\ICache;
use Sabre\DAV\Exception\ServiceUnavailable;
use Test\TestCase;
use Test\Traits\UserTrait;

/**
 * @group DB
 */
class UploadHomeTest extends TestCase {
	use UserTrait;

	private $user;
	private $mountPath;
	private $storage;

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
	 * Control metadata visibility without replacing UploadHome's concrete Views.
	 *
	 * @return Temporary|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mountUploads($exists, $visibleAfterMkdir = true, $mkdirResult = true) {
		$available = $exists;
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
		$cache->method('get')->with('')->willReturnCallback(function () use (&$available, $entry) {
			return $available ? $entry : false;
		});
		$watcher = $this->createMock(Watcher::class);
		$watcher->method('needsUpdate')->willReturn(false);
		$this->storage = $this->getMockBuilder(Temporary::class)
			->setMethods(['getCache', 'getWatcher', 'getUpdater', 'getOwner', 'file_exists', 'mkdir'])
			->getMock();
		$this->storage->method('getCache')->willReturn($cache);
		$this->storage->method('getWatcher')->willReturn($watcher);
		$this->storage->method('getUpdater')->willReturn($this->createMock(Updater::class));
		$this->storage->method('getOwner')->willReturn($this->user->getUID());
		$this->storage->method('file_exists')->with('')->willReturnCallback(function () use (&$available) {
			return $available;
		});
		$this->storage->method('mkdir')->with('')->willReturnCallback(function () use (&$available, $visibleAfterMkdir, $mkdirResult) {
			$available = $visibleAfterMkdir;
			return $mkdirResult;
		});
		Filesystem::mount($this->storage, [], $this->mountPath);
		return $this->storage;
	}

	public function testCreatesUploadHomeOnFirstUse(): void {
		$storage = $this->mountUploads(false);
		$storage->expects($this->once())->method('mkdir')->with('');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testReusesExistingUploadHomeWithoutCreatingAgain(): void {
		$storage = $this->mountUploads(true);
		$storage->expects($this->never())->method('mkdir');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testReusesHomeCreatedConcurrentlyWhenMkdirReturnsFalse(): void {
		$storage = $this->mountUploads(false, true, false);
		$storage->expects($this->once())->method('mkdir')->with('');

		$home = new UploadHome(['user' => $this->user]);
		self::assertSame(123456789, $home->getLastModified());
	}

	public function testUnavailableMetadataReturnsRetryable503(): void {
		$storage = $this->mountUploads(false, false, false);
		$storage->expects($this->once())->method('mkdir')->with('');

		$home = new UploadHome(['user' => $this->user]);
		try {
			$home->getLastModified();
			self::fail('Missing upload metadata must not be passed to Directory');
		} catch (ServiceUnavailable $exception) {
			self::assertSame(503, $exception->getHTTPCode());
		}
	}
}
