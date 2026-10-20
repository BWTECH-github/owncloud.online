<?php
/**
 * @author Christoph Wurst <christoph@owncloud.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
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

use OCP\ISession;
use OCP\Security\ICrypto;
use OCP\Session\Exceptions\SessionNotAvailableException;

/**
 * Class CryptoSessionData
 *
 * @package OC\Session
 */
class CryptoSessionData implements \ArrayAccess, ISession {
	/** @var ISession */
	protected $session;
	/** @var \OCP\Security\ICrypto */
	protected $crypto;
	/** @var string */
	protected $passphrase;
	/** @var array */
	protected $sessionValues;
	/** @var bool */
	protected $isModified = false;
	/** @var array|null Werte beim vorzeitigen Freigeben der Sperre (releaseLock()), sonst null */
	protected $valuesAtRelease = null;
	public const encryptedSessionName = 'encrypted_session_data';

	/**
	 * @param ISession $session
	 * @param ICrypto $crypto
	 * @param string $passphrase
	 */
	public function __construct(
		ISession $session,
		ICrypto $crypto,
		$passphrase
	) {
		$this->crypto = $crypto;
		$this->session = $session;
		$this->passphrase = $passphrase;
		$this->initializeSession();
	}

	/**
	 * Close session if class gets destructed
	 */
	public function __destruct() {
		try {
			$this->close();
		} catch (SessionNotAvailableException $e) {
			// This exception can occur if session is already closed
			// So it is safe to ignore it and let the garbage collector to proceed
		}
	}

	protected function initializeSession() {
		$encryptedSessionData = $this->session->get(self::encryptedSessionName);
		try {
			$this->sessionValues = \json_decode(
				$this->crypto->decrypt($encryptedSessionData, $this->passphrase),
				true
			);
		} catch (\Exception $e) {
			$this->sessionValues = [];
		}
	}

	/**
	 * Set a value in the session
	 *
	 * @param string $key
	 * @param mixed $value
	 */
	public function set($key, $value) {
		$this->sessionValues[$key] = $value;
		$this->isModified = true;
	}

	/**
	 * Get a value from the session
	 *
	 * @param string $key
	 * @return string|null Either the value or null
	 */
	public function get($key) {
		if (isset($this->sessionValues[$key])) {
			return $this->sessionValues[$key];
		}

		return null;
	}

	/**
	 * Check if a named key exists in the session
	 *
	 * @param string $key
	 * @return bool
	 */
	public function exists($key): bool {
		return isset($this->sessionValues[$key]);
	}

	/**
	 * Remove a $key/$value pair from the session
	 *
	 * @param string $key
	 */
	public function remove($key) {
		$this->isModified = true;
		unset($this->sessionValues[$key]);
		$this->session->remove(self::encryptedSessionName);
	}

	/**
	 * Reset and recreate the session
	 */
	public function clear() {
		$this->sessionValues = [];
		$this->isModified = true;
		// Leeren ersetzt alles, es gibt nichts zusammenzuführen; die Sperre holt
		// sich Internal::clear() dafür selbst zurück.
		$this->valuesAtRelease = null;
		$this->session->clear();
	}

	/**
	 * Wrapper around session_regenerate_id
	 *
	 * @param bool $deleteOldSession Whether to delete the old associated session file or not.
	 * @return void
	 */
	public function regenerateId($deleteOldSession = true) {
		if ($this->valuesAtRelease !== null) {
			// Die neue Kennung soll den aktuellen Stand tragen, nicht den beim Freigeben.
			$this->mergeChangesSinceRelease(true);
		}
		$this->session->regenerateId($deleteOldSession);
	}

	/**
	 * Wrapper around session_id
	 *
	 * @return string
	 * @throws SessionNotAvailableException
	 * @since 9.1.0
	 */
	public function getId() {
		return $this->session->getId();
	}

	/**
	 * Close the session and release the lock, also writes all changed data in batch
	 */
	public function close(): void {
		if ($this->valuesAtRelease !== null) {
			$this->mergeChangesSinceRelease(false);
		}
		$this->writeValues();
		$this->session->close();
	}

	/**
	 * Schreibt die Sitzung und gibt ihre Sperre schon vor close() frei.
	 *
	 * Für Anfragen, die die Sitzung danach nur noch lesen: Parallele Anfragen
	 * derselben Sitzung warten dann nicht mehr, bis diese hier fertig ist. Ändert
	 * die Anfrage doch noch etwas, holt close() die Sperre zurück, liest die
	 * Sitzung neu und trägt nur diese Änderungen ein – was andere Anfragen
	 * inzwischen gespeichert haben, bleibt erhalten.
	 *
	 * @return bool false, wenn die Sitzung darunter das nicht kann
	 */
	public function releaseLock(): bool {
		if (!($this->session instanceof Internal)) {
			return false;
		}
		$this->writeValues();
		if (!$this->session->releaseLock()) {
			return false;
		}
		$this->valuesAtRelease = (array)$this->sessionValues;
		return true;
	}

	private function writeValues(): void {
		if ($this->isModified) {
			$encryptedValue = $this->crypto->encrypt(\json_encode($this->sessionValues), $this->passphrase);
			$this->session->set(self::encryptedSessionName, $encryptedValue);
			$this->isModified = false;
		}
	}

	/**
	 * Nach releaseLock(): Was hat die Anfrage seitdem geändert? Gibt es etwas
	 * (oder verlangt $force den frischen Stand), die Sperre zurückholen, die
	 * Sitzung neu lesen und nur diese Änderungen darauf anwenden. Zwischenwerte,
	 * die bis hierher wieder zurückgesetzt wurden, zählen nicht.
	 *
	 * @param bool $force auch ohne Änderungen frisch lesen
	 */
	private function mergeChangesSinceRelease(bool $force): void {
		$valuesAtRelease = $this->valuesAtRelease;
		$this->valuesAtRelease = null;
		$values = (array)$this->sessionValues;
		$changed = [];
		foreach ($values as $key => $value) {
			if (!\array_key_exists($key, $valuesAtRelease) || $valuesAtRelease[$key] !== $value) {
				$changed[$key] = $value;
			}
		}
		$removed = \array_keys(\array_diff_key($valuesAtRelease, $values));
		$this->isModified = false;
		if (!$force && $changed === [] && $removed === []) {
			return;
		}
		$reacquired = $this->session instanceof Internal && $this->session->reacquireLock();
		if (!$reacquired) {
			if ($changed !== [] || $removed !== []) {
				\OC::$server->getLogger()->warning(
					'Session lock could not be reacquired, changes to the session were not saved (keys: {keys})',
					['app' => 'core', 'keys' => \implode(', ', \array_merge(\array_keys($changed), $removed))]
				);
			}
			return;
		}
		$this->initializeSession();
		foreach ($changed as $key => $value) {
			$this->sessionValues[$key] = $value;
		}
		foreach ($removed as $key) {
			unset($this->sessionValues[$key]);
		}
		$this->isModified = $changed !== [] || $removed !== [];
	}

	/**
	 * @param mixed $offset
	 * @return bool
	 */
	public function offsetExists($offset): bool {
		return $this->exists($offset);
	}

	/**
	 * @param mixed $offset
	 * @return mixed
	 */
	public function offsetGet($offset): mixed {
		return $this->get($offset);
	}

	/**
	 * @param mixed $offset
	 * @param mixed $value
	 */
	public function offsetSet($offset, $value): void {
		$this->set($offset, $value);
	}

	/**
	 * @param mixed $offset
	 */
	public function offsetUnset($offset): void {
		$this->remove($offset);
	}
}
