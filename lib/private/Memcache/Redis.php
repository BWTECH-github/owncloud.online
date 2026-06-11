<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Stefan Weil <sw@weilnetz.de>
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
 */

namespace OC\Memcache;

use OCP\IMemcacheTTL;

class Redis extends Cache implements IMemcacheTTL {
	/** @var \Redis | \RedisCluster $cache */
	private static $cache = null;

	public function __construct($prefix = '') {
		parent::__construct($prefix);
		if (self::$cache === null) {
			self::$cache = \OC::$server->getGetRedisFactory()->getInstance();
		}
	}

	/**
	 * entries in redis get namespaced to prevent collisions between ownCloud instances and users
	 */
	protected function getNameSpace() {
		return $this->prefix;
	}

	public function get($key) {
		$result = self::$cache->get($this->getNameSpace() . $key);
		if ($result === false) {
			// phpredis liefert false nur bei fehlendem Key; Werte sind immer
			// JSON-Strings. Der frühere exists()-Roundtrip lieferte im
			// false-Fall ebenfalls null (json_decode(false) === null).
			return null;
		}
		return \json_decode($result, true);
	}

	public function set($key, $value, $ttl = 0) {
		if ($ttl > 0) {
			return self::$cache->setex($this->getNameSpace() . $key, $ttl, \json_encode($value));
		} else {
			return self::$cache->set($this->getNameSpace() . $key, \json_encode($value));
		}
	}

	public function hasKey($key) {
		// phpredis >= 4 liefert int statt bool — Interface verlangt bool
		return (bool)self::$cache->exists($this->getNameSpace() . $key);
	}

	public function remove($key) {
		if (self::$cache->del($this->getNameSpace() . $key)) {
			return true;
		} else {
			return false;
		}
	}

	public function clear($prefix = '') {
		$prefix = $this->getNameSpace() . $prefix . '*';
		$it = null;
		self::$cache->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
		while ($keys = self::$cache->scan($it, $prefix)) {
			self::$cache->del($keys);
		}
		return true;
	}

	/**
	 * Set a value in the cache if it's not already stored
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param int $ttl Time To Live in seconds. Defaults to 60*60*24
	 * @return bool
	 */
	public function add($key, $value, $ttl = 0) {
		// don't encode ints for inc/dec
		if (!\is_int($value)) {
			$value = \json_encode($value);
		}
		return self::$cache->setnx($this->getPrefix() . $key, $value);
	}

	/**
	 * Increase a stored number
	 *
	 * @param string $key
	 * @param int $step
	 * @return int | bool
	 */
	public function inc($key, $step = 1) {
		return self::$cache->incrBy($this->getNameSpace() . $key, $step);
	}

	/**
	 * Kodiert einen Wert exakt so, wie set()/add()/inc() ihn in Redis ablegen:
	 * Integer roh, alles andere als JSON. Nötig für atomare Lua-Vergleiche.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function encodeStoredValue($value) {
		return \is_int($value) ? (string)$value : \json_encode($value);
	}

	/**
	 * Decrease a stored number
	 *
	 * @param string $key
	 * @param int $step
	 * @return int | bool
	 */
	public function dec($key, $step = 1) {
		// atomar in einem Roundtrip statt EXISTS + DECRBY
		$lua = 'if redis.call("EXISTS", KEYS[1]) == 1 then '
			. 'return redis.call("DECRBY", KEYS[1], ARGV[1]) end '
			. 'return false';
		return self::$cache->eval($lua, [$this->getNameSpace() . $key, $step], 1);
	}

	/**
	 * Compare and set
	 *
	 * @param string $key
	 * @param mixed $old
	 * @param mixed $new
	 * @return bool
	 */
	public function cas($key, $old, $new) {
		if (!\is_int($new)) {
			$new = \json_encode($new);
		}
		// atomar per Lua in einem Roundtrip statt WATCH/GET/MULTI/SET/EXEC
		$lua = 'if redis.call("GET", KEYS[1]) == ARGV[1] then '
			. 'redis.call("SET", KEYS[1], ARGV[2]) return 1 end '
			. 'return 0';
		$result = self::$cache->eval(
			$lua,
			[$this->getNameSpace() . $key, self::encodeStoredValue($old), $new],
			1
		);
		return (bool)$result;
	}

	/**
	 * Compare and delete
	 *
	 * @param string $key
	 * @param mixed $old
	 * @return bool
	 */
	public function cad($key, $old) {
		// atomar per Lua in einem Roundtrip statt WATCH/GET/MULTI/DEL/EXEC
		$lua = 'if redis.call("GET", KEYS[1]) == ARGV[1] then '
			. 'redis.call("DEL", KEYS[1]) return 1 end '
			. 'return 0';
		$result = self::$cache->eval(
			$lua,
			[$this->getNameSpace() . $key, self::encodeStoredValue($old)],
			1
		);
		return (bool)$result;
	}

	public function setTTL($key, $ttl) {
		self::$cache->expire($this->getNameSpace() . $key, $ttl);
	}

	public static function isAvailable() {
		return \OC::$server->getGetRedisFactory()->isAvailable();
	}
}
