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
 * Modified by BW-Tech GmbH on 2026-08-06.
 * Changes:
 *   - never let the brute-force throttler break authentication
 *   - detect password spraying across a whole IP subnet, not just one IP
 *   - throttle password spraying per-IP, reset delay on successful auth
 *   - cap bruteforce delay at 30s + prune oc_bruteforce_attempts
 */

namespace OCO\Security\Bruteforce;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ILogger;

/**
 * Rate-limits repeated failed authentication attempts (login, WebDAV/OCS basic
 * auth, public-share-link passwords, oco_mcp), keyed on the combination of
 * remote IP and the targeted identifier (login name / share token).
 *
 * The model is a MINIMUM SPACING between attempts, not "sleep this long on
 * every try": getDelay() returns how far apart attempts have to be, and
 * getRetryAfter() turns that into the seconds still left of the current
 * cooldown. Time the caller has already waited therefore counts towards the
 * cooldown instead of being charged again on the next attempt.
 *
 * That distinction is what makes the wait explainable. An interactive caller
 * (the web login) asks getRetryAfter() up front, refuses the attempt and shows
 * the remaining time, so the user is told what is happening instead of staring
 * at a page that hangs. The attacker's maximum rate is unchanged - one attempt
 * per cooldown either way - but no PHP worker is held while they wait.
 *
 * Deliberately DB-backed rather than OCP\ICache: on installations without a
 * configured distributed cache backend (Redis/Memcached/APCu), ICacheFactory
 * can fall back to a per-request cache with no persistence across requests,
 * which would make the throttle silently ineffective.
 *
 * There is no permanent lockout: every cooldown expires on its own, so an
 * attacker cannot lock a legitimate user out of their account for good. See
 * project memory "security-audit-2026-07" for the design discussion.
 *
 * @package OCO\Security\Bruteforce
 */
class Throttler {
	public const DB_TABLE = 'bruteforce_attempts';

	/** How far back failed attempts are still counted. */
	private const LOOKBACK_SECONDS = 12 * 3600;

	/**
	 * Failed attempts per (IP, identifier) that stay free of any cooldown.
	 * Mistyping a password - or reaching for an old one - is what people
	 * actually do, and punishing the first try trains them to distrust the
	 * form. Brute forcing a password needs orders of magnitude more than three
	 * guesses, so the security value of charging for them is negligible.
	 */
	private const IDENTIFIER_FREE_ATTEMPTS = 3;

	/**
	 * Failed attempts from one IP across ALL identifiers before that dimension
	 * starts a cooldown. This is the password-spraying brake, and it counts raw
	 * attempts rather than distinct accounts on purpose: resetDelay() removes
	 * the rows of the one identifier that just succeeded, so a distinct-account
	 * counter would hand an attacker a free slot back for every account they
	 * crack, and they could sit on the threshold indefinitely. Against a raw
	 * total, one success barely dents the count.
	 *
	 * Twenty is well clear of the per-identifier grace so that a single user
	 * having a bad morning does not slow down everyone behind a shared address
	 * (office NAT, computer lab, family router). Getting there alone takes real
	 * persistence, because their own escalating cooldown paces them long before
	 * the twentieth try - and even then their colleagues only pay 5 seconds.
	 */
	private const IP_FREE_ATTEMPTS = 20;

	/**
	 * First cooldown once the grace is used up. The ladder then doubles
	 * (5, 10, 20, 40, 80 ...) up to MAX_DELAY_SECONDS. Starting at five
	 * seconds rather than one keeps the very first cooldown long enough to
	 * matter while still reading as "a moment" to a human.
	 */
	private const FIRST_DELAY_SECONDS = 5;

	/**
	 * Upper bound for a cooldown. Two minutes caps an attacker at roughly 30
	 * guesses an hour on a single account, and it is a wait a locked-out user
	 * can be told about honestly - which is precisely why it may be this long:
	 * the interactive caller shows the remaining time instead of blocking.
	 */
	private const MAX_DELAY_SECONDS = 120;

	/**
	 * Hard ceiling for how long sleepDelay() may occupy the process,
	 * independent of the cooldown. Non-interactive callers (WebDAV/OCS basic
	 * auth, share passwords, MCP) have no way to render a countdown and are
	 * still slowed by sleeping, but a flood of parallel attempts must not tie
	 * up FPM workers for minutes and take the instance down (self-DoS).
	 */
	private const MAX_SLEEP_SECONDS = 30;

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
	 * Required minimum spacing between attempts right now, in seconds, derived
	 * from the failed attempts recorded within the lookback window. This is NOT
	 * how long the caller still has to wait - use getRetryAfter() for that.
	 *
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 * @return int seconds, 0 if no cooldown is warranted
	 */
	public function getDelay($action, $ip, $identifier) {
		try {
			return $this->computeCooldown($action, $ip, $identifier)['delay'];
		} catch (\Exception $e) {
			$this->logStorageFailure($e, 'read recorded attempts');
			return 0;
		}
	}

	/**
	 * Seconds still left of the current cooldown, i.e. how long until this
	 * caller may try again. Zero means "go ahead now".
	 *
	 * Unlike getDelay() this accounts for time already elapsed since the last
	 * failure, so waiting counts: a user who sits out the cooldown is let
	 * through instead of being charged the full delay a second time. It is
	 * what an interactive caller shows in its countdown, and what sleepDelay()
	 * sleeps.
	 *
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 * @return int seconds, 0 if the caller may attempt immediately
	 */
	public function getRetryAfter($action, $ip, $identifier) {
		try {
			return $this->computeCooldown($action, $ip, $identifier)['retryAfter'];
		} catch (\Exception $e) {
			$this->logStorageFailure($e, 'read recorded attempts');
			return 0;
		}
	}

	/**
	 * Evaluate all three dimensions - this exact (ip, identifier) pair, the IP
	 * across every identifier, and the surrounding subnet bucket - and return
	 * the strictest outcome of the three.
	 *
	 * Each dimension carries its own grace and its own cap, and each is
	 * measured against ITS OWN most recent attempt: the remaining cooldown of
	 * a dimension is only meaningful relative to when that dimension last saw
	 * a failure.
	 *
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 * @return array{delay: int, retryAfter: int}
	 * @throws \Exception on storage failure; callers translate that into "no
	 *                    throttling" rather than breaking authentication
	 */
	private function computeCooldown($action, $ip, $identifier) {
		$now = $this->timeFactory->getTime();
		$dimensions = [
			[$this->countAttempts($action, $ip, $identifier), self::IDENTIFIER_FREE_ATTEMPTS, self::MAX_DELAY_SECONDS],
			[$this->countAttemptsByIp($action, $ip), self::IP_FREE_ATTEMPTS, self::MAX_DELAY_SECONDS],
			[$this->countAttemptsByIpBucket($action, $ip), self::BUCKET_FREE_ATTEMPTS, self::BUCKET_MAX_DELAY_SECONDS],
		];

		$delay = 0;
		$retryAfter = 0;
		foreach ($dimensions as [$stats, $free, $cap]) {
			$dimensionDelay = $this->exponentialDelay($stats['count'] - $free, $cap);
			if ($dimensionDelay <= 0) {
				continue;
			}
			$delay = \max($delay, $dimensionDelay);
			$retryAfter = \max($retryAfter, $stats['last'] + $dimensionDelay - $now);
		}

		// Clamped to the cooldown itself: a stored timestamp ahead of the current
		// clock (skew after a time sync, or a moved system clock) would otherwise
		// produce a remaining time larger than any cooldown ever is - and that
		// number gets rendered on the login form.
		return ['delay' => $delay, 'retryAfter' => \min(\max(0, $retryAfter), $delay)];
	}

	/**
	 * FIRST_DELAY_SECONDS, then doubling for every further attempt, capped at
	 * $cap. $count is the attempt count with the dimension's grace already
	 * subtracted, so zero or less means "still within grace, no cooldown".
	 *
	 * @param int $count
	 * @param int $cap
	 * @return int seconds, 0 if $count is 0 or negative
	 */
	private function exponentialDelay($count, $cap) {
		if ($count <= 0) {
			return 0;
		}
		return \min($cap, self::FIRST_DELAY_SECONDS * (int)(2 ** \min($count - 1, 20)));
	}

	/**
	 * Sleep out the remaining cooldown, for callers that cannot render one.
	 * Call this BEFORE checking credentials; the current attempt is registered
	 * separately via registerAttempt() once its own outcome is known.
	 *
	 * Interactive callers should prefer getRetryAfter() and tell the user
	 * instead - sleeping leaves them with nothing but a page that hangs.
	 *
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 */
	public function sleepDelay($action, $ip, $identifier) {
		$delay = \min($this->getRetryAfter($action, $ip, $identifier), self::MAX_SLEEP_SECONDS);
		if ($delay > 0) {
			$this->logger->debug("Throttling $action attempt from $ip for {$delay}s", ['app' => 'core']);
			\sleep($delay);
		}
	}

	/**
	 * @param string $action
	 * @param string $ip
	 * @param string $identifier
	 * @return array{count: int, last: int}
	 */
	private function countAttempts($action, $ip, $identifier) {
		$qb = $this->db->getQueryBuilder();
		$qb->select([$qb->createFunction('count(*) as `num_attempts`, max(`occurred`) as `last_attempt`')])
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

		return $this->toStats($row);
	}

	/**
	 * Normalize a "count(*) + max(occurred)" row. max() is NULL when no rows
	 * matched, so the timestamp only ever matters together with a non-zero
	 * count.
	 *
	 * @param array|false $row
	 * @return array{count: int, last: int}
	 */
	private function toStats($row) {
		if (!$row) {
			return ['count' => 0, 'last' => 0];
		}
		return [
			'count' => (int)$row['num_attempts'],
			'last' => (int)$row['last_attempt'],
		];
	}

	/**
	 * Failed attempts for this action from this exact IP, regardless of which
	 * identifier was targeted. Closes the horizontal password-spraying gap:
	 * countAttempts() alone only ever sees 0/1 per (ip, identifier) pair when
	 * one IP tries many different accounts with the same guess.
	 *
	 * Raw attempts rather than distinct accounts - see IP_FREE_ATTEMPTS for why
	 * that matters when resetDelay() clears a cracked account's rows.
	 *
	 * @param string $action
	 * @param string $ip
	 * @return array{count: int, last: int}
	 */
	private function countAttemptsByIp($action, $ip) {
		$qb = $this->db->getQueryBuilder();
		$qb->select([$qb->createFunction('count(*) as `num_attempts`, max(`occurred`) as `last_attempt`')])
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

		return $this->toStats($row);
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
	 * @return array{count: int, last: int}
	 */
	private function countAttemptsByIpBucket($action, $ip) {
		$bucket = $this->computeIpBucket($ip);
		if ($bucket === null) {
			return ['count' => 0, 'last' => 0];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select([$qb->createFunction('count(*) as `num_attempts`, max(`occurred`) as `last_attempt`')])
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

		return $this->toStats($row);
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
