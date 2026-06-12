<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
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

namespace OC\Files\Cache;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\IPropagator;
use OCP\IDBConnection;

/**
 * Propagate etags and mtimes within the storage
 */
class Propagator implements IPropagator {
	private $inBatch = false;

	private $batch = [];

	/**
	 * @var \OC\Files\Storage\Storage
	 */
	protected $storage;

	/**
	 * @var IDBConnection
	 */
	private $connection;

	/**
	 * @param \OC\Files\Storage\Storage $storage
	 * @param IDBConnection $connection
	 */
	public function __construct(\OC\Files\Storage\Storage $storage, IDBConnection $connection) {
		$this->storage = $storage;
		$this->connection = $connection;
	}

	/**
	 * @param string $internalPath
	 * @param int $time
	 * @param int $sizeDifference number of bytes the file has grown
	 */
	public function propagateChange($internalPath, $time, $sizeDifference = 0) {
		$storageId = (int)$this->storage->getStorageCache()->getNumericId();

		$parents = $this->getParents($internalPath);

		if (\count($parents) === 0) {
			return;
		}

		if ($this->inBatch) {
			foreach ($parents as $parent) {
				$this->addToBatch($parent, $time, $sizeDifference);
			}
			return;
		}

		$parentHashes = \array_map('md5', $parents);
		$etag = \uniqid(); // since we give all folders the same etag we don't ask the storage for the etag

		$builder = $this->connection->getQueryBuilder();
		$hashParams = \array_map(function ($hash) use ($builder) {
			return $builder->expr()->literal($hash);
		}, $parentHashes);

		$builder->update('filecache')
			->set('mtime', $builder->createFunction('GREATEST(`mtime`, ' . $builder->createNamedParameter($time, IQueryBuilder::PARAM_INT) . ')'))
			->set('etag', $builder->createNamedParameter($etag, IQueryBuilder::PARAM_STR));

		if ($sizeDifference !== 0) {
			// if we need to update size, only update the records with calculated size (>-1)
			// special cases (IScanner::SIZE_*) have negative values, so they won't interfere
			$builder->set('size', $builder->createFunction(
				'CASE' .
					' WHEN ' . $builder->expr()->gt('size', $builder->expr()->literal(-1, IQueryBuilder::PARAM_INT)) .
						' THEN  `size` + ' . $builder->createNamedParameter($sizeDifference) .
					' ELSE `size`' .
				' END'
			));
		}

		$builder->where($builder->expr()->eq('storage', $builder->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));
		$builder->andWhere($builder->expr()->in('path_hash', $hashParams));

		$builder->execute();
	}

	protected function getParents($path) {
		if ($path === '') {
			return [];
		}
		$parts = \explode('/', $path);
		$parent = '';
		$parents = [];
		foreach ($parts as $part) {
			$parents[] = $parent;
			$parent = \trim($parent . '/' . $part, '/');
		}
		return $parents;
	}

	/**
	 * Mark the beginning of a propagation batch
	 *
	 * Note that not all cache setups support propagation in which case this will be a noop
	 *
	 * Batching for cache setups that do support it has to be explicit since the cache state is not fully consistent
	 * before the batch is committed.
	 */
	public function beginBatch() {
		$this->inBatch = true;
	}

	private function addToBatch($internalPath, $time, $sizeDifference) {
		if (!isset($this->batch[$internalPath])) {
			$this->batch[$internalPath] = [
				'hash' => \md5($internalPath),
				'time' => $time,
				'size' => $sizeDifference
			];
		} else {
			$this->batch[$internalPath]['size'] += $sizeDifference;
			if ($time > $this->batch[$internalPath]['time']) {
				$this->batch[$internalPath]['time'] = $time;
			}
		}
	}

	/**
	 * Commit the active propagation batch
	 */
	public function commitBatch() {
		if (!$this->inBatch) {
			throw new \BadMethodCallException('Not in batch');
		}
		$this->inBatch = false;

		$this->connection->beginTransaction();

		$storageId = (int)$this->storage->getStorageCache()->getNumericId();
		// ein etag für den gesamten Batch — entspricht dem bisherigen Verhalten,
		// das uniqid() ebenfalls nur einmal beim Query-Bau auswertete
		$etag = \uniqid();

		// Einträge nach identischem Wert gruppieren: bei einer typischen
		// Propagation (eine Datei, n Eltern-Ebenen) sind time und size für
		// alle Ebenen gleich — statt 2 UPDATEs pro Ebene genügen 2 gesamt
		$timeGroups = [];
		$sizeGroups = [];
		foreach ($this->batch as $item) {
			$timeGroups[$item['time']][] = $item['hash'];
			if ($item['size']) {
				$sizeGroups[$item['size']][] = $item['hash'];
			}
		}

		foreach ($timeGroups as $time => $hashes) {
			foreach (\array_chunk($hashes, 200) as $hashChunk) {
				$query = $this->connection->getQueryBuilder();
				$hashParams = \array_map(function ($hash) use ($query) {
					return $query->expr()->literal($hash);
				}, $hashChunk);
				$query->update('filecache')
					->set('mtime', $query->createFunction('GREATEST(`mtime`, ' . $query->createParameter('time') . ')'))
					->set('etag', $query->expr()->literal($etag))
					->where($query->expr()->eq('storage', $query->expr()->literal($storageId, IQueryBuilder::PARAM_INT)))
					->andWhere($query->expr()->in('path_hash', $hashParams));
				$query->setParameter('time', $time, IQueryBuilder::PARAM_INT);
				$query->execute();
			}
		}

		foreach ($sizeGroups as $sizeDifference => $hashes) {
			foreach (\array_chunk($hashes, 200) as $hashChunk) {
				$sizeQuery = $this->connection->getQueryBuilder();
				$hashParams = \array_map(function ($hash) use ($sizeQuery) {
					return $sizeQuery->expr()->literal($hash);
				}, $hashChunk);
				$sizeQuery->update('filecache')
					->set('size', $sizeQuery->createFunction('`size` + ' . $sizeQuery->createParameter('size')))
					->where($sizeQuery->expr()->eq('storage', $sizeQuery->expr()->literal($storageId, IQueryBuilder::PARAM_INT)))
					->andWhere($sizeQuery->expr()->in('path_hash', $hashParams))
					// special cases (IScanner::SIZE_*) have negative values, so they won't interfere
					->andWhere($sizeQuery->expr()->gt('size', $sizeQuery->expr()->literal(-1, IQueryBuilder::PARAM_INT)));
				$sizeQuery->setParameter('size', $sizeDifference, IQueryBuilder::PARAM_INT);
				$sizeQuery->execute();
			}
		}

		$this->batch = [];

		$this->connection->commit();
	}
}
