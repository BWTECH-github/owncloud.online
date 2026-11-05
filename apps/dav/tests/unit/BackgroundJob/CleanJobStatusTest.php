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

namespace OCA\DAV\Tests\Unit\BackgroundJob;

use OCA\DAV\BackgroundJob\CleanJobStatus;
use OCA\DAV\JobStatus\Entity\JobStatusMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Test\TestCase;

class CleanJobStatusTest extends TestCase {
	public function testDeletesJobsUnchangedForADay() {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1000000);
		$mapper = $this->createMock(JobStatusMapper::class);
		$mapper->expects($this->once())->method('deleteUnchangedSince')
			->with(1000000 - CleanJobStatus::MAX_AGE);

		$job = new CleanJobStatus($mapper, $timeFactory);
		$job->execute($this->createMock(IJobList::class));
	}
}
