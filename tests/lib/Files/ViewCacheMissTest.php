<?php

namespace Test\Files;

use OC\Files\Cache\Cache;
use OC\Files\Cache\CacheEntry;
use OC\Files\Cache\Scanner;
use OC\Files\Cache\Watcher;
use OC\Files\Storage\Local;
use OC\Files\View;
use OCP\Files\Cache\IScanner;
use OCP\Files\Cache\IPropagator;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class ViewCacheMissTest extends TestCase {
	private $directory;

	protected function setUp(): void {
		$this->directory = \sys_get_temp_dir() . '/oc-view-cache-miss-' . \bin2hex(\random_bytes(8));
		\mkdir($this->directory, 0700);
		\file_put_contents($this->directory . '/stale.txt', 'still on storage');
	}

	protected function tearDown(): void {
		\unlink($this->directory . '/stale.txt');
		\rmdir($this->directory);
	}

	/** @dataProvider cacheMissProvider */
	public function testCacheMissScanPolicy($option, $path, $cachedSize, $expectedScans, $expectedEntry, $needsUpdate = false) {
		$entries = $cachedSize === null ? [] : [$path => new CacheEntry(['size' => $cachedSize])];
		$cache = $this->getMockBuilder(Cache::class)->disableOriginalConstructor()->getMock();
		$cache->method('get')->willReturnCallback(function ($path) use (&$entries) {
			return $entries[$path] ?? false;
		});
		$scanner = $this->getMockBuilder(Scanner::class)->disableOriginalConstructor()->getMock();
		$scanner->expects($this->exactly($expectedScans))->method('scan')
			->with($path, Scanner::SCAN_SHALLOW)
			->willReturnCallback(function ($path) use (&$entries) {
				$entries[$path] = new CacheEntry(['size' => 16]);
			});
		$watcher = $this->getMockBuilder(Watcher::class)->disableOriginalConstructor()->getMock();
		$watcher->method('needsUpdate')->willReturn($needsUpdate);
		$watcher->expects($this->exactly($needsUpdate ? 1 : 0))->method('update')
			->willReturnCallback(function ($path) use (&$entries) {
				$entries[$path] = new CacheEntry(['size' => 23]);
			});
		$propagator = $this->createMock(IPropagator::class);
		$propagator->expects($this->exactly($needsUpdate ? 1 : 0))->method('propagateChange');
		$storage = $this->getMockBuilder(Local::class)
			->setConstructorArgs([['datadir' => $this->directory]])
			->onlyMethods(['getCache', 'getScanner', 'getWatcher', 'getPropagator'])->getMock();
		$storage->method('getCache')->willReturn($cache);
		$storage->method('getScanner')->willReturn($scanner);
		$storage->method('getWatcher')->willReturn($watcher);
		$storage->method('getPropagator')->willReturn($propagator);
		self::assertTrue($storage->file_exists($path));

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(function ($key, $default) use ($option) {
			self::assertSame('filesystem_scan_on_cache_miss', $key);
			self::assertTrue($default);
			return $option ?? $default;
		});
		$view = $this->getMockBuilder(View::class)->disableOriginalConstructor()
			->onlyMethods(['lockFile', 'unlockFile'])->getMock();
		$lookup = \Closure::bind(function ($storage, $path) use ($config) {
			$this->config = $config;
			return $this->getCacheEntry($storage, $path, $path);
		}, $view, View::class);

		$result = $lookup($storage, $path);
		if ($expectedEntry) {
			self::assertInstanceOf(CacheEntry::class, $result);
			self::assertSame($needsUpdate ? 23 : ($expectedScans ? 16 : $cachedSize), $result['size']);
		} else {
			self::assertFalse($result);
			self::assertSame([], $entries, 'A stale physical file must not be reinserted into the cache');
		}
	}

	public function cacheMissProvider() {
		return [
			'disabled does not resurrect a stale physical file' => [false, 'stale.txt', null, 0, false],
			'unset keeps automatic discovery' => [null, 'stale.txt', null, 1, true],
			'enabled keeps automatic discovery' => [true, 'stale.txt', null, 1, true],
			'disabled still bootstraps the storage root' => [false, '', null, 1, true],
			'disabled still scans an incomplete cache entry' => [false, 'stale.txt', IScanner::SIZE_NEEDS_SCAN, 1, true],
			'disabled preserves a complete cached entry' => [false, 'stale.txt', 7, 0, true],
			'disabled preserves watcher updates to cached entries' => [false, 'stale.txt', 7, 0, true, true],
		];
	}
}
