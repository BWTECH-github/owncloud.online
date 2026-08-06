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

	/**
	 * Upper bound for a single sleep, regardless of attempt count. Capped low
	 * so a flood of failed attempts from one IP+identifier cannot tie up an
	 * FPM worker for minutes per request (self-DoS): a few dozen parallel
	 * requests would otherwise exhaust the pool. The exponential back-off still
	 * climbs to this cap, and the "no hard lockout" design is preserved.
	 */
	private const MAX_DELAY_SECONDS = 30;

	/**
	 * Attempts within a subnet bucket (see computeIpBucket()) before that
	 * dimension starts adding delay. Deliberately higher than the per-IP
	 * threshold: a /24 or /64 bucket can contain many unrelated legitimate
	 * users (NAT, corporate gateway, mobile carrier) whose combined,
	 * unrelated failed logins must not immediately throttle the whole
	 * subnet.
	 */
	private const BUCKET_FREE_ATTEMPTS = 20;

	/**
	 * Upper bound for the subnet-bucket dimension, deliberately lower than
	 * MAX_DELAY_SECONDS: a false positive here punishes bystanders sharing
	 * the origin's subnet, not just the attacker, so the worst case must be
	 * less severe than the per-IP cap.
	 */
	private const BUCKET_MAX_DELAY_SECONDS = 10;

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
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::DB_TABLE)
				->values([
					'action' => $qb->createNamedParameter($action),
					'occurred' => $qb->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT),
					'ip' => $qb->createNamedParameter($ip),
					'ip_bucket' => $qb->createNamedParameter($this->computeIpBucket($ip)),
					'identifier' => $qb->createNamedParameter($identifier),
				]);
			$qb->execute();
		} catch (\Exception $e) {
			$this->logStorageFailure($e, 'record a failed attempt');
		}
	}

	/**
	 * The throttler must never take authentication down with it. Its table can be
	 * missing or out of date - most notably right after an upgrade whose
	 * migrations have not been run yet, where the ip_bucket column does not exist
	 * yet - and a query failing there must not turn every login into a 500. Log
	 * the problem prominently (an administrator has to run occ upgrade) and let
	 * the caller continue unthrottled.
	 *
	 * @param \Exception $e
	 * @param string $what description of the operation that failed
	 */
	private function logStorageFailure(\Exception $e, $what) {
		$this->logger->logException($e, [
			'app' => 'core',
			'message' => "Brute-force throttler could not $what - is the database schema up to date (occ upgrade)? Continuing without throttling.",
			'level' => \OCP\Util::ERROR,
		]);
	}

	/**
	 * Clear recorded failures for this exact (action, ip, identifier) after a
	 * successful authentication, so a legitimate user who mistyped their
	 * password a few times isn't still throttled on their next, correct
	 * login.
	 *
	 * Deliberately scoped to this one identifier only - it must NOT clear
	 * other identifiers' failure history from the same IP. Otherwise, during
	 * a password-spraying attack, a single lucky guess would reset the
	 * whole origin's attempt count via countAttemptsByIp() and let the
	 * attacker continue against the remaining accounts unthrottled.
	 *
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 */
	public function resetDelay($action, $ip, $identifier) {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::DB_TABLE)
				->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
				->andWhere($qb->expr()->eq('ip', $qb->createNamedParameter($ip)))
				->andWhere($qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)));
			$qb->execute();
		} catch (\Exception $e) {
			$this->logStorageFailure($e, 'clear recorded attempts');
		}
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
		try {
			$exactCount = \max(
				$this->countAttempts($action, $ip, $identifier),
				$this->countAttemptsByIp($action, $ip)
			);
			$bucketCount = $this->countAttemptsByIpBucket($action, $ip);

			return \max(
				$this->exponentialDelay($exactCount, self::MAX_DELAY_SECONDS),
				$this->exponentialDelay($bucketCount - self::BUCKET_FREE_ATTEMPTS, self::BUCKET_MAX_DELAY_SECONDS)
			);
		} catch (\Exception $e) {
			$this->logStorageFailure($e, 'read recorded attempts');
			return 0;
		}
	}

	/**
	 * 1, 2, 4, 8, ... seconds for each attempt past the first, capped at
	 * $cap so a single request can't tie up a worker for longer than that.
	 *
	 * @param int $count
	 * @param int $cap
	 * @return int seconds, 0 if $count is 0 or negative
	 */
	private function exponentialDelay($count, $cap) {
		if ($count <= 0) {
			return 0;
		}
		return \min($cap, (int)(2 ** \min($count, 20)));
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

	/**
	 * Failed attempts for this action from this exact IP, regardless of
	 * which identifier was targeted. Closes the horizontal password-spraying
	 * gap: countAttempts() alone only ever sees 0/1 per (ip, identifier)
	 * pair when one IP tries many different accounts with the same guess.
	 *
	 * @param string $action
	 * @param string $ip
	 * @return int
	 */
	private function countAttemptsByIp($action, $ip) {
		$qb = $this->db->getQueryBuilder();
		$qb->select([$qb->createFunction('count(*) as `num_attempts`')])
			->from(self::DB_TABLE)
			->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
			->andWhere($qb->expr()->eq('ip', $qb->createNamedParameter($ip)))
			->andWhere($qb->expr()->gt('occurred', $qb->createNamedParameter(
				$this->timeFactory->getTime() - self::LOOKBACK_SECONDS,
				IQueryBuilder::PARAM_INT
			)));
		$result = $qb->execute();
		$row = $result->fetchAssociative();
		$result->free();

		return $row ? (int)$row['num_attempts'] : 0;
	}

	/**
	 * Failed attempts for this action from ANY IP in the same subnet
	 * bucket as $ip (see computeIpBucket()), regardless of exact IP or
	 * targeted identifier. Closes the gap countAttemptsByIp() leaves open:
	 * an attacker rotating through multiple IPs of the same /24 (IPv4) or
	 * /64 (IPv6) - e.g. a botnet or cloud egress pool - would otherwise
	 * never accumulate enough failures on any single IP to be throttled.
	 *
	 * @param string $action
	 * @param string $ip
	 * @return int
	 */
	private function countAttemptsByIpBucket($action, $ip) {
		$bucket = $this->computeIpBucket($ip);
		if ($bucket === null) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select([$qb->createFunction('count(*) as `num_attempts`')])
			->from(self::DB_TABLE)
			->where($qb->expr()->eq('action', $qb->createNamedParameter($action)))
			->andWhere($qb->expr()->eq('ip_bucket', $qb->createNamedParameter($bucket)))
			->andWhere($qb->expr()->gt('occurred', $qb->createNamedParameter(
				$this->timeFactory->getTime() - self::LOOKBACK_SECONDS,
				IQueryBuilder::PARAM_INT
			)));
		$result = $qb->execute();
		$row = $result->fetchAssociative();
		$result->free();

		return $row ? (int)$row['num_attempts'] : 0;
	}

	/**
	 * Normalize an IP address to a subnet bucket key: /24 for IPv4, /64 for
	 * IPv6. Computed via inet_pton()-based binary masking rather than
	 * string-prefix matching on the textual address - the same IPv6 /64 can
	 * be written multiple different ways (:: compression, leading zeros),
	 * so string comparison would silently miss matches.
	 *
	 * @param string $ip
	 * @return string|null canonical bucket key, or null if $ip cannot be
	 *                      parsed as an IPv4/IPv6 address - callers treat
	 *                      that as "no bucket dimension" rather than
	 *                      throwing, since a malformed remote address must
	 *                      never break login.
	 */
	private function computeIpBucket($ip) {
		$packed = @\inet_pton($ip);
		if ($packed === false) {
			return null;
		}
		$maskBits = \strlen($packed) === 4 ? 24 : 64;
		$masked = $packed & $this->prefixMask(\strlen($packed), $maskBits);
		return \bin2hex($masked) . '/' . $maskBits;
	}

	/**
	 * Build a $totalBytes-long binary mask with the leading $maskBits bits
	 * set to 1 and the rest 0, for ANDing with a packed inet_pton() address.
	 *
	 * @param int $totalBytes
	 * @param int $maskBits
	 * @return string binary mask, same length as $totalBytes
	 */
	private function prefixMask($totalBytes, $maskBits) {
		$mask = '';
		$fullBytes = \intdiv($maskBits, 8);
		$remainder = $maskBits % 8;
		for ($i = 0; $i < $totalBytes; $i++) {
			if ($i < $fullBytes) {
				$mask .= "\xFF";
			} elseif ($i === $fullBytes && $remainder > 0) {
				$mask .= \chr((0xFF << (8 - $remainder)) & 0xFF);
			} else {
				$mask .= "\x00";
			}
		}
		return $mask;
	}

	/**
	 * Delete attempt rows older than the lookback window. Run periodically by
	 * CleanupJob so oc_bruteforce_attempts does not grow unbounded - rows past
	 * LOOKBACK_SECONDS no longer influence getDelay() anyway. Uses the existing
	 * bruteforce_occurred_index on `occurred`.
	 *
	 * @return int number of rows deleted
	 */
	public function cleanupOldAttempts() {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::DB_TABLE)
				->where($qb->expr()->lt('occurred', $qb->createNamedParameter(
					$this->timeFactory->getTime() - self::LOOKBACK_SECONDS,
					IQueryBuilder::PARAM_INT
				)));
			return (int)$qb->execute();
		} catch (\Exception $e) {
			$this->logStorageFailure($e, 'prune expired attempts');
			return 0;
		}
	}
}
