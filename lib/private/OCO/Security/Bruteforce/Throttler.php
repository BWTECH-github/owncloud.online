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

namespace OCO\Security\Bruteforce;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ILogger;

/**
 * Slows down repeated failed authentication attempts (login, WebDAV/OCS basic
 * auth, public-share-link passwords, oco_mcp) by sleeping for an increasing
 * duration before returning, keyed on the combination of remote IP and the
 * targeted identifier (login name / share token).
 *
 * Deliberately DB-backed rather than OCP\ICache: on installations without a
 * configured distributed cache backend (Redis/Memcached/APCu), ICacheFactory
 * can fall back to a per-request cache with no persistence across requests,
 * which would make the throttle silently ineffective.
 *
 * This never rejects requests outright (no hard lockout) - it only adds
 * delay. See project memory "security-audit-2026-07" for the design
 * discussion that led to this trade-off.
 *
 * @package OCO\Security\Bruteforce
 */
class Throttler {
	public const DB_TABLE = 'bruteforce_attempts';

	/** How far back failed attempts are still counted. */
	private const LOOKBACK_SECONDS = 12 * 3600;

	/** Upper bound for a single sleep, regardless of attempt count. */
	private const MAX_DELAY_SECONDS = 30 * 60;

	/** @var IDBConnection */
	private $db;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var ILogger */
	private $logger;

	/**
	 * @param IDBConnection $db
	 * @param ITimeFactory $timeFactory
	 * @param ILogger $logger
	 */
	public function __construct(IDBConnection $db, ITimeFactory $timeFactory, ILogger $logger) {
		$this->db = $db;
		$this->timeFactory = $timeFactory;
		$this->logger = $logger;
	}

	/**
	 * Record a failed authentication attempt.
	 *
	 * @param string $action e.g. 'login', 'share_password'
	 * @param string $ip remote address of the caller
	 * @param string $identifier the targeted login name or share token
	 */
	public function registerAttempt($action, $ip, $identifier) {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::DB_TABLE)
			->values([
				'action' => $qb->createNamedParameter($action),
				'occurred' => $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT),
				'ip' => $qb->createNamedParameter($ip),
				'identifier' => $qb->createNamedParameter($identifier),
			]);
		$qb->execute();
	}

	/**
	 * How many seconds the caller should be made to wait right now, based on
	 * failed attempts for this exact IP + identifier combination within the
	 * lookback window.
	 *
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 * @return int seconds, 0 if no delay is warranted
	 */
	public function getDelay($action, $ip, $identifier) {
		$count = $this->countAttempts($action, $ip, $identifier);
		if ($count === 0) {
			return 0;
		}
		// 1, 2, 4, 8, ... seconds, capped so a single request can't tie up
		// a worker for longer than MAX_DELAY_SECONDS.
		return \min(self::MAX_DELAY_SECONDS, (int)(2 ** \min($count, 20)));
	}

	/**
	 * Sleep for whatever delay getDelay() currently computes. Call this
	 * BEFORE checking credentials, using the attempt count accumulated by
	 * previous failures (the current attempt is registered separately via
	 * registerAttempt() once its own outcome is known).
	 *
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 */
	public function sleepDelay($action, $ip, $identifier) {
		$delay = $this->getDelay($action, $ip, $identifier);
		if ($delay > 0) {
			$this->logger->debug("Throttling $action attempt from $ip for {$delay}s", ['app' => 'core']);
			\sleep($delay);
		}
	}

	/**
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 * @return int
	 */
	private function countAttempts($action, $ip, $identifier) {
		$qb = $this->db->getQueryBuilder();
		$qb->select([$qb->createFunction('count(*) as `num_attempts`')])
			->from(self::DB_TABLE)
			->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
			->andWhere($qb->expr()->eq('ip', $qb->createNamedParameter($ip)))
			->andWhere($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)))
			->andWhere($qb->expr()->gt('occurred', $qb->createNamedParameter(
				$this->timeFactory->getTime() - self::LOOKBACK_SECONDS,
				IQueryBuilder::PARAM_INT
			)));
		$result = $qb->execute();
		$row = $result->fetchAssociative();
		$result->free();

		return $row ? (int)$row['num_attempts'] : 0;
	}
}
