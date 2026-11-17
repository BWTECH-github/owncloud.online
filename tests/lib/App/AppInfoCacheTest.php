<?php

namespace Test\App;

use OC\App\AppManager;
use OC\Memcache\ArrayCache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AppInfoCacheBackend extends ArrayCache {
	public $reads = [];

	public function get($key) {
		$this->reads[$key] = ($this->reads[$key] ?? 0) + 1;
		return parent::get($key);
	}
}

class AppInfoCacheTest extends TestCase {
	private $root;
	private $path;
	private $cache;
	private $manager;

	protected function setUp(): void {
		$this->root = \realpath(\sys_get_temp_dir()) . '/oc-app-info-' . \bin2hex(\random_bytes(8));
		$this->path = $this->root . '/cachetest/appinfo/info.xml';
		\mkdir(\dirname($this->path), 0700, true);
		$this->writeInfo($this->path, '1.0', 'FirstNamespace');
		$this->cache = new AppInfoCacheBackend();
		$this->manager = $this->newManager();
	}

	protected function tearDown(): void {
		foreach (\glob($this->root . '/cachetest/appinfo/*') as $file) {
			\unlink($file);
		}
		\rmdir($this->root . '/cachetest/appinfo');
		\rmdir($this->root . '/cachetest');
		\rmdir($this->root);
	}

	private function newManager() {
		$factory = $this->getMockBuilder(ICacheFactory::class)
			->onlyMethods(['create', 'isAvailable'])
			->addMethods(['createLocal'])
			->getMock();
		$factory->method('createLocal')->with('app-info')->willReturn($this->cache);
		$factory->method('create')->with('settings')->willReturn(new ArrayCache());
		$manager = $this->getMockBuilder(AppManager::class)
			->setConstructorArgs([
				$factory,
				$this->createMock(EventDispatcherInterface::class),
				$this->createMock(IConfig::class),
			])
			->onlyMethods(['getAppRoots'])
			->getMock();
		$manager->method('getAppRoots')->willReturn([['path' => $this->root, 'url' => '/apps']]);
		return $manager;
	}

	private function writeInfo($path, $version, $namespace) {
		\file_put_contents($path, '<info><id>cachetest</id><version>' . $version
			. '</version><namespace>' . $namespace . '</namespace></info>');
		\clearstatcache(true, $path);
	}

	/** @dataProvider lookupProvider */
	public function testRepeatedLookupsReadBackendOnce($byPath) {
		$this->manager->getAppInfoByPath($this->path);
		$this->manager = $this->newManager();
		$this->cache->reads = [];
		for ($i = 0; $i < 5; $i++) {
			$info = $byPath ? $this->manager->getAppInfoByPath($this->path)
				: $this->manager->getAppInfo('cachetest');
			self::assertSame('FirstNamespace', $info['namespace']);
			self::assertTrue($info['_cached']);
		}
		self::assertSame(1, $this->cache->reads[$byPath ? $this->path : 'cachetest']);
	}

	public function lookupProvider() {
		return ['app id' => [false], 'real path' => [true]];
	}

	public function testParsedEntryServesBothKeysWithoutAnotherBackendRead() {
		$first = $this->manager->getAppInfoByPath($this->path);
		$reads = $this->cache->reads;
		self::assertFalse($first['_cached']);
		self::assertTrue($this->manager->getAppInfo('cachetest')['_cached']);
		self::assertTrue($this->manager->getAppInfoByPath($this->path)['_cached']);
		self::assertSame($reads, $this->cache->reads);
	}

	public function testReusedInfoKeepsAllLanguagesAndIgnoresCallerChanges() {
		\file_put_contents($this->path, '<info><id>cachetest</id><version>1.0</version>'
			. '<name lang="en">English</name><name lang="de">Deutsch</name></info>');
		$info = $this->manager->getAppInfoByPath($this->path);
		self::assertSame([
			['@attributes' => ['lang' => 'en'], '@value' => 'English'],
			['@attributes' => ['lang' => 'de'], '@value' => 'Deutsch'],
		], $info['name']);
		$info['name'][0]['@value'] = 'Changed by caller';
		self::assertSame('English', $this->manager->getAppInfo('cachetest')['name'][0]['@value']);
	}

	/** @dataProvider xmlChangeProvider */
	public function testRepeatedLookupsStillNoticeXmlChanges($byPath, $version) {
		$this->manager->getAppInfoByPath($this->path);
		$this->manager->getAppPath('cachetest');
		$this->writeInfo($this->path, $version, 'ChangedNamespace');
		$info = $byPath ? $this->manager->getAppInfoByPath($this->path)
			: $this->manager->getAppInfo('cachetest');
		self::assertSame('ChangedNamespace', $info['namespace']);
		self::assertFalse($info['_cached']);
		self::assertSame('ChangedNamespace', $this->manager->getAppInfo('cachetest')['namespace']);
	}

	public function xmlChangeProvider() {
		return [
			'app id, same version' => [false, '1.0'],
			'app id, upgrade' => [false, '2.0'],
			'real path, same version' => [true, '1.0'],
			'real path, upgrade' => [true, '2.0'],
		];
	}

	public function testEquivalentPathsReuseTheSameEntry() {
		$this->manager->getAppInfoByPath($this->path);
		$this->manager = $this->newManager();
		$this->cache->reads = [];
		$this->manager->getAppInfoByPath($this->path);
		$info = $this->manager->getAppInfoByPath(\dirname($this->path) . '/../appinfo/info.xml');
		self::assertSame('FirstNamespace', $info['namespace']);
		self::assertSame(1, $this->cache->reads[$this->path]);
	}

	public function testClearAppsCacheDropsRequestEntries() {
		$this->manager->getAppInfoByPath($this->path);
		$this->manager->getAppInfo('cachetest');
		$this->manager->clearAppsCache();
		$info = $this->manager->getAppInfoByPath($this->path);
		self::assertFalse($info['_cached']);
		self::assertSame('FirstNamespace', $info['namespace']);
	}

	public function testPathLookupRetainsNewestAppIdVersion() {
		$newer = \dirname($this->path) . '/newer.xml';
		$this->writeInfo($newer, '2.0', 'NewerNamespace');
		$this->manager->getAppInfoByPath($newer);
		$this->manager->getAppInfoByPath($this->path);
		self::assertSame('2.0', $this->manager->getAppInfo('cachetest')['version']);
		self::assertSame('1.0', $this->manager->getAppInfoByPath($this->path)['version']);
	}

	public function testOlderRequestCannotReplaceNewerSharedAppIdVersion() {
		$this->manager->getAppInfoByPath($this->path);
		$newer = \dirname($this->path) . '/newer.xml';
		$this->writeInfo($newer, '3.0', 'NewerNamespace');
		$this->newManager()->getAppInfoByPath($newer);
		$middle = \dirname($this->path) . '/middle.xml';
		$this->writeInfo($middle, '2.0', 'MiddleNamespace');
		self::assertSame('2.0', $this->manager->getAppInfoByPath($middle)['version']);
		self::assertSame('3.0', $this->newManager()->getAppInfo('cachetest')['version']);
	}

	public function testRemovedCachedPathResolvesCurrentAppPath() {
		$old = \dirname($this->path) . '/old.xml';
		$this->writeInfo($old, '2.0', 'OldPathNamespace');
		$this->manager->getAppInfoByPath($old);
		\unlink($old);
		\clearstatcache(true, $old);
		self::assertSame('FirstNamespace', $this->manager->getAppInfo('cachetest')['namespace']);
	}
}
