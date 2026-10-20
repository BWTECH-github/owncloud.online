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

use OC\Session\Internal;
use OCP\Session\Exceptions\SessionNotAvailableException;
use Test\TestCase;

/**
 * Sitzungsstart, wenn die Sitzungssperre belegt ist, und vorzeitiges Freigeben.
 *
 * Eine echte PHP-Sitzung ist prozessweit; jeder Test läuft deshalb in einem
 * eigenen Prozess. Statt Redis speichert FakeSessionSaveHandler im Speicher und
 * meldet sich wie phpredis, wenn die Sperre nicht frei wird.
 */
class InternalTest extends TestCase {
	private const SESSION_NAME = 'oc_internal_test';
	private const SESSION_ID = 'sid0123456789';

	/** @var FakeSessionSaveHandler */
	private $handler;

	private function prepare(?string $failure, array $stored = []): void {
		$this->handler = new FakeSessionSaveHandler();
		$this->handler->failure = $failure;
		$this->handler->store = $stored;
		\session_set_save_handler($this->handler, false);
		$_COOKIE[self::SESSION_NAME] = self::SESSION_ID;
		InternalWithCookieProbe::$deletedCookies = 0;
	}

	private function assertSessionIdUnavailable(Internal $session): void {
		try {
			$session->getId();
			$this->fail('getId() muss ohne Sitzung SessionNotAvailableException werfen');
		} catch (SessionNotAvailableException $e) {
			$this->addToAssertionCount(1);
		}
	}

	/**
	 * phpredis bis 6.2 (und 6.3 ohne Lesemodus): nach lock_retries × lock_wait_time
	 * „Failed to acquire session lock“ und keine Sitzung.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLockTimeoutKeepsTheCookieAndContinuesWithoutSession(): void {
		$this->prepare('locked', [self::SESSION_ID => 'user_id|s:5:"alice";']);

		$session = new InternalWithCookieProbe(self::SESSION_NAME);

		$this->assertSame(0, InternalWithCookieProbe::$deletedCookies, 'die Sitzung ist nur belegt, nicht ungültig');
		$this->assertTrue($session->hasLockFailed());
		$this->assertFalse($session->isReadOnly());
		$this->assertStringContainsString('Failed to acquire session lock', $session->getStartFailure());
		$this->assertSame(PHP_SESSION_NONE, \session_status());
		$this->assertSessionIdUnavailable($session);
		$this->assertNull($session->get('user_id'));

		// ohne Sitzung weiter: im Speicher nutzbar, gespeichert wird nichts
		$session->set('LAST_ACTIVITY', 1);
		$this->assertSame(1, $session->get('LAST_ACTIVITY'));
		$session->close();
		$this->assertSame(0, $this->handler->writes);
	}

	/**
	 * phpredis ab 6.3 mit redis.session.lock_failure_readonly: gelesen wird
	 * trotzdem, schreiben darf die Anfrage nicht.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLockTimeoutInReadOnlyModeKeepsTheDataAndWritesNothing(): void {
		$this->prepare('readonly', [self::SESSION_ID => 'user_id|s:5:"alice";']);

		$session = new InternalWithCookieProbe(self::SESSION_NAME);

		$this->assertSame('alice', $session->get('user_id'));
		$this->assertTrue($session->isReadOnly());
		$this->assertSame(self::SESSION_ID, $session->getId());
		$this->assertSame(0, InternalWithCookieProbe::$deletedCookies);
		$this->assertStringContainsString('session will be read only', $session->getStartFailure());

		$session->set('LAST_ACTIVITY', 1);
		$session->close();
		$this->assertSame(0, $this->handler->writes, 'ohne Sperre wird nichts zurückgeschrieben');
		$this->assertSame('user_id|s:5:"alice";', $this->handler->store[self::SESSION_ID]);
	}

	/**
	 * Andere Startfehler behandelt Internal wie bisher: Cookie verwerfen.
	 * Der Speicherpfad (bei Redis womöglich mit Kennwort) erscheint nicht in der
	 * Meldung.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testOtherStartFailureStillDeletesTheCookieAndHidesTheSavePath(): void {
		$this->prepare('broken');

		$session = new InternalWithCookieProbe(self::SESSION_NAME);

		$this->assertSame(1, InternalWithCookieProbe::$deletedCookies);
		$this->assertFalse($session->hasLockFailed());
		$this->assertSame('session_start(): Failed to read session data: redis', $session->getStartFailure());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testReleaseLockWritesTheSessionAndKeepsItReadable(): void {
		$this->prepare(null, [self::SESSION_ID => 'a|i:1;']);
		$session = new Internal(self::SESSION_NAME);
		$session->set('b', 2);

		$this->assertTrue($session->releaseLock());

		$this->assertSame(1, $this->handler->writes);
		$this->assertSame('a|i:1;b|i:2;', $this->handler->store[self::SESSION_ID]);
		$this->assertSame(PHP_SESSION_NONE, \session_status(), 'Sperre frei');
		$this->assertSame(1, $session->get('a'));
		$this->assertSame(self::SESSION_ID, $session->getId());

		// danach nur noch im Speicher, auch close() schreibt nichts mehr
		$session->set('c', 3);
		$this->assertSame(3, $session->get('c'));
		$session->close();
		$this->assertSame(1, $this->handler->writes);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testReacquireLockReadsWhatOthersWroteMeanwhile(): void {
		$this->prepare(null, [self::SESSION_ID => 'a|i:1;']);
		$session = new Internal(self::SESSION_NAME);
		$session->releaseLock();
		// eine parallele Anfrage derselben Sitzung speichert inzwischen
		$this->handler->store[self::SESSION_ID] = 'a|i:1;parallel|s:1:"x";';

		$this->assertTrue($session->reacquireLock());

		$this->assertSame(2, $this->handler->reads);
		$this->assertSame('x', $session->get('parallel'));
		$this->assertSame(PHP_SESSION_ACTIVE, \session_status(), 'wieder gesperrt offen');
		$session->set('d', 4);
		$session->close();
		$this->assertSame('a|i:1;parallel|s:1:"x";d|i:4;', $this->handler->store[self::SESSION_ID]);
	}

	/**
	 * Abmelden nach vorzeitigem Freigeben: clear() holt die Sperre zurück, damit
	 * das Leeren gespeichert wird.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testClearAfterReleaseLockEmptiesTheStoredSession(): void {
		$this->prepare(null, [self::SESSION_ID => 'user_id|s:5:"alice";']);
		$session = new Internal(self::SESSION_NAME);
		$session->releaseLock();

		$session->clear();
		$session->close();

		$this->assertNull($session->get('user_id'));
		$this->assertContains(self::SESSION_ID, $this->handler->destroyed);
		$this->assertStringNotContainsString('alice', \implode('', $this->handler->store));
	}

	/**
	 * Abmelden ohne Sperre (Lesemodus): die gespeicherte Sitzung wird verworfen,
	 * das braucht keine Sperre.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testClearInReadOnlyModeDestroysTheStoredSession(): void {
		$this->prepare('readonly', [self::SESSION_ID => 'user_id|s:5:"alice";']);
		$session = new Internal(self::SESSION_NAME);

		$session->clear();
		$session->close();

		$this->assertSame([self::SESSION_ID], $this->handler->destroyed);
		$this->assertSame(0, $this->handler->writes);
		$this->assertNull($session->get('user_id'));
	}
}

/**
 * Hält fest, wie oft Internal das Sitzungs-Cookie verwerfen will (in der CLI
 * kommen gesetzte Cookies nicht in headers_list() an).
 */
class InternalWithCookieProbe extends Internal {
	/** @var int */
	public static $deletedCookies = 0;

	protected function deleteSessionCookie(): void {
		self::$deletedCookies++;
	}
}
