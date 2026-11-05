<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

namespace OCA\DAV\Tests\Unit\JobStatus\Entity;

use OCA\DAV\JobStatus\Entity\JobStatus;
use OCA\DAV\JobStatus\Entity\JobStatusMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Sabre\DAV\UUIDUtil;
use Test\TestCase;

/**
 * Class JobStatusMapperTest
 *
 * @package OCA\DAV\Tests\Unit\JobStatus\Entity
 * @group DB
 */
class JobStatusMapperTest extends TestCase {
	/** @var JobStatusMapper */
	private $mapper;
	/** @var IDBConnection */
	private $database;
	/** @var JobStatus */
	private $testJobStatus;

	public function setUp(): void {
		parent::setUp();
		$this->database = \OC::$server->getDatabaseConnection();
		$this->mapper = new JobStatusMapper($this->database);

		// test entity
		$this->testJobStatus = new JobStatus();
		$this->testJobStatus->setUserId('xxx');
		$this->testJobStatus->setUuid(UUIDUtil::getUUID());
		$this->testJobStatus->setStatusInfo(\json_encode([]));
	}

	public function tearDown(): void {
		parent::tearDown();
		if ($this->mapper !== null && $this->testJobStatus !== null) {
			$this->mapper->delete($this->testJobStatus);
		}
	}

	/**
	 */
	public function testInsert() {
		$this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

		$this->mapper->insert($this->testJobStatus);
		$this->assertNotNull($this->testJobStatus->getId());
		// below throws exception due to unique constraint violation
		$this->mapper->insert($this->testJobStatus);
	}

	/**
	 * @depends testInsert
	 */
	public function testQuery() {
		$this->mapper->insert($this->testJobStatus);
		$entity = $this->mapper->findByUserIdAndJobId(
			$this->testJobStatus->getUserId(),
			$this->testJobStatus->getUuid()
		);
		$this->assertInstanceOf(JobStatus::class, $entity);
		$this->assertEquals($this->testJobStatus->getId(), $entity->getId());
		$this->assertEquals($this->testJobStatus->getUserId(), $entity->getUserId());
		$this->assertEquals($this->testJobStatus->getUuid(), $entity->getUuid());
		$this->assertEquals($this->testJobStatus->getStatusInfo(), $entity->getStatusInfo());
	}

	private function insertJob(string $userId, int $updatedAt): JobStatus {
		$job = new JobStatus();
		$job->setUserId($userId);
		$job->setUuid(UUIDUtil::getUUID());
		$job->setStatusInfo(\json_encode(['status' => 'finished']));
		$job->setUpdatedAt($updatedAt);
		return $this->mapper->insert($job);
	}

	private function jobExists(JobStatus $job): bool {
		try {
			$this->mapper->findByUserIdAndJobId($job->getUserId(), $job->getUuid());
			return true;
		} catch (DoesNotExistException $e) {
			return false;
		}
	}

	public function testDeleteUnchangedSince() {
		$old = $this->insertJob('job-status-old', 1000);
		$recent = $this->insertJob('job-status-old', 3000);

		$this->mapper->deleteUnchangedSince(2000);

		$this->assertFalse($this->jobExists($old));
		$this->assertTrue($this->jobExists($recent));
		$this->assertSame(3000, $this->mapper->findByUserIdAndJobId('job-status-old', $recent->getUuid())->getUpdatedAt());
		$this->mapper->delete($recent);
	}

	public function testDeleteByUserId() {
		$own = $this->insertJob('job-status-gone', \time());
		$other = $this->insertJob('job-status-stays', \time());

		$this->mapper->deleteByUserId('job-status-gone');

		$this->assertFalse($this->jobExists($own));
		$this->assertTrue($this->jobExists($other));
		$this->mapper->delete($other);
	}
}
