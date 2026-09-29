<?php
/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test\BackgroundJob;

use OCP\ILogger;

class JobTest extends \Test\TestCase {
	private $run = false;

	protected function setUp(): void {
		parent::setUp();
		$this->run = false;
	}

	public function testRemoveAfterException() {
		$jobList = new DummyJobList();
		$e = new \Exception();
		$job = new TestJob($this, function () use ($e) {
			throw $e;
		});
		$jobList->add($job);

		$logger = $this->getMockBuilder('OCP\ILogger')
			->disableOriginalConstructor()
			->getMock();
		$logger->expects($this->once())
			->method('logException')
			->with($e);

		$this->assertCount(1, $jobList->getAll());
		$job->execute($jobList, $logger);
		$this->assertTrue($this->run);
		$this->assertCount(1, $jobList->getAll());
	}

	public function testExecuteLogsErrorsInsteadOfPassingThemOn() {
		$jobList = new DummyJobList();
		// wie fwrite(false) im Zertifikatsspeicher unter PHP 8
		$error = new \TypeError('fwrite(): Argument #1 ($stream) must be of type resource, false given');
		$job = new TestJob($this, function () use ($error) {
			throw $error;
		});
		$jobList->add($job);

		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->once())
			->method('logException')
			->with($error);

		$job->execute($jobList, $logger);
		$this->assertTrue($this->run);
		$this->assertCount(1, $jobList->getAll());
	}

	public function markRun() {
		$this->run = true;
	}
}
