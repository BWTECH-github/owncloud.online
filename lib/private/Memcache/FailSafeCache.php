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

namespace OC\Memcache;

use OCP\ILogger;
use OCP\IMemcache;
use OCP\Util;

/**
 * Hülle um einen Cache-Dienst im Netz (von der Factory für Redis verwendet) in
 * der Rolle eines reinen Zwischenspeichers (memcache.local, memcache.distributed).
 *
 * Ein Zwischenspeicher beschleunigt nur: ohne ihn liefert jede Anfrage dasselbe
 * Ergebnis aus Datenbank und Dateisystem. War der Dienst gestört (Neustart,
 * Netzwerk, Redis an maxclients), warf bisher schon der erste Zugriff beim
 * Hochfahren einer Anfrage eine Ausnahme – WebDAV antwortete dann mit
 * „503 Internal Server Error“, auch wenn die Anfrage den Cache gar nicht
 * gebraucht hätte. Die Hülle behandelt einen Ausfall wie einen leeren Cache:
 * Lesen liefert nichts, Schreiben wird verworfen, und der Grund steht einmal je
 * Anfrage im Protokoll.
 *
 * Nach dem ersten Fehler bleibt der Dienst für den Rest der Anfrage (bzw. des
 * occ- oder Cron-Laufs) außen vor, für alle Hüllen gemeinsam. Das erspart weitere
 * Wartezeiten auf einen gestörten Dienst und verhindert, dass eine Anfrage teils
 * zwischengespeicherte, teils frische Daten mischt. Die nächste Anfrage versucht
 * es wieder.
 *
 * Für Sperren (memcache.locking) wird die Hülle bewusst nicht verwendet: eine
 * Sperre, die stillschweigend nicht gesetzt wird, gefährdet Daten. Ebenso fängt
 * sie nur Ausnahmen, keine \Error – ein Programmierfehler soll sichtbar bleiben.
 *
 * @package OC\Memcache
 */
class FailSafeCache extends Cache implements IMemcache {
	/**
	 * Ausfall in dieser Anfrage schon festgestellt – gilt für alle Hüllen, weil
	 * alle denselben Dienst ansprechen.
	 *
	 * @var bool
	 */
	private static $unavailable = false;

	/** @var callable */
	private $factory;

	/** @var IMemcache|null */
	private $cache = null;

	/** @var ILogger */
	private $logger;

	/**
	 * @param callable $factory liefert den eigentlichen Cache (IMemcache); erst beim
	 *                          ersten Zugriff aufgerufen, weil schon der Aufbau die
	 *                          Verbindung herstellt und scheitern kann
	 * @param ILogger $logger
	 * @param string $prefix derselbe Präfix wie der des eigentlichen Caches
	 */
	public function __construct(callable $factory, ILogger $logger, $prefix = '') {
		parent::__construct($prefix);
		$this->factory = $factory;
		$this->logger = $logger;
	}

	/**
	 * Nur für Tests: den in dieser Anfrage festgestellten Ausfall vergessen.
	 */
	public static function resetForTesting() {
		self::$unavailable = false;
	}

	/**
	 * Führt eine Operation auf dem eigentlichen Cache aus und liefert bei einem
	 * Ausfall $fallback – das Ergebnis, das ein leerer Cache liefern würde.
	 *
	 * @param callable $operation
	 * @param mixed $fallback
	 * @return mixed
	 */
	private function attempt(callable $operation, $fallback) {
		if (self::$unavailable) {
			return $fallback;
		}
		try {
			if ($this->cache === null) {
				$this->cache = \call_user_func($this->factory);
			}
			return $operation($this->cache);
		} catch (\Exception $e) {
			// zuerst merken, dann protokollieren: greift das Protokollieren selbst
			// auf einen Cache zu, bekommt es den Leerwert statt einer Schleife
			self::$unavailable = true;
			$this->logFailure($e);
			return $fallback;
		}
	}

	/**
	 * @param \Exception $e
	 */
	private function logFailure(\Exception $e) {
		try {
			$this->logger->logException($e, [
				'app' => 'core',
				'message' => 'Memory cache unavailable, continuing without it for this request',
				'level' => Util::ERROR,
			]);
		} catch (\Throwable) {
			// Protokollieren darf die Anfrage ebenso wenig abbrechen wie der Cache
		}
	}

	public function get($key) {
		return $this->attempt(static function (IMemcache $cache) use ($key) {
			return $cache->get($key);
		}, null);
	}

	public function set($key, $value, $ttl = 0) {
		return $this->attempt(static function (IMemcache $cache) use ($key, $value, $ttl) {
			return $cache->set($key, $value, $ttl);
		}, false);
	}

	public function hasKey($key) {
		return $this->attempt(static function (IMemcache $cache) use ($key) {
			return $cache->hasKey($key);
		}, false);
	}

	public function remove($key) {
		return $this->attempt(static function (IMemcache $cache) use ($key) {
			return $cache->remove($key);
		}, false);
	}

	public function clear($prefix = '') {
		return $this->attempt(static function (IMemcache $cache) use ($prefix) {
			return $cache->clear($prefix);
		}, false);
	}

	public function add($key, $value, $ttl = 0) {
		return $this->attempt(static function (IMemcache $cache) use ($key, $value, $ttl) {
			return $cache->add($key, $value, $ttl);
		}, false);
	}

	public function inc($key, $step = 1) {
		return $this->attempt(static function (IMemcache $cache) use ($key, $step) {
			return $cache->inc($key, $step);
		}, false);
	}

	public function dec($key, $step = 1) {
		return $this->attempt(static function (IMemcache $cache) use ($key, $step) {
			return $cache->dec($key, $step);
		}, false);
	}

	public function cas($key, $old, $new) {
		return $this->attempt(static function (IMemcache $cache) use ($key, $old, $new) {
			return $cache->cas($key, $old, $new);
		}, false);
	}

	public function cad($key, $old) {
		return $this->attempt(static function (IMemcache $cache) use ($key, $old) {
			return $cache->cad($key, $old);
		}, false);
	}
}
