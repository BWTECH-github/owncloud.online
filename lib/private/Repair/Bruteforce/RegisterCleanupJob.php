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
 * Modified by BW-Tech GmbH on 2026-07-24.
 * Changes:
 *   - cap bruteforce delay at 30s + prune oc_bruteforce_attempts
 */

namespace OC\Repair\Bruteforce;

use OCO\Security\Bruteforce\CleanupJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Idempotently registers the bruteforce-attempts CleanupJob. IJobList::add is a
 * no-op when the job already exists, so running the repair step repeatedly is
 * safe.
 */
class RegisterCleanupJob implements IRepairStep {
	/** @var IJobList */
	private $jobList;

	public function __construct(IJobList $jobList) {
		$this->jobList = $jobList;
	}

	public function getName() {
		return 'Register bruteforce attempts cleanup job';
	}

	public function run(IOutput $output) {
		$this->jobList->add(CleanupJob::class);
	}
}
