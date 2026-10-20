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
 */

namespace Test\Session;

use OC\Session\CryptoSessionData;
use OC\Session\Internal;
use OC\Session\Memory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\GenericEvent;
use Test\TestCase;

/**
 * CryptoSessionData nach vorzeitigem Freigeben der Sitzungssperre: Was die
 * Anfrage danach noch ändert, landet beim Schließen auf dem frisch gelesenen
 * Stand – nur das, und nur wenn sich wirklich etwas geändert hat.
 */
class CryptoSessionDataLockReleaseTest extends TestCase {
	/** @var Internal|MockObject */
	private $internal;
	/** @var ICrypto|MockObject */
	private $crypto;
	/** @var string|null was Internal unter encrypted_session_data liefert */
	private $stored;
	/** @var string[] Aufrufe an Internal in Reihenfolge */
	private $calls = [];
	/** @var string[] verschlüsselt geschriebene Werte */
	private $written = [];

	protected function setUp(): void {
		parent::setUp();
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(static function ($input) {
			return '#' . $input . '#';
		});
		$this->crypto->method('decrypt')->willReturnCallback(static function ($input) {
			return $input ? \substr($input, 1, -1) : '';
		});

		$this->internal = $this->createMock(Internal::class);
		$this->internal->method('get')->willReturnCallback(function ($key) {
			return $key === CryptoSessionData::encryptedSessionName ? $this->stored : null;
		});
		$this->internal->method('set')->willReturnCallback(function ($key, $value) {
			$this->calls[] = 'set';
			$this->written[] = $value;
		});
		$this->internal->method('releaseLock')->willReturnCallback(function () {
			$this->calls[] = 'releaseLock';
			return true;
		});
		$this->internal->method('close')->willReturnCallback(function () {
			$this->calls[] = 'close';
		});
		$this->internal->method('clear')->willReturnCallback(function () {
			$this->calls[] = 'clear';
		});
		$this->internal->method('regenerateId')->willReturnCallback(function () {
			$this->calls[] = 'regenerateId';
		});
	}

	private function store(array $values): void {
		$this->stored = '#' . \json_encode($values) . '#';
	}

	private function lastWrittenValues(): array {
		$this->assertNotEmpty($this->written, 'nichts geschrieben');
		return \json_decode(\substr(\end($this->written), 1, -1), true);
	}

	private function releasedSession(array $values): CryptoSessionData {
		$this->store($values);
		$session = new CryptoSessionData($this->internal, $this->crypto, 'PASS');
		$this->assertTrue($session->releaseLock());
		$this->calls = [];
		$this->written = [];
		return $session;
	}

	public function testReleaseLockWritesPendingChangesBeforeReleasing(): void {
		$this->store(['user_id' => 'alice']);
		$session = new CryptoSessionData($this->internal, $this->crypto, 'PASS');
		$session->set('LAST_ACTIVITY', 200);

		$this->assertTrue($session->releaseLock());

		$this->assertSame(['set', 'releaseLock'], $this->calls);
		$this->assertSame(['user_id' => 'alice', 'LAST_ACTIVITY' => 200], $this->lastWrittenValues());
	}

	public function testCloseWithoutNetChangeKeepsTheLockFree(): void {
		$session = $this->releasedSession(['user_id' => 'alice', 'LAST_ACTIVITY' => 200]);
		$this->internal->expects($this->never())->method('reacquireLock');

		// wie base.php während checkServer(): Benutzer kurz entfernt und wieder gesetzt
		$session->set('user_id', null);
		$session->set('user_id', 'alice');
		$session->close();

		$this->assertSame(['close'], $this->calls);
	}

	public function testCloseMergesOwnChangesIntoTheFreshlyReadSession(): void {
		$session = $this->releasedSession(['user_id' => 'alice', 'LAST_ACTIVITY' => 200, 'gone' => 1]);
		$session->set('last_check_timeout', 500);
		$session->remove('gone');
		// inzwischen hat eine parallele Anfrage derselben Sitzung gespeichert
		$this->internal->expects($this->once())->method('reacquireLock')->willReturnCallback(function () {
			$this->calls[] = 'reacquireLock';
			$this->store(['user_id' => 'alice', 'LAST_ACTIVITY' => 260, 'gone' => 1, 'parallel' => 'x']);
			return true;
		});

		$session->close();

		$this->assertSame(['reacquireLock', 'set', 'close'], $this->calls);
		$this->assertEquals(
			['user_id' => 'alice', 'LAST_ACTIVITY' => 260, 'parallel' => 'x', 'last_check_timeout' => 500],
			$this->lastWrittenValues()
		);
	}

	public function testCloseDropsChangesAndLogsWhenTheLockStaysBusy(): void {
		$session = $this->releasedSession(['user_id' => 'alice']);
		$session->set('last_check_timeout', 500);
		$this->internal->method('reacquireLock')->willReturn(false);
		$logged = [];
		$listener = function (GenericEvent $event) use (&$logged) {
			$logged[] = $event->getArguments();
		};
		$dispatcher = \OC::$server->getEventDispatcher();
		$dispatcher->addListener('log.beforewrite', $listener);
		try {
			$session->close();
		} finally {
			$dispatcher->removeListener('log.beforewrite', $listener);
		}

		$this->assertSame([], $this->written);
		$this->assertSame(['close'], $this->calls);
		$this->assertSame(500, $session->get('last_check_timeout'), 'die Anfrage selbst sieht ihre Änderung weiter');
		$warnings = \array_values(\array_filter($logged, static function (array $entry) {
			return \strpos($entry['formattedMessage'], 'Session lock could not be reacquired') === 0;
		}));
		$this->assertCount(1, $warnings);
		$this->assertSame('core', $warnings[0]['app']);
		$this->assertStringContainsString('last_check_timeout', $warnings[0]['formattedMessage']);
	}

	public function testClearNeedsNoMerge(): void {
		$session = $this->releasedSession(['user_id' => 'alice']);
		$this->internal->expects($this->never())->method('reacquireLock');

		$session->clear();
		$session->close();

		$this->assertSame(['clear', 'set', 'close'], $this->calls);
		$this->assertSame([], $this->lastWrittenValues());
	}

	public function testRegenerateIdReadsTheCurrentSessionFirst(): void {
		$session = $this->releasedSession(['user_id' => 'alice']);
		$this->internal->expects($this->once())->method('reacquireLock')->willReturnCallback(function () {
			$this->calls[] = 'reacquireLock';
			$this->store(['user_id' => 'alice', 'parallel' => 'x']);
			return true;
		});

		$session->regenerateId();

		$this->assertSame(['reacquireLock', 'regenerateId'], $this->calls);
		$this->assertSame('x', $session->get('parallel'));
	}

	public function testReleaseLockNeedsAPhpSession(): void {
		$session = new CryptoSessionData(new Memory(), $this->crypto, 'PASS');

		$this->assertFalse($session->releaseLock());
	}
}
