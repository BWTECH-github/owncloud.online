<?php
/**
 * @author Roeland Jago Douma <rullzer@owncloud.com>
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
 * Modified by BW-Tech GmbH on 2026-08-06.
 * Changes:
 *   - make the chunk cache mock store bytes and pin the encryption help link
 *   - enforce write hooks and serialise chunk accounting on chunked uploads
 */
namespace Test;

class FileChunkingTest extends \Test\TestCase {
	public function dataIsComplete() {
		return [
			[1, [], false],
			[1, [0], true],
			[2, [], false],
			[2, [0], false],
			[2, [1], false],
			[2, [0,1], true],
			[10, [], false],
			[10, [0,1,2,3,4,5,6,7,8], false],
			[10, [1,2,3,4,5,6,7,8,9], false],
			[10, [0,1,2,3,5,6,7,8,9], false],
			[10, [0,1,2,3,4,5,6,7,8,9], true],
		];
	}

	/**
	 * @dataProvider dataIsComplete
	 * @param $total
	 * @param array $present
	 * @param $expected
	 */
	public function testIsComplete($total, array $present, $expected) {
		$fileChunking = $this->getMockBuilder('\OC_FileChunking')
			->setMethods(['getCache'])
			->setConstructorArgs([[
				'name' => 'file',
				'transferid' => '42',
				'chunkcount' => $total,
			]])
			->getMock();

		$cache = $this->createMock('\OCP\ICache');

		$cache->expects($this->atLeastOnce())
			->method('hasKey')
			->will($this->returnCallback(function ($key) use ($present) {
				$data = \explode('-', $key);
				return \in_array($data[3], $present);
			}));

		$fileChunking->method('getCache')->willReturn($cache);

		$this->assertEquals($expected, $fileChunking->isComplete());
	}

	/**
	 * @param array $store in-memory backing store, by reference
	 * @return \OC\Cache\File|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function getInMemoryCache(array &$store) {
		// the real chunk cache is OC\Cache\File - ICache has no size()
		$cache = $this->createMock('\OC\Cache\File');
		$cache->method('get')->willReturnCallback(function ($key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function ($key, $value) use (&$store) {
			// OC\Cache\File streams a resource into its backing file, so the stored
			// value is always bytes - the mock has to do the same, otherwise size()
			// sees a resource and reports 0 for every chunk.
			if (\is_resource($value)) {
				$value = \stream_get_contents($value);
			}
			$store[$key] = (string)$value;
			return true;
		});
		$cache->method('size')->willReturnCallback(function ($key) use (&$store) {
			return isset($store[$key]) && \is_string($store[$key]) ? \strlen($store[$key]) : 0;
		});
		$cache->method('hasKey')->willReturnCallback(function ($key) use (&$store) {
			return isset($store[$key]);
		});
		$cache->method('remove')->willReturnCallback(function ($key) use (&$store) {
			unset($store[$key]);
			return true;
		});
		return $cache;
	}

	/**
	 * @param array $store
	 * @return \OC_FileChunking|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function getChunkingWithCache(array &$store) {
		$fileChunking = $this->getMockBuilder('\OC_FileChunking')
			->setMethods(['getCache'])
			->setConstructorArgs([[
				'name' => 'file',
				'transferid' => '42',
				'chunkcount' => 3,
			]])
			->getMock();
		$fileChunking->method('getCache')->willReturn($this->getInMemoryCache($store));
		return $fileChunking;
	}

	private function chunkStream($bytes) {
		$fh = \fopen('php://temp', 'r+');
		\fwrite($fh, \str_repeat('x', $bytes));
		\rewind($fh);
		return $fh;
	}

	/**
	 * QuotaPlugin consults getCurrentSize() on every chunk PUT, so a subtotal
	 * that drifts low silently raises the effective quota.
	 */
	public function testCurrentSizeTracksStoredChunks() {
		$store = [];
		$fileChunking = $this->getChunkingWithCache($store);

		$fileChunking->store('0', $this->chunkStream(1000));
		$this->assertEquals(1000, $fileChunking->getCurrentSize());

		$fileChunking->store('1', $this->chunkStream(1500));
		$this->assertEquals(2500, $fileChunking->getCurrentSize());
	}

	/**
	 * A retried chunk replaces its predecessor, so its bytes must not be counted
	 * twice - otherwise the upload fails against the quota although it did not grow.
	 */
	public function testRetriedChunkIsNotCountedTwice() {
		$store = [];
		$fileChunking = $this->getChunkingWithCache($store);

		$fileChunking->store('0', $this->chunkStream(1000));
		$fileChunking->store('1', $this->chunkStream(1000));
		$this->assertEquals(2000, $fileChunking->getCurrentSize());

		$fileChunking->store('1', $this->chunkStream(1000));
		$this->assertEquals(2000, $fileChunking->getCurrentSize(), 'a retry of the same chunk must not add up');
	}

	/**
	 * Whenever the subtotal is missing - it is dropped rather than written
	 * unsynchronised when concurrent chunks of the same transfer collide -
	 * getCurrentSize() has to recount and arrive at the same number.
	 */
	public function testRecountWithoutSubtotalMatches() {
		$store = [];
		$fileChunking = $this->getChunkingWithCache($store);

		$fileChunking->store('0', $this->chunkStream(1000));
		$fileChunking->store('1', $this->chunkStream(1000));

		foreach (\array_keys($store) as $key) {
			if (\substr($key, -4) === 'size') {
				unset($store[$key]);
			}
		}

		$this->assertEquals(2000, $fileChunking->getCurrentSize(), 'the recount must match the stored chunks');
	}
}
