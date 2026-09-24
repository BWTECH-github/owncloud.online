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

namespace OCA\DAV\DAV;

use OCA\DAV\JobStatus\Entity\JobStatus;
use OCA\DAV\JobStatus\Entity\JobStatusMapper;
use OCA\DAV\Upload\AssemblyStream;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Shutdown\IShutdownManager;
use Sabre\DAV\Exception;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\UUIDUtil;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;

/**
 * Class LazyOpsPlugin
 *
 * @package OCA\DAV\DAV
 */
class LazyOpsPlugin extends ServerPlugin {
	/**
	 * Mindestabstand in Sekunden zwischen zwei Fortschrittsmeldungen im
	 * Auftragsstatus. Die Web-Oberfläche erkennt an ausbleibenden Meldungen,
	 * dass ein Zusammenbau abgebrochen ist (etwa durch einen PHP-FPM-Neustart).
	 */
	public const PROGRESS_INTERVAL = 10;

	/** @var int Abstand der Fortschrittsmeldungen in Sekunden (Tests setzen 0) */
	private $progressInterval = self::PROGRESS_INTERVAL;

	/** @var Server */
	private $server;
	/** @var string */
	private $jobId;
	/** @var JobStatus */
	private $entity;
	/** @var IUserSession */
	private $userSession;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IShutdownManager */
	private $shutdownManager;
	/** @var ILogger */
	private $logger;
	/** @var JobStatusMapper */
	private $mapper;

	public function __construct(
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		IShutdownManager $shutdownManager,
		JobStatusMapper $jobStatusMapper,
		ILogger $logger
	) {
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->shutdownManager = $shutdownManager;
		$this->logger = $logger;
		$this->mapper = $jobStatusMapper;
	}

	/**
	 * @param Server $server
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		$server->on('method:MOVE', [$this, 'httpMove'], 90);
	}

	/**
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 * @return bool
	 * @throws Exception\NotAuthenticated
	 */
	public function httpMove(RequestInterface $request, ResponseInterface $response) {
		if (!$request->getHeader('OC-LazyOps')) {
			return true;
		}

		$this->jobId = UUIDUtil::getUUID();
		$this->setJobStatus([
			'status' => 'init'
		]);
		$userId = $this->getUserId();
		$location = $this->urlGenerator
				->linkTo('', 'remote.php') . "/dav/job-status/{$userId}/{$this->jobId}";

		$response->setStatus(202);
		$response->setHeader('Connection', 'close');
		// Ohne Content-Length wartet der Client bis zum Skriptende, also bis
		// der Zusammenbau fertig ist – die 202 wäre dann wertlos.
		$response->setHeader('Content-Length', '0');
		$response->setHeader('OC-JobStatus-Location', $location);

		$this->shutdownManager->register(function () use ($request, $response) {
			return $this->afterResponse($request, $response);
		}, IShutdownManager::HIGH);

		return false;
	}

	public function afterResponse(RequestInterface $request, ResponseInterface $response) {
		if (!$request->getHeader('OC-LazyOps')) {
			return true;
		}

		\flush();
		// Unter PHP-FPM hält nginx die Verbindung sonst bis zum Skriptende;
		// die Antwort geht jetzt raus, der Zusammenbau läuft losgelöst weiter.
		if (\function_exists('fastcgi_finish_request')) {
			\fastcgi_finish_request();
		}
		$request->removeHeader('OC-LazyOps');
		$responseDummy = new Response();
		$lastReport = \time();
		AssemblyStream::setProgressListener(function ($position, $size) use (&$lastReport) {
			$now = \time();
			if ($now - $lastReport < $this->progressInterval) {
				return;
			}
			$lastReport = $now;
			try {
				$this->setJobStatus([
					'status' => 'started',
					'progress' => $position,
					'total' => $size,
					'heartbeat' => $now
				]);
			} catch (\Throwable $e) {
				// eine verlorene Zwischenmeldung darf den Zusammenbau nicht abbrechen
				$this->logger->logException($e, ['app' => 'dav']);
			}
		});
		try {
			$this->setJobStatus([
				'status' => 'started',
				'heartbeat' => $lastReport
			]);
			$this->server->emit('method:MOVE', [$request, $responseDummy]);

			$this->setJobStatus([
				'status' => 'finished',
				'fileId' => $response->getHeader('OC-FileId'),
				'ETag' => $response->getHeader('ETag')
			]);
		} catch (\Throwable $ex) {
			$this->logger->logException($ex);

			$this->setJobStatus([
				'status' => 'error',
				'errorCode' => $ex instanceof Exception ? $ex->getHTTPCode() : 500,
				'errorMessage' => $ex->getMessage()
			]);
		} finally {
			AssemblyStream::setProgressListener(null);
		}
		return false;
	}

	private function setJobStatus(array $status) {
		if ($this->entity === null) {
			$userId = $this->getUserId();

			$this->entity = new JobStatus();
			$this->entity->setStatusInfo(\json_encode($status));
			$this->entity->setUserId($userId);
			$this->entity->setUuid($this->jobId);
			$this->mapper->insert($this->entity);
		} else {
			$this->entity->setStatusInfo(\json_encode($status));
			$this->mapper->update($this->entity);
		}
	}

	/**
	 * @return string
	 * @throws Exception\NotAuthenticated
	 */
	private function getUserId() {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception\NotAuthenticated();
		}
		return $user->getUID();
	}
}
