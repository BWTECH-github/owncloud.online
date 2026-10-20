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

use OC\AppFramework\Http\Request;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Session\CryptoWrapper;
use OC\Session\Memory;
use OCA\DAV\Connector\Sabre\Auth;
use OCP\Util;
use Symfony\Component\EventDispatcher\GenericEvent;
use Test\TestCase;

/**
 * OC::initSession() bei belegter Sitzungssperre und bei Anfragen von
 * Sync-Clients.
 *
 * Auf testoo lief der Desktop-Client mit parallelen PROPFIND (Bearer-Token und
 * Sitzungs-Cookie) in die Sperre von phpredis. Nach lock_retries × lock_wait_time
 * startete die Sitzung nicht, initSession() warf beim Invalidieren des Tokens
 * SessionNotAvailableException, und remote.php antwortete mit „503 Internal
 * Server Error“. Die Tests mit echter PHP-Sitzung laufen je in einem eigenen
 * Prozess, den Sitzungsspeicher stellt FakeSessionSaveHandler.
 */
class InitSessionTest extends TestCase {
	private const SESSION_ID = 'sid0123456789';
	private const PASSPHRASE = 'passphrase-for-tests';

	/** @var FakeSessionSaveHandler */
	private $handler;
	/** @var array[] */
	private $logged = [];

	/**
	 * @param string|null $failure siehe FakeSessionSaveHandler
	 * @param array|null $values gespeicherte Sitzungswerte, null für keine Sitzung
	 * @param array $server zusätzliche $_SERVER-Werte der Anfrage
	 */
	private function prepare(?string $failure, ?array $values, array $server = []): void {
		$sessionName = \OC_Util::getInstanceId();
		$cookies = [$sessionName => self::SESSION_ID, CryptoWrapper::COOKIE_NAME => self::PASSPHRASE];
		$_COOKIE = $cookies;

		$this->handler = new FakeSessionSaveHandler();
		$this->handler->failure = $failure;
		if ($values !== null) {
			$encrypted = \OC::$server->getCrypto()->encrypt(\json_encode($values), self::PASSPHRASE);
			$this->handler->store[self::SESSION_ID] = 'encrypted_session_data|' . \serialize($encrypted);
		}
		\session_set_save_handler($this->handler, false);

		$request = new Request(
			['cookies' => $cookies, 'server' => $server + ['REQUEST_METHOD' => 'PROPFIND']],
			\OC::$server->getSecureRandom(),
			\OC::$server->getConfig()
		);
		$this->overwriteService('Request', $request);
		$this->overwriteService('CryptoWrapper', new CryptoWrapper(
			\OC::$server->getCrypto(),
			\OC::$server->getSecureRandom(),
			$request,
			\OC::$server->getTimeFactory()
		));

		\OC::$server->getEventDispatcher()->addListener('log.beforewrite', function (GenericEvent $event) {
			$this->logged[] = $event->getArguments();
		});
	}

	private function setScript(string $script): void {
		$property = new \ReflectionProperty(\OC::class, 'SUBURI');
		$property->setValue(null, $script);
	}

	/**
	 * @return array[] Einträge der App core, deren Meldung so beginnt
	 */
	private function loggedStartingWith(string $prefix): array {
		return \array_values(\array_filter($this->logged, static function (array $entry) use ($prefix) {
			return $entry['app'] === 'core' && \strpos($entry['formattedMessage'], $prefix) === 0;
		}));
	}

	private function storedValues(): array {
		$serialized = $this->handler->store[self::SESSION_ID];
		$encrypted = \unserialize(\substr($serialized, \strlen('encrypted_session_data|')));
		return \json_decode(\OC::$server->getCrypto()->decrypt($encrypted, self::PASSPHRASE), true);
	}

	/**
	 * Der Fall von testoo: Sperre nicht rechtzeitig frei, keine Sitzung.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLockTimeoutNoLongerAbortsTheRequest(): void {
		$this->prepare('locked', ['user_id' => 'alice', 'LAST_ACTIVITY' => \time()]);

		\OC::initSession();

		$warnings = $this->loggedStartingWith('Session lock not acquired in time');
		$this->assertCount(1, $warnings);
		$this->assertSame(Util::WARN, $warnings[0]['loglevel']);
		$this->assertStringContainsString('continuing without session: session_start(): Failed to acquire session lock', $warnings[0]['formattedMessage']);
		// ohne Sitzung weiter
		$this->assertNull(\OC::$server->getSession()->get('user_id'));
		$this->assertIsInt(\OC::$server->getSession()->get('LAST_ACTIVITY'));
		$this->assertSame(0, $this->handler->writes);
	}

	/**
	 * Andere Startfehler (Redis antwortet nicht) stehen als Fehler im Protokoll,
	 * ohne Speicherpfad; die Anfrage läuft ohne Sitzung weiter.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testOtherStartFailureIsLoggedAndTheRequestContinues(): void {
		$this->prepare('broken', ['user_id' => 'alice', 'LAST_ACTIVITY' => \time()]);

		\OC::initSession();

		$errors = $this->loggedStartingWith('Session could not be started');
		$this->assertCount(1, $errors);
		$this->assertSame(Util::ERROR, $errors[0]['loglevel']);
		$this->assertSame('Session could not be started properly: session_start(): Failed to read session data: redis', $errors[0]['formattedMessage']);
		$this->assertNull(\OC::$server->getSession()->get('user_id'));
	}

	/**
	 * phpredis 6.3 im Lesemodus: die Anmeldung bleibt für diese Anfrage erhalten.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLockTimeoutInReadOnlyModeKeepsTheLogin(): void {
		$this->prepare('readonly', ['user_id' => 'alice', 'LAST_ACTIVITY' => \time() - 10]);

		\OC::initSession();
		\OC::$server->getSession()->close();

		$warnings = $this->loggedStartingWith('Session lock not acquired in time');
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('continuing read-only', $warnings[0]['formattedMessage']);
		$this->assertSame('alice', \OC::$server->getSession()->get('user_id'));
		$this->assertSame(0, $this->handler->writes, 'ohne Sperre wird nichts zurückgeschrieben');
	}

	private function tokenProvider(): IProvider {
		return \OC::$server->query(IProvider::class);
	}

	private function sessionTokenExists(): bool {
		try {
			$this->tokenProvider()->getToken(self::SESSION_ID);
			return true;
		} catch (InvalidTokenException $e) {
			return false;
		}
	}

	/**
	 * Wie bisher: Eine neu begonnene Sitzung mit mitgeschicktem Cookie räumt ein
	 * verwaistes Token derselben Kennung ab.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testNewSessionStillInvalidatesAnOrphanedToken(): void {
		$this->prepare(null, null);
		$this->tokenProvider()->generateToken(self::SESSION_ID, 'alice', 'alice', null, 'verwaist');

		\OC::initSession();

		$this->assertFalse($this->sessionTokenExists());
	}

	/**
	 * Ohne Sperre gelesen und ohne LAST_ACTIVITY: Diese Sitzung legt womöglich
	 * gerade eine parallele Anfrage an (etwa eine lange Seitenanfrage nach der
	 * Anmeldung). Deren Token darf nicht verschwinden.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testReadOnlySessionDoesNotInvalidateTheToken(): void {
		$this->prepare('readonly', ['user_id' => 'alice']);
		$this->tokenProvider()->generateToken(self::SESSION_ID, 'alice', 'alice', null, 'parallel angelegt');

		try {
			\OC::initSession();

			$this->assertTrue($this->sessionTokenExists());
		} finally {
			$this->tokenProvider()->invalidateToken(self::SESSION_ID);
		}
	}

	/**
	 * Sync-Client mit angemeldeter Sitzung: Sperre gleich nach initSession() frei.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testSyncClientRequestReleasesTheLockRightAway(): void {
		$this->setScript('/remote.php');
		$this->prepare(
			null,
			['user_id' => 'alice', Auth::DAV_AUTHENTICATED => 'alice', 'LAST_ACTIVITY' => \time() - 10],
			['HTTP_AUTHORIZATION' => 'Bearer token-for-tests']
		);

		\OC::initSession();

		$this->assertSame(PHP_SESSION_NONE, \session_status(), 'Sperre frei');
		$this->assertSame(1, $this->handler->writes, 'LAST_ACTIVITY ist gespeichert');
		$this->assertGreaterThan(\time() - 5, $this->storedValues()['LAST_ACTIVITY']);
		$session = \OC::$server->getSession();
		$this->assertSame('alice', $session->get('user_id'));

		// wie OC::init() während checkServer(): kurz ohne Benutzer, dann wieder mit
		$session->set('user_id', null);
		$session->set('user_id', 'alice');
		$session->close();

		$this->assertSame(1, $this->handler->reads, 'ohne Änderung keine zweite Sperre');
		$this->assertSame(1, $this->handler->writes);
	}

	/**
	 * Ändert die Anfrage nach dem Freigeben doch etwas, wird es gespeichert.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testChangeAfterEarlyReleaseIsSavedOnClose(): void {
		$this->setScript('/remote.php');
		$this->prepare(
			null,
			['user_id' => 'alice', Auth::DAV_AUTHENTICATED => 'alice', 'LAST_ACTIVITY' => \time() - 10],
			['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'app-password']
		);
		\OC::initSession();

		\OC::$server->getSession()->set('last_check_timeout', 1234);
		\OC::$server->getSession()->close();

		$this->assertSame(2, $this->handler->reads, 'Sperre zurückgeholt und frisch gelesen');
		$this->assertSame(2, $this->handler->writes);
		$stored = $this->storedValues();
		$this->assertSame(1234, $stored['last_check_timeout']);
		$this->assertSame('alice', $stored['user_id']);
	}

	/**
	 * Browser (ohne Authorization-Kopf): Sperre bleibt wie bisher bis close().
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testBrowserRequestKeepsTheLock(): void {
		$this->setScript('/remote.php');
		$this->prepare(null, ['user_id' => 'alice', Auth::DAV_AUTHENTICATED => 'alice', 'LAST_ACTIVITY' => \time() - 10]);

		\OC::initSession();

		$this->assertSame(PHP_SESSION_ACTIVE, \session_status());
		$this->assertSame(0, $this->handler->writes);
	}

	public function providesReleaseCases(): array {
		$davSession = ['user_id' => 'alice', Auth::DAV_AUTHENTICATED => 'alice'];
		$bearer = ['HTTP_AUTHORIZATION' => 'Bearer abc'];
		return [
			'Bearer, WebDAV-Sitzung' => [true, '/remote.php', $bearer, true, $davSession],
			'Basic über PHP_AUTH_USER' => [true, '/remote.php', ['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'x'], true, $davSession],
			'Kopf nach Rewrite' => [true, '/remote.php', ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc'], true, $davSession],
			'Browser ohne Authorization' => [false, '/remote.php', [], true, $davSession],
			'andere Skripte' => [false, '/index.php', $bearer, true, $davSession],
			'ohne Sitzungs-Cookie' => [false, '/remote.php', $bearer, false, $davSession],
			'Sitzung nicht für WebDAV angemeldet' => [false, '/remote.php', $bearer, true, ['user_id' => 'alice']],
			'WebDAV-Anmeldung eines anderen Kontos' => [false, '/remote.php', $bearer, true, ['user_id' => 'alice', Auth::DAV_AUTHENTICATED => 'bob']],
			'nicht angemeldet' => [false, '/remote.php', $bearer, true, ['user_id' => '', Auth::DAV_AUTHENTICATED => '']],
		];
	}

	/**
	 * @dataProvider providesReleaseCases
	 */
	public function testCanReleaseSessionLock(bool $expected, string $script, array $server, bool $withCookie, array $values): void {
		$request = new Request(
			['server' => $server, 'cookies' => $withCookie ? [\session_name() => self::SESSION_ID] : []],
			\OC::$server->getSecureRandom(),
			\OC::$server->getConfig()
		);
		$session = new Memory();
		foreach ($values as $key => $value) {
			$session->set($key, $value);
		}

		$this->assertSame($expected, self::invokePrivate(\OC::class, 'canReleaseSessionLock', [$script, $request, $session]));
	}
}
