<?php
/**
 * This file is licensed under the Affero General Public License version 3 or later.
 * See the COPYING-README file.
 */

namespace Test\Files\Cache;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Result;
use OC\DB\AdapterSqlite;
use OC\DB\Connection;
use OC\Files\Cache\Cache;
use OC\Files\Cache\HomeCache;
use OC\Files\Cache\Propagator;
use OC\Files\Cache\Storage as StorageCache;
use OC\Files\Storage\Storage;
use OCP\IDBConnection;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;

/** Exercises the real cache SQL against an isolated in-memory database. */
class DeadlockRetryTest extends TestCase {
	/** @var DeadlockRetryConnection */
	private $connection;
	private $originalServer;

	protected function setUp(): void {
		$this->connection = DriverManager::getConnection([
			'driver' => 'pdo_sqlite',
			'memory' => true,
			'wrapperClass' => DeadlockRetryConnection::class,
			'adapter' => AdapterSqlite::class,
			'tablePrefix' => 'oc_',
		]);
		$this->originalServer = \OC::$server;
		\OC::$server = $this->createMock(IServerContainer::class);
		\OC::$server->method('getDatabaseConnection')->willReturn($this->connection);
		$this->connection->executeStatement('CREATE TABLE oc_filecache (
			fileid INTEGER PRIMARY KEY, storage INTEGER, parent INTEGER, path TEXT,
			path_hash TEXT, size INTEGER, mtime INTEGER, etag TEXT)');
		foreach ([[1, -1, '', 200], [2, 1, 'files', 200], [3, 2, 'files/folder', 100],
			[4, 3, 'files/folder/a', 20], [5, 3, 'files/folder/b', 30]] as $row) {
			$this->connection->insert('oc_filecache', [
				'fileid' => $row[0], 'storage' => 1, 'parent' => $row[1], 'path' => $row[2],
				'path_hash' => \md5($row[2]), 'size' => $row[3], 'mtime' => 100, 'etag' => 'before',
			]);
		}
	}

	protected function tearDown(): void {
		$this->connection->close();
		\OC::$server = $this->originalServer;
	}

	public function folderProvider() {
		return [['cache', 'files/folder', 4, 40, 70], ['home', 'files', 3, 80, 80], ['home', 'files/folder', 4, 40, 70]];
	}

	/** @dataProvider folderProvider */
	public function testFolderSizeRereadsChildrenAfterDeadlock($kind, $path, $childId, $childSize, $expected) {
		$cache = $kind === 'home' ? new DeadlockRetryHomeCache($this->connection) : new DeadlockRetryCache($this->connection);
		$entry = $cache->get($path);
		$this->connection->failuresRemaining = 1;
		$this->connection->beforeFailure = function () use ($childId, $childSize) {
			// A competing committed change must be included when the calculation retries.
			$this->connection->getNativeConnection()->exec('UPDATE oc_filecache SET size = ' . $childSize . ' WHERE fileid = ' . $childId);
		};

		self::assertSame($expected, $cache->calculateFolderSize($path, $entry));
		self::assertSame($expected, $this->size($path));
		self::assertSame(2, $this->connection->updateAttempts);
	}

	public function staleFolderEntryProvider() {
		return [['cache', 'files/folder', 4, 70, 100], ['home', 'files', 3, 200, 200], ['home', 'files/folder', 4, 70, 100]];
	}

	/** @dataProvider staleFolderEntryProvider */
	public function testFolderSizeRefreshesParentEntryAfterDeadlock($kind, $path, $childId, $childSize, $expected) {
		$cache = $kind === 'home' ? new DeadlockRetryHomeCache($this->connection) : new DeadlockRetryCache($this->connection);
		$entry = $cache->get($path);
		$this->connection->failuresRemaining = 1;
		$this->connection->beforeFailure = function () use ($entry, $childId, $childSize) {
			$native = $this->connection->getNativeConnection();
			$native->exec('UPDATE oc_filecache SET size = ' . $childSize . ' WHERE fileid = ' . $childId);
			$native->exec('UPDATE oc_filecache SET size = 41 WHERE fileid = ' . $entry['fileid']);
		};

		// The new sum equals the stale entry; using that entry would skip the required UPDATE.
		self::assertSame($expected, $cache->calculateFolderSize($path, $entry));
		self::assertSame($expected, $this->size($path));
		self::assertSame(2, $this->connection->updateAttempts);
	}

	public function testPropagationAppliesSizeDifferenceExactlyOnceAfterDeadlock() {
		$this->connection->failuresRemaining = 1;
		$this->propagator()->propagateChange('files/folder/a', 200, -10);

		self::assertSame(190, $this->size(''));
		self::assertSame(190, $this->size('files'));
		self::assertSame(90, $this->size('files/folder'));
		self::assertSame(2, $this->connection->updateAttempts);
		self::assertSame(200, (int)$this->connection->fetchOne('SELECT mtime FROM oc_filecache WHERE fileid = 3'));
		self::assertNotSame('before', $this->connection->fetchOne('SELECT etag FROM oc_filecache WHERE fileid = 3'));
	}

	public function failureProvider() {
		$cases = [];
		foreach (['cache', 'home', 'propagator'] as $operation) {
			foreach (['exhausted', 'transaction', 'transaction_ended', 'transaction_started', 'timeout', 'other'] as $condition) {
				$cases[] = [$operation, $condition];
			}
		}
		return $cases;
	}

	/** @dataProvider failureProvider */
	public function testUnsafeOrExhaustedRetryPropagatesFailure($operation, $condition) {
		$this->connection->failuresRemaining = 10;
		$error = $this->connection->failure = $condition === 'timeout'
			? self::sqlFailure(LockWaitTimeoutException::class, 1205, 'HY000')
			: ($condition === 'other' ? new \RuntimeException('unrelated failure') : self::sqlFailure(DeadlockException::class, 1213, '40001'));
		if ($condition === 'transaction' || $condition === 'transaction_ended') {
			$this->connection->beginTransaction();
		}
		if ($condition === 'transaction_ended') {
			$this->connection->beforeFailure = function () {
				$this->connection->rollBack();
			};
		}
		if ($condition === 'transaction_started') {
			$this->connection->beforeFailure = function () {
				$this->connection->beginTransaction();
			};
		}

		try {
			$this->operate($operation);
			self::fail('The original failure must be propagated');
		} catch (\Throwable $caught) {
			self::assertSame($error, $caught);
			self::assertSame($condition === 'exhausted' ? 5 : 1, $this->connection->updateAttempts);
			self::assertSame(200, $this->size('files'));
		} finally {
			if ($this->connection->inTransaction()) {
				$this->connection->rollBack();
			}
		}
	}

	private function operate($operation) {
		if ($operation === 'propagator') {
			$this->propagator()->propagateChange('files/folder/a', 200, -10);
		} else {
			$cache = $operation === 'home' ? new DeadlockRetryHomeCache($this->connection) : new DeadlockRetryCache($this->connection);
			$cache->calculateFolderSize($operation === 'home' ? 'files' : 'files/folder');
		}
	}

	private function propagator() {
		$storageCache = $this->createMock(StorageCache::class);
		$storageCache->method('getNumericId')->willReturn(1);
		$storage = $this->createMock(Storage::class);
		$storage->method('getStorageCache')->willReturn($storageCache);
		return new Propagator($storage, $this->connection);
	}

	private function size($path) {
		return (int)$this->connection->fetchOne('SELECT size FROM oc_filecache WHERE path = ?', [$path]);
	}

	public static function sqlFailure($class, $code, $state) {
		$error = new \PDOException('Controlled SQL failure');
		$error->errorInfo = [$state, $code];
		return new $class(\Doctrine\DBAL\Driver\PDO\Exception::new($error), null);
	}
}

/** Injects a failed atomic UPDATE without replacing the actual SQL on success. */
class DeadlockRetryConnection extends Connection {
	public $failuresRemaining = 0;
	public $updateAttempts = 0;
	public $beforeFailure;
	public $failure;

	private function failUpdate($sql) {
		if (\preg_match('/^UPDATE\s/i', \ltrim($sql)) && \strpos($sql, 'filecache') !== false) {
			$this->updateAttempts++;
			if ($this->failuresRemaining > 0) {
				$this->failuresRemaining--;
				if ($this->beforeFailure) {
					($this->beforeFailure)();
				}
				throw $this->failure ?? DeadlockRetryTest::sqlFailure(DeadlockException::class, 1213, '40001');
			}
		}
	}

	public function executeQuery($query, array $params = [], $types = [], ?QueryCacheProfile $qcp = null): Result {
		$this->failUpdate($query);
		return parent::executeQuery($query, $params, $types, $qcp);
	}

	public function executeStatement($query, array $params = [], array $types = []) {
		$this->failUpdate($query);
		return parent::executeStatement($query, $params, $types);
	}
}

trait DeadlockRetryCacheFixture {
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	public function getNumericStorageId() {
		return 1;
	}

	public function get($path) {
		$entry = $this->connection->executeQuery('SELECT * FROM oc_filecache WHERE path = ?', [$path])->fetchAssociative();
		$entry['mimetype'] = 'httpd/unix-directory';
		return $entry;
	}
}

class DeadlockRetryCache extends Cache {
	use DeadlockRetryCacheFixture;
}

class DeadlockRetryHomeCache extends HomeCache {
	use DeadlockRetryCacheFixture;
}
