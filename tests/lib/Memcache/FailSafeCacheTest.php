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

namespace Test\Memcache;

use OC\Memcache\ArrayCache;
use OC\Memcache\FailSafeCache;
use OCP\ILogger;
use OCP\IMemcache;

/**
 * Läuft der Dienst, verhält sich die Hülle wie der Cache darin (die geerbten
 * Tests aus Test\Memcache\Cache); fällt er aus, wie ein leerer Cache.
 */
class FailSafeCacheTest extends Cache {
	/** @var ILogger|\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		FailSafeCache::resetForTesting();
		$this->logger = $this->createMock(ILogger::class);
		$this->instance = new FailSafeCache(static function () {
			return new ArrayCache('');
		}, $this->logger);
	}

	protected function tearDown(): void {
		FailSafeCache::resetForTesting();
		parent::tearDown();
	}

	public function testUnreachableBackendBehavesLikeAnEmptyCache() {
		$connects = 0;
		$cache = new FailSafeCache(static function () use (&$connects) {
			$connects++;
			throw new \RuntimeException('Connection refused');
		}, $this->logger, 'prefix/');
		$this->logger->expects($this->once())->method('logException');

		$results = [
			'get' => $cache->get('a'),
			'hasKey' => $cache->hasKey('a'),
			'set' => $cache->set('a', 1),
			'add' => $cache->add('a', 1),
			'inc' => $cache->inc('a'),
			'dec' => $cache->dec('a'),
			'cas' => $cache->cas('a', 1, 2),
			'cad' => $cache->cad('a', 1),
			'remove' => $cache->remove('a'),
			'clear' => $cache->clear(),
			'offsetGet' => $cache['a'],
		];

		$this->assertSame([
			'get' => null,
			'hasKey' => false,
			'set' => false,
			'add' => false,
			'inc' => false,
			'dec' => false,
			'cas' => false,
			'cad' => false,
			'remove' => false,
			'clear' => false,
			'offsetGet' => null,
		], $results);
		$this->assertSame(1, $connects, 'nach dem ersten Fehler kein neuer Verbindungsversuch in derselben Anfrage');
		$this->assertSame('prefix/', $cache->getPrefix());
	}

	public function testFailureMidRequestKeepsAllCachesAwayFromTheBackend() {
		$broken = $this->createMock(IMemcache::class);
		$broken->expects($this->once())->method('get')->willThrowException(new \RuntimeException('read error on connection'));
		$broken->expects($this->never())->method('set');
		$first = new FailSafeCache(static function () use ($broken) {
			return $broken;
		}, $this->logger);
		$secondConnects = 0;
		$second = new FailSafeCache(static function () use (&$secondConnects) {
			$secondConnects++;
			return new ArrayCache('');
		}, $this->logger);

		$this->assertNull($first->get('a'));
		$this->assertFalse($first->set('a', 1));
		$this->assertNull($second->get('a'));
		$this->assertSame(0, $secondConnects, 'kein zweiter Zugriff auf den gestörten Dienst');
	}

	public function testBackendIsUsedAgainInTheNextRequest() {
		$cache = new FailSafeCache(static function () {
			throw new \RuntimeException('Connection refused');
		}, $this->logger);
		$cache->get('a');

		FailSafeCache::resetForTesting();
		$next = new FailSafeCache(static function () {
			$inner = new ArrayCache('');
			$inner->set('a', 'wert');
			return $inner;
		}, $this->logger);

		$this->assertSame('wert', $next->get('a'));
	}

	public function testProgrammingErrorsAreNotHidden() {
		$cache = new FailSafeCache(static function () {
			throw new \TypeError('Programmierfehler');
		}, $this->logger);

		$this->expectException(\TypeError::class);
		$cache->get('a');
	}

	public function testFailingLoggerDoesNotBreakTheRequest() {
		$this->logger->method('logException')->willThrowException(new \RuntimeException('Protokoll nicht schreibbar'));
		$cache = new FailSafeCache(static function () {
			throw new \RuntimeException('Connection refused');
		}, $this->logger);

		$this->assertNull($cache->get('a'));
	}
}
