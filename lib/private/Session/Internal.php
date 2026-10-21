<?php
/**
 * @author cetra3 <peter@parashift.com.au>
 * @author Christoph Wurst <christoph@owncloud.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Phil Davis <phil.davis@inf.org>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-03-16.
 * Changes:
 *   - PHP 8.4 compatibility and owncloud.online design integration
 *   - php8.3 (#41449)
 */

namespace OC\Session;

use OC\AppFramework\Http\Request;
use OCP\Session\Exceptions\SessionNotAvailableException;

/**
 * Class Internal
 *
 * wrap php's internal session handling into the Session interface
 *
 * @package OC\Session
 */
class Internal extends Session {
	/**
	 * So meldet phpredis, dass die Sitzungssperre in der eingestellten Wartezeit
	 * (redis.session.lock_retries × lock_wait_time) nicht frei wurde: 6.x „Failed
	 * to acquire session lock“, 6.3 im Lesemodus mit dem Zusatz „…, session will
	 * be read only“, 5.3 „Acquire of session lock was not successful“.
	 */
	private const LOCK_FAILURE_MESSAGES = [
		'Failed to acquire session lock',
		'Acquire of session lock was not successful',
	];

	/** @var string|null erste Meldung beim letzten Sitzungsstart, null wenn er glatt lief */
	private $startFailure = null;

	/** @var bool die Sitzungssperre war beim letzten Start nicht zu bekommen */
	private $lockFailed = false;

	/** @var bool ohne Sperre geöffnet: lesbar, Änderungen werden nicht gespeichert */
	private $readOnly = false;

	/** @var bool Sperre nach releaseLock() frei, die Daten bleiben lesbar */
	private $lockReleased = false;

	/** @var bool nach einem Sperrfehler mit einer neuen, leeren Sitzung weiter */
	private $replacedLockedSession = false;

	/**
	 * @param string $name
	 * @throws \Exception
	 */
	public function __construct($name) {
		\session_name($name);
		$this->startSession();
		if ($this->lockFailed && $this->shouldReplaceLockedSession()) {
			$this->replaceLockedSession();
		}
		if ($_SESSION === null) {
			throw new \Exception('Failed to start session');
		}
	}

	/**
	 * @param string $key
	 * @param integer $value
	 * @throws \Exception
	 */
	public function set($key, $value) {
		$this->validateSession();
		$_SESSION[$key] = $value;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function get($key) {
		if (!$this->exists($key)) {
			return null;
		}
		return $_SESSION[$key];
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public function exists($key) {
		return isset($_SESSION[$key]);
	}

	/**
	 * @param string $key
	 */
	public function remove($key) {
		if (isset($_SESSION[$key])) {
			unset($_SESSION[$key]);
		}
	}

	public function clear() {
		if ($this->lockReleased) {
			// Leeren (Abmelden) muss gespeichert werden: Sperre zurückholen
			$this->reacquireLock();
		}
		if ($this->readOnly) {
			// Ohne Sperre lässt sich die gespeicherte Sitzung nur noch verwerfen,
			// dafür braucht phpredis keine Sperre. Danach ohne Sitzung weiter.
			@\session_destroy();
			$this->readOnly = false;
		}
		if (\session_status() !== PHP_SESSION_ACTIVE) {
			$_SESSION = [];
			return;
		}
		\session_unset();
		$this->regenerateId();
		@\session_destroy();
		@\session_start();
		$_SESSION = [];
	}

	public function close(): void {
		if ($this->readOnly) {
			// Ohne Sperre geöffnet: nichts zurückschreiben, phpredis lehnte es ab
			// („Unable to write session: session lock not held“).
			\session_abort();
		} elseif (!$this->lockReleased) {
			\session_write_close();
		}
		parent::close();
	}

	/**
	 * Schreibt die Sitzung und gibt ihre Sperre frei, ohne sie zu schließen.
	 *
	 * Lesen geht danach weiter; set() und remove() wirken nur noch im Speicher,
	 * bis reacquireLock() die Sperre zurückholt. Wer die Sitzung danach ändern
	 * will, muss das selbst tun (siehe CryptoSessionData::releaseLock()).
	 *
	 * @return bool false, wenn die Sitzung nicht gesperrt offen war
	 */
	public function releaseLock(): bool {
		if ($this->sessionClosed || $this->readOnly || \session_status() !== PHP_SESSION_ACTIVE) {
			return false;
		}
		// Scheitert das Schreiben, meldet PHP das selbst; frei ist die Sperre so oder so.
		\session_write_close();
		$this->lockReleased = true;
		return true;
	}

	/**
	 * Holt nach releaseLock() die Sperre zurück und liest die Sitzung dabei neu.
	 *
	 * Wartet wie jeder Sitzungsstart höchstens so lange, wie phpredis es zulässt.
	 * Geht nicht mehr, sobald die Antwortköpfe gesendet sind.
	 *
	 * @return bool true, wenn die Sitzung wieder gesperrt und speicherbar offen ist
	 */
	public function reacquireLock(): bool {
		if (!$this->lockReleased || $this->sessionClosed || \headers_sent()) {
			return false;
		}
		$this->lockReleased = false;
		$this->startSession();
		return !$this->readOnly && \session_status() === PHP_SESSION_ACTIVE;
	}

	/**
	 * @return bool true, wenn die Sitzung ohne Sperre nur lesend offen ist
	 */
	public function isReadOnly(): bool {
		return $this->readOnly;
	}

	/**
	 * @return bool true, wenn der letzte Start an der Sitzungssperre scheiterte
	 */
	public function hasLockFailed(): bool {
		return $this->lockFailed;
	}

	/**
	 * @return bool true, wenn die Anfrage nach einem Sperrfehler mit einer neuen,
	 *              leeren Sitzung weiterläuft (getStartFailure() nennt den Sperrfehler)
	 */
	public function hasReplacedLockedSession(): bool {
		return $this->replacedLockedSession;
	}

	/**
	 * Erste Meldung des letzten Sitzungsstarts, ohne Speicherpfad (der kann bei
	 * Redis Zugangsdaten enthalten) und ohne Sitzungskennung (wer das Protokoll
	 * liest, könnte damit die Sitzung übernehmen).
	 *
	 * @return string|null null, wenn der Start glatt lief
	 */
	public function getStartFailure(): ?string {
		if ($this->startFailure === null) {
			return null;
		}
		$failure = \preg_replace('/\s*\((?:path: )?[^()]*:\/\/[^()]*\)/', '', $this->startFailure);
		// Der files-Handler nennt die Kennung im Dateinamen, etwa „open(…/sess_<Kennung>, O_RDWR) failed“.
		// Kennungen dürfen „,“ enthalten (session.sid_bits_per_character=6), deshalb endet sie
		// erst vor „, “, „)“, Leerraum oder dem Textende.
		$failure = \preg_replace('/\bsess_[A-Za-z0-9,-]+?(?=,\s|[\s)]|$)/', 'sess_***', $failure);
		// Kurze Werte nicht ersetzen: Kennungen von PHP haben mindestens 22 Zeichen.
		foreach ([@\session_id(), $_COOKIE[\session_name()] ?? null] as $id) {
			if (\is_string($id) && \strlen($id) >= 16) {
				$failure = \str_replace($id, '***', $failure);
			}
		}
		return $failure;
	}

	/**
	 * Wrapper around session_regenerate_id
	 *
	 * @param bool $deleteOldSession Whether to delete the old associated session file or not.
	 * @return void
	 */
	public function regenerateId($deleteOldSession = true) {
		if ($this->lockReleased && !$this->reacquireLock()) {
			return;
		}
		if (@\session_regenerate_id($deleteOldSession) && $this->readOnly) {
			// Die neue Kennung hat ihre eigene Sperre: ab jetzt wieder speicherbar.
			$this->readOnly = false;
		}
	}

	/**
	 * Wrapper around session_id
	 *
	 * @return string
	 * @throws SessionNotAvailableException
	 * @since 9.1.0
	 */
	public function getId() {
		$id = @\session_id();
		if ($id === '') {
			throw new SessionNotAvailableException();
		}
		return $id;
	}

	/**
	 * @throws \Exception
	 */
	public function reopen() {
		throw new \Exception('The session cannot be reopened - reopen() is ony to be used in unit testing.');
	}

	/**
	 * @param int $errorNumber
	 * @param string $errorString
	 * @throws \ErrorException
	 */
	public function trapError($errorNumber, $errorString): void {
		if ($this->startFailure === null) {
			$this->startFailure = $errorString;
		}
		foreach (self::LOCK_FAILURE_MESSAGES as $lockFailure) {
			if (\strpos($errorString, $lockFailure) !== false) {
				// Nur belegt, nicht kaputt: nicht abbrechen. Im Lesemodus hat
				// phpredis die Sitzung trotzdem gelesen.
				$this->lockFailed = true;
				return;
			}
		}
		throw new \ErrorException($errorString);
	}

	/**
	 * Startet die PHP-Sitzung und hält fest, wie das ausging.
	 */
	private function startSession(): void {
		$this->startFailure = null;
		$this->lockFailed = false;
		\set_error_handler([$this, 'trapError']);
		try {
			$this->start();
		} catch (\Exception $e) {
			if ($this->startFailure === null) {
				$this->startFailure = $e->getMessage();
			}
			// Eine nur belegte Sitzung ist nicht kaputt: das Cookie nicht verwerfen.
			// Ob es mit ihr weitergeht, entscheidet shouldReplaceLockedSession().
			if (!$this->lockFailed) {
				$this->deleteSessionCookie();
			}
		}
		\restore_error_handler();
		$this->readOnly = $this->lockFailed && \session_status() === PHP_SESSION_ACTIVE;
	}

	/**
	 * Nach einem Sperrfehler: neue Sitzung statt der belegten?
	 *
	 * Mit der belegten geht es nur weiter, wenn sie lesbar ist (phpredis 5.3 und
	 * ab 6.3 im Lesemodus), etwas enthält und der Client sich nicht ohnehin mit
	 * jeder Anfrage selbst anmeldet: So bleibt etwa ein angemeldeter Browser
	 * angemeldet, solange die Sperre nur kurz belegt ist. Sonst bringt die alte
	 * Sitzung nichts, und mit ihrem Cookie wartete jede weitere Anfrage wieder die
	 * volle Zeit – bei einer verwaisten Sperre (PHP-Prozess beendet, ohne sie
	 * freizugeben) bis redis.session.lock_expire. Eine Anmeldung fände zudem für
	 * ihr Formular-Token keinen Platz.
	 */
	private function shouldReplaceLockedSession(): bool {
		return !$this->readOnly || empty($_SESSION) || self::sendsAuthorization();
	}

	/**
	 * Meldet sich der Client mit jeder Anfrage selbst an (Authorization-Kopf, etwa
	 * Sync-Clients und API-Aufrufe)? Basic steht nach OC::handleAuthHeaders() bzw.
	 * unter mod_php auch in PHP_AUTH_USER.
	 */
	private static function sendsAuthorization(): bool {
		return !empty($_SERVER['HTTP_AUTHORIZATION'])
			|| !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
			|| isset($_SERVER['PHP_AUTH_USER']);
	}

	/**
	 * Beginnt nach einem Sperrfehler eine neue, leere Sitzung mit eigener Kennung
	 * und eigener Sperre; PHP schickt dem Client dafür ein neues Cookie. Die
	 * belegte Sitzung bleibt unberührt, parallele Anfragen mit dem alten Cookie
	 * arbeiten mit ihr weiter. Klappt der Wechsel nicht, bleibt es beim Stand nach
	 * dem Sperrfehler bzw. nach dem neuen Fehler.
	 */
	private function replaceLockedSession(): void {
		if (\headers_sent()) {
			return;
		}
		if (\session_status() === PHP_SESSION_ACTIVE) {
			// nur lesend offen: nichts zurückschreiben
			\session_abort();
		}
		$newId = \session_create_id();
		if ($newId === false) {
			return;
		}
		$lockFailure = $this->startFailure;
		\session_id($newId);
		$this->startSession();
		if ($this->startFailure === null) {
			$this->startFailure = $lockFailure;
			$this->replacedLockedSession = true;
		}
	}

	/**
	 * Lässt den Browser das Sitzungs-Cookie verwerfen.
	 */
	protected function deleteSessionCookie(): void {
		\setcookie(\session_name(), '', -1, \OC::$WEBROOT ? : '/');
	}

	/**
	 * @throws \Exception
	 */
	private function validateSession(): void {
		if ($this->sessionClosed) {
			throw new SessionNotAvailableException('Session has been closed - no further changes to the session are allowed');
		}
	}

	private function start() {
		if (@\session_id() === '') {
			// prevents javascript from accessing php session cookies
			\ini_set('session.cookie_httponly', true);

			// set the cookie path to the ownCloud directory
			$cookie_path = \OC::$WEBROOT ? : '/';
			\ini_set('session.cookie_path', $cookie_path);

			if ($this->getServerProtocol() === 'https') {
				\ini_set('session.cookie_secure', true);
			}

			//try to set the session lifetime
			$sessionLifeTime = self::getSessionLifeTime();
			@\ini_set('session.gc_maxlifetime', (string)$sessionLifeTime);
		}
		if (\ini_get('session.save_handler') === 'redis') {
			// phpredis ab 6.3.0: Wird die Sitzungssperre nicht rechtzeitig frei, die
			// Sitzung nur lesend öffnen statt gar nicht. Ältere Versionen kennen den
			// Schalter nicht; ein per php_admin_value gesetzter Wert hat Vorrang.
			@\ini_set('redis.session.lock_failure_readonly', '1');
		}
		\session_start();
	}

	private static function getSessionLifeTime(): string {
		return \OC::$server->getConfig()->getSystemValue('session_lifetime', 60 * 20);
	}

	private function getServerProtocol(): string {
		$method = $_SERVER['REQUEST_METHOD'] ?? null;
		$req = new Request(
			[
				'get' => $_GET,
				'post' => $_POST,
				'files' => $_FILES,
				'server' => $_SERVER,
				'env' => $_ENV,
				'cookies' => $_COOKIE,
				'method' => $method,
				'urlParams' => [],
			],
			null,
			\OC::$server->getConfig(),
			null
		);

		return $req->getServerProtocol();
	}
}
