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

namespace Test\DB;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use OC\DB\Connection;
use OC\DB\ConnectionFactory;
use Test\TestCase;

/**
 * Nach längerem Leerlauf prüft die Verbindung sich vor der nächsten Abfrage selbst und baut
 * sich neu auf, wenn die Gegenseite sie in der Zwischenzeit getrennt hat.
 *
 * @group DB
 */
class ConnectionIdleReconnectTest extends TestCase {
	/** @var Connection eigene Verbindung, die geteilte der Testumgebung bleibt unberührt */
	private $connection;

	protected function setUp(): void {
		parent::setUp();
		$systemConfig = \OC::$server->getSystemConfig();
		$factory = new ConnectionFactory($systemConfig);
		$this->connection = $factory->getConnection(
			$systemConfig->getValue('dbtype', 'sqlite'),
			$factory->createConnectionParams()
		);
		$this->connection->connect();
	}

	protected function tearDown(): void {
		$this->connection->close();
		parent::tearDown();
	}

	private function driverConnection(): ?DriverConnection {
		$property = new \ReflectionProperty(\Doctrine\DBAL\Connection::class, '_conn');
		$property->setAccessible(true);
		return $property->getValue($this->connection);
	}

	private function replaceDriverConnection(DriverConnection $driverConnection): void {
		$property = new \ReflectionProperty(\Doctrine\DBAL\Connection::class, '_conn');
		$property->setAccessible(true);
		$property->setValue($this->connection, $driverConnection);
	}

	private function setIdleSeconds(int $seconds): void {
		$property = new \ReflectionProperty(Connection::class, 'lastUsed');
		$property->setAccessible(true);
		$property->setValue($this->connection, \time() - $seconds);
	}

	/** Verbindung, die die Gegenseite getrennt hat: Jede Abfrage scheitert */
	private function deadDriverConnection($expectedChecks): DriverConnection {
		$dead = $this->createMock(DriverConnection::class);
		$dead->expects($expectedChecks)->method('query')
			->willThrowException(new \RuntimeException('MySQL server has gone away'));
		return $dead;
	}

	private function dummySelect(): string {
		return $this->connection->getDatabasePlatform()->getDummySelectSQL();
	}

	public function testDeadConnectionIsReplacedAfterIdle(): void {
		$dead = $this->deadDriverConnection($this->once());
		$this->replaceDriverConnection($dead);
		$this->setIdleSeconds(Connection::IDLE_CHECK_AFTER + 1);

		// Die Abfrage läuft über eine neu aufgebaute Verbindung durch
		self::assertEquals(1, $this->connection->executeQuery($this->dummySelect())->fetchOne());
		self::assertNotSame($dead, $this->driverConnection());
	}

	public function testHealthyConnectionIsKeptAfterIdle(): void {
		$before = $this->driverConnection();
		$this->setIdleSeconds(Connection::IDLE_CHECK_AFTER + 1);

		self::assertEquals(1, $this->connection->executeQuery($this->dummySelect())->fetchOne());
		self::assertSame($before, $this->driverConnection());
	}

	public function testConnectionIsNotCheckedWithinIdleWindow(): void {
		$real = $this->driverConnection();
		$unchecked = $this->deadDriverConnection($this->never());
		$this->replaceDriverConnection($unchecked);
		$this->setIdleSeconds(Connection::IDLE_CHECK_AFTER - 5);

		$this->connection->connect();
		self::assertSame($unchecked, $this->driverConnection(), 'Im Normalbetrieb darf keine Prüfabfrage laufen');
		$this->replaceDriverConnection($real);
	}

	public function testConnectionIsNotReplacedInsideTransaction(): void {
		$this->connection->beginTransaction();
		$real = $this->driverConnection();
		$unchecked = $this->deadDriverConnection($this->never());
		$this->replaceDriverConnection($unchecked);
		$this->setIdleSeconds(Connection::IDLE_CHECK_AFTER + 1);

		$this->connection->connect();
		self::assertSame($unchecked, $this->driverConnection(), 'Eine offene Transaktion darf nicht stillschweigend verloren gehen');
		$this->replaceDriverConnection($real);
		$this->connection->rollBack();
	}

	/** Isolationsstufe, mit der die Sitzung gerade wirklich läuft */
	private function sessionIsolation(): string {
		$platform = $this->connection->getDatabasePlatform();
		if ($platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform) {
			return (string)$this->connection->executeQuery('PRAGMA read_uncommitted')->fetchOne();
		}
		if ($platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
			$row = $this->connection->executeQuery(
				"SHOW SESSION VARIABLES WHERE Variable_name IN ('tx_isolation', 'transaction_isolation')"
			)->fetchAssociative();
			return (string)$row['Value'];
		}
		if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
			return (string)$this->connection->executeQuery('SHOW transaction_isolation')->fetchOne();
		}
		$this->markTestSkipped('Isolationsstufe der Sitzung ist auf dieser Plattform nicht abfragbar');
	}

	public function testSessionIsolationIsRestoredAfterReconnect(): void {
		$before = $this->sessionIsolation();
		$this->replaceDriverConnection($this->deadDriverConnection($this->once()));
		$this->setIdleSeconds(Connection::IDLE_CHECK_AFTER + 1);

		self::assertSame($before, $this->sessionIsolation(), 'Die neue Sitzung braucht die Isolationsstufe der ersten');
	}

	public function testLastInsertIdNeverTriggersTheCheck(): void {
		$platform = $this->connection->getDatabasePlatform();
		if (!$platform instanceof \Doctrine\DBAL\Platforms\SqlitePlatform
			&& !$platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
			$this->markTestSkipped('Die Einfüge-ID kommt auf dieser Plattform aus einer eigenen Abfrage');
		}
		$real = $this->driverConnection();
		// Auf MySQL setzt jede Abfrage die Einfüge-ID der Sitzung zurück: Zwischen INSERT und
		// lastInsertId() darf deshalb auch nach langem Leerlauf keine Prüfabfrage laufen.
		$driver = $this->createMock(DriverConnection::class);
		$driver->expects($this->never())->method('query');
		$driver->expects($this->once())->method('lastInsertId')->willReturn('42');
		$this->replaceDriverConnection($driver);
		$this->setIdleSeconds(Connection::IDLE_CHECK_AFTER + 1);

		self::assertEquals(42, $this->connection->lastInsertId('*PREFIX*filecache'));
		$this->replaceDriverConnection($real);
	}
}
