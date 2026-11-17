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
		$cache->method('getNumberedKeys')->willReturnCallback(function ($prefix) use (&$store) {
			$result = [];
			foreach ($store as $key => $value) {
				$number = \substr($key, \strlen($prefix));
				if (\strpos($key, $prefix) === 0 && \preg_match('/^(0|[1-9][0-9]*)$/', $number) === 1) {
					$result[(int)$number] = \strlen($value);
				}
			}
			return $result;
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

	public function dataDecodeName() {
		return [
			['file.txt-chunking-42-3-0', true],
			['file.txt-chunking-42-3-2', true],
			['file.txt-chunking-42-0003-2', true],
			['file.txt-chunking-42-100000-99999', true],
			['file.txt-chunking-42-3-3', false],
			['file.txt-chunking-42-3-7', false],
			['file.txt-chunking-42-0-0', false],
			['file.txt-chunking-42-100001-0', false],
			['file.txt-chunking-42-99999999999999999999999-0', false],
			['file.txt-chunking-42-3-99999999999999999999999', false],
			['file.txt', false],
		];
	}

	/**
	 * chunkcount comes from the URL - also without login through public folder
	 * links - and must stay within sane bounds.
	 *
	 * @dataProvider dataDecodeName
	 */
	public function testDecodeNameAcceptsOnlyValidChunkNames($name, $valid) {
		$info = \OC_FileChunking::decodeName($name);
		if ($valid) {
			$this->assertSame('file.txt', $info['name']);
			$this->assertSame('42', $info['transferid']);
		} else {
			$this->assertSame([], $info);
		}
	}

	/**
	 * @param int $chunkCount
	 * @param \OC\Cache\File $cache
	 * @return \OC_FileChunking|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function getChunkingWithCount($chunkCount, $cache) {
		$fileChunking = $this->getMockBuilder('\OC_FileChunking')
			->setMethods(['getCache'])
			->setConstructorArgs([[
				'name' => 'file',
				'transferid' => '42',
				'chunkcount' => $chunkCount,
			]])
			->getMock();
		$fileChunking->method('getCache')->willReturn($cache);
		return $fileChunking;
	}

	/**
	 * The recount must look at the stored chunks only, not probe every index up
	 * to chunkcount - that loop could be stretched at will by the caller.
	 */
	public function testCurrentSizeDoesNotProbeEveryIndex() {
		$cache = $this->createMock('\OC\Cache\File');
		$cache->method('get')->willReturn(null);
		$cache->method('set')->willReturn(true);
		$cache->expects($this->never())->method('size');
		$cache->expects($this->never())->method('hasKey');
		$cache->expects($this->once())->method('getNumberedKeys')
			->with('file-chunking-42-')
			->willReturn([0 => 1000, 5 => 2000]);

		$fileChunking = $this->getChunkingWithCount(\OC_FileChunking::MAX_CHUNK_COUNT, $cache);
		$this->assertEquals(3000, $fileChunking->getCurrentSize());
	}

	/**
	 * Leftovers of an earlier transfer with the same prefix but more chunks do
	 * not count, just like the per-index loop did not see them.
	 */
	public function testCurrentSizeIgnoresIndicesBeyondChunkCount() {
		$cache = $this->createMock('\OC\Cache\File');
		$cache->method('get')->willReturn(null);
		$cache->method('set')->willReturn(true);
		$cache->method('getNumberedKeys')->willReturn([0 => 10, 1 => 10, 7 => 99]);

		$fileChunking = $this->getChunkingWithCount(3, $cache);
		$this->assertEquals(20, $fileChunking->getCurrentSize());
	}

	public function testCleanupRemovesTheStoredChunksAndTheSubtotal() {
		$removed = [];
		$cache = $this->createMock('\OC\Cache\File');
		$cache->method('getNumberedKeys')->willReturn([0 => 1, 2 => 1]);
		$cache->method('remove')->willReturnCallback(function ($key) use (&$removed) {
			$removed[] = $key;
			return true;
		});

		$fileChunking = $this->getChunkingWithCount(\OC_FileChunking::MAX_CHUNK_COUNT, $cache);
		$fileChunking->cleanup();
		$this->assertSame(['file-chunking-42-size', 'file-chunking-42-0', 'file-chunking-42-2'], $removed);
	}
}
