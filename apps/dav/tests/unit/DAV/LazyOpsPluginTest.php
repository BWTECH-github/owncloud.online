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

namespace OCA\DAV\Tests\unit\DAV;

use OCA\DAV\DAV\LazyOpsPlugin;
use OCA\DAV\JobStatus\Entity\JobStatus;
use OCA\DAV\JobStatus\Entity\JobStatusMapper;
use OCA\DAV\Upload\AssemblyStream;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Shutdown\IShutdownManager;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Test\TestCase;

class LazyOpsPluginTest extends TestCase {
	/** @var LazyOpsPlugin */
	private $plugin;
	/** @var ILogger */
	private $logger;
	/** @var JobStatusMapper | \PHPUnit\Framework\MockObject\MockObject */
	private $jobStatusMapper;
	/** @var IShutdownManager | \PHPUnit\Framework\MockObject\MockObject */
	private $shutdownManager;
	/** @var IURLGenerator | \PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;
	/** @var IUserSession | \PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	public function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator= $this->createMock(IURLGenerator::class);
		$this->shutdownManager = $this->createMock(IShutdownManager::class);
		$this->jobStatusMapper = $this->createMock(JobStatusMapper::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->plugin = new LazyOpsPlugin(
			$this->userSession,
			$this->urlGenerator,
			$this->shutdownManager,
			$this->jobStatusMapper,
			$this->logger
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testInit() {
		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method('on')->with('method:MOVE');
		$this->plugin->initialize($server);
	}

	public function testMoveWithoutLazyOpsHeader() {
		$request = $this->createMock(RequestInterface::class);
		$response= $this->createMock(ResponseInterface::class);
		$response->expects($this->never())->method('setStatus');
		$this->plugin->httpMove($request, $response);
	}

	public function testMoveWithLazyOpsHeader() {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getHeader')->with('OC-LazyOps')->willReturn(true);
		$response= $this->createMock(ResponseInterface::class);
		$response->expects($this->once())->method('setStatus')->with(202);
		$response->expects($this->exactly(3))->method('setHeader')->withConsecutive(
			['Connection', 'close'],
			['Content-Length', '0'],
			['OC-JobStatus-Location', self::stringStartsWith('/remote.php/dav/job-status/alice/')]
		);

		$this->urlGenerator->expects(self::once())->method('linkTo')->willReturn('/remote.php');

		$this->jobStatusMapper->expects(self::once())
			->method('insert')->willReturnCallback(function (JobStatus $entity) {
				self::assertEquals('alice', $entity->getUserId());
				self::assertEquals('{"status":"init"}', $entity->getStatusInfo());
			});

		$this->shutdownManager->expects(self::once())->method('register');

		$this->plugin->httpMove($request, $response);
	}

	public function testAfterResponseProcessing() {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getHeader')->with('OC-LazyOps')->willReturn(true);
		$request->expects(self::once())->method('removeHeader')->with('OC-LazyOps')->willReturn(true);

		$response= $this->createMock(ResponseInterface::class);
		$response->expects($this->exactly(2))->method('getHeader')->withConsecutive(
			['OC-FileId'],
			['ETag']
		)->willReturnOnConsecutiveCalls(
			'oc1234',
			'"abcdef"'
		);

		$this->jobStatusMapper->expects(self::once())
			->method('insert')->willReturnCallback(function (JobStatus $entity) {
				self::assertEquals('alice', $entity->getUserId());
				$info = \json_decode($entity->getStatusInfo(), true);
				self::assertSame('started', $info['status']);
				self::assertIsInt($info['heartbeat']);
			});
		$this->jobStatusMapper->expects(self::once())
			->method('update')->willReturnCallback(function (JobStatus $entity) {
				self::assertEquals('alice', $entity->getUserId());
				self::assertEquals('{"status":"finished","fileId":"oc1234","ETag":"\"abcdef\""}', $entity->getStatusInfo());
			});

		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method('emit')->with('method:MOVE');

		$this->plugin->initialize($server);
		$this->plugin->afterResponse($request, $response);
	}

	public function testAfterResponseProcessingThrowingAnException() {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getHeader')->with('OC-LazyOps')->willReturn(true);
		$request->expects(self::once())->method('removeHeader')->with('OC-LazyOps')->willReturn(true);

		$response= $this->createMock(ResponseInterface::class);

		$this->jobStatusMapper->expects(self::once())
			->method('insert')->willReturnCallback(function (JobStatus $entity) {
				self::assertEquals('alice', $entity->getUserId());
				$info = \json_decode($entity->getStatusInfo(), true);
				self::assertSame('started', $info['status']);
				self::assertIsInt($info['heartbeat']);
			});
		$this->jobStatusMapper->expects(self::once())
			->method('update')->willReturnCallback(function (JobStatus $entity) {
				self::assertEquals('alice', $entity->getUserId());
				self::assertEquals('{"status":"error","errorCode":404,"errorMessage":""}', $entity->getStatusInfo());
			});

		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method('emit')->willThrowException(new NotFound());

		$this->plugin->initialize($server);
		$this->plugin->afterResponse($request, $response);
	}

	private function buildNode(string $name, string $data) {
		$node = $this->getMockBuilder('\Sabre\DAV\File')
			->setMethods(['getName', 'get', 'getSize'])
			->getMockForAbstractClass();
		$node->method('getName')->willReturn($name);
		$node->method('get')->willReturn($data);
		$node->method('getSize')->willReturn(\strlen($data));
		return $node;
	}

	private function lazyRequest() {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getHeader')->with('OC-LazyOps')->willReturn(true);
		return $request;
	}

	private function assemblingServer() {
		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method('emit')->with('method:MOVE')
			->willReturnCallback(function () {
				// liest den Zusammenbau wie File::put in kleinen Stücken
				$stream = AssemblyStream::wrap([$this->buildNode('0', 'abcdef'), $this->buildNode('6', 'ghij')]);
				while (!\feof($stream)) {
					\fread($stream, 3);
				}
				\fclose($stream);
				return true;
			});
		return $server;
	}

	public function testAfterResponseReportsAssemblyProgressAndClearsTheListener() {
		(new \ReflectionProperty(LazyOpsPlugin::class, 'progressInterval'))->setValue($this->plugin, 0);
		$reports = [];
		$this->jobStatusMapper->expects(self::once())->method('insert');
		$this->jobStatusMapper->method('update')->willReturnCallback(function (JobStatus $entity) use (&$reports) {
			$reports[] = \json_decode($entity->getStatusInfo(), true);
		});

		$this->plugin->initialize($this->assemblingServer());
		$this->plugin->afterResponse($this->lazyRequest(), $this->createMock(ResponseInterface::class));

		$last = \array_pop($reports);
		self::assertSame('finished', $last['status']);
		self::assertNotEmpty($reports);
		foreach ($reports as $report) {
			self::assertSame('started', $report['status']);
			self::assertSame(10, $report['total']);
			self::assertIsInt($report['heartbeat']);
		}
		self::assertSame(10, \end($reports)['progress']);

		$listener = new \ReflectionProperty(AssemblyStream::class, 'progressListener');
		self::assertNull($listener->getValue());
	}

	public function testALostProgressReportDoesNotAbortTheAssembly() {
		(new \ReflectionProperty(LazyOpsPlugin::class, 'progressInterval'))->setValue($this->plugin, 0);
		$calls = 0;
		$final = null;
		$this->jobStatusMapper->method('update')->willReturnCallback(function (JobStatus $entity) use (&$calls, &$final) {
			if (++$calls === 1) {
				throw new \RuntimeException('Datenbank kurz weg');
			}
			$final = \json_decode($entity->getStatusInfo(), true);
		});
		$this->logger->expects(self::once())->method('logException');

		$this->plugin->initialize($this->assemblingServer());
		$this->plugin->afterResponse($this->lazyRequest(), $this->createMock(ResponseInterface::class));

		self::assertSame('finished', $final['status']);
	}
}
