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
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-24.
 * Changes:
 *   - cap bruteforce delay at 30s + prune oc_bruteforce_attempts
 */

namespace OCO\Security\Bruteforce;

use OC\BackgroundJob\TimedJob;
use OCP\ILogger;

/**
 * Periodically prunes expired rows from oc_bruteforce_attempts so the table
 * does not grow unbounded. Rows older than the throttler lookback window no
 * longer influence the delay, so deleting them is loss-free.
 *
 * @package OCO\Security\Bruteforce
 */
class CleanupJob extends TimedJob {
	/** @var Throttler */
	private $throttler;

	/** @var ILogger */
	private $logger;

	public function __construct(Throttler $throttler, ILogger $logger) {
		$this->throttler = $throttler;
		$this->logger = $logger;
		// Attempts only matter for the 12h lookback window; hourly cleanup keeps
		// the table small without adding meaningful load.
		$this->setInterval(60 * 60);
	}

	/**
	 * @param mixed $argument
	 * @return void
	 */
	protected function run($argument) {
		$deleted = $this->throttler->cleanupOldAttempts();
		if ($deleted > 0) {
			$this->logger->debug(
				"Bruteforce cleanup removed {$deleted} expired attempt row(s)",
				['app' => 'core']
			);
		}
	}
}
