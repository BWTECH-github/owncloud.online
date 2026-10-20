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

/**
 * Sitzungsspeicher im Speicher. $failure stellt nach, was phpredis meldet, wenn
 * die Sitzungssperre nicht frei wird ('locked', 'readonly') oder Redis nicht
 * antwortet ('broken'). Mit $failingId trifft das nur diese Kennung – so wie
 * eine belegte Sperre nur ihre Sitzung betrifft, nicht eine neue.
 */
class FakeSessionSaveHandler implements \SessionHandlerInterface {
	/** @var array<string, string> Kennung => serialisierte Sitzung */
	public $store = [];
	/** @var string|null */
	public $failure = null;
	/** @var string|null nur diese Kennung scheitert, null: jede */
	public $failingId = null;
	/** @var int */
	public $reads = 0;
	/** @var int */
	public $writes = 0;
	/** @var string[] */
	public $destroyed = [];

	public function open(string $path, string $name): bool {
		return true;
	}

	public function close(): bool {
		return true;
	}

	public function read(string $id): string|false {
		$this->reads++;
		if ($this->failingId !== null && $this->failingId !== $id) {
			return $this->store[$id] ?? '';
		}
		if ($this->failure === 'locked') {
			\trigger_error('session_start(): Failed to acquire session lock', E_USER_WARNING);
			return false;
		}
		if ($this->failure === 'readonly') {
			\trigger_error('session_start(): Failed to acquire session lock, session will be read only', E_USER_WARNING);
		}
		if ($this->failure === 'broken') {
			\trigger_error('session_start(): Failed to read session data: redis (path: tcp://127.0.0.1:6379?auth=geheim)', E_USER_WARNING);
			return false;
		}
		return $this->store[$id] ?? '';
	}

	public function write(string $id, string $data): bool {
		$this->writes++;
		$this->store[$id] = $data;
		return true;
	}

	public function destroy(string $id): bool {
		$this->destroyed[] = $id;
		unset($this->store[$id]);
		return true;
	}

	public function gc(int $max_lifetime): int|false {
		return 0;
	}
}
