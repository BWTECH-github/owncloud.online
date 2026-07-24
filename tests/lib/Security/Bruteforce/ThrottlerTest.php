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

namespace Test\Security\Bruteforce;

use OCO\Security\Bruteforce\Throttler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\ILogger;
use Test\TestCase;

/**
 * @group DB
 */
class ThrottlerTest extends TestCase {
	/** @var IDBConnection */
	private $db;

	/** @var Throttler */
	private $throttler;

	/** @var int controllable "current time" */
	private $now = 1000000;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OC::$server->getDatabaseConnection();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(function () {
			return $this->now;
		});

		$this->throttler = new Throttler($this->db, $timeFactory, $this->createMock(ILogger::class));
		$this->wipe();
	}

	protected function tearDown(): void {
		$this->wipe();
		parent::tearDown();
	}

	private function wipe() {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(Throttler::DB_TABLE)->execute();
	}

	public function testNoDelayWithoutAttempts() {
		$this->assertSame(0, $this->throttler->getDelay('login', '1.2.3.4', 'alice'));
	}

	/**
	 * The exponential back-off must climb but never exceed the 30s cap, so a
	 * single request can never hang an FPM worker for minutes.
	 */
	public function testDelayGrowsButIsCappedAt30() {
		$prev = 0;
		for ($i = 1; $i <= 20; $i++) {
			$this->throttler->registerAttempt('login', '1.2.3.4', 'alice');
			$delay = $this->throttler->getDelay('login', '1.2.3.4', 'alice');
			$this->assertGreaterThan(0, $delay);
			$this->assertLessThanOrEqual(30, $delay, "attempt {$i} must stay <= 30s cap");
			$this->assertGreaterThanOrEqual($prev, $delay, 'delay must be monotonically non-decreasing');
			$prev = $delay;
		}
		// Well past 2^5=32 the value must sit exactly on the cap.
		$this->assertSame(30, $this->throttler->getDelay('login', '1.2.3.4', 'alice'));
	}

	/**
	 * Attempts are keyed on action+ip+identifier; unrelated combinations must
	 * not be throttled.
	 */
	public function testDelayIsKeyedPerActionIpIdentifier() {
		$this->throttler->registerAttempt('login', '1.2.3.4', 'alice');
		$this->throttler->registerAttempt('login', '1.2.3.4', 'alice');

		$this->assertGreaterThan(0, $this->throttler->getDelay('login', '1.2.3.4', 'alice'));
		$this->assertSame(0, $this->throttler->getDelay('login', '9.9.9.9', 'alice'), 'different IP');
		$this->assertSame(0, $this->throttler->getDelay('login', '1.2.3.4', 'bob'), 'different identifier');
		$this->assertSame(0, $this->throttler->getDelay('share_password', '1.2.3.4', 'alice'), 'different action');
	}

	/**
	 * cleanupOldAttempts() must remove only rows outside the lookback window and
	 * leave live-window rows (and thus the current delay) intact.
	 */
	public function testCleanupDeletesOnlyExpiredRows() {
		$this->now = 1000000;
		$this->throttler->registerAttempt('login', '1.2.3.4', 'alice'); // expires later

		$this->now = 1000000 + 13 * 3600; // +13h: the first row is now older than the 12h window
		$this->throttler->registerAttempt('login', '1.2.3.4', 'alice'); // still live

		$deleted = $this->throttler->cleanupOldAttempts();
		$this->assertSame(1, $deleted, 'exactly the expired row is removed');

		// the surviving live-window row must still count towards the delay
		$this->assertGreaterThan(0, $this->throttler->getDelay('login', '1.2.3.4', 'alice'));
	}
}
