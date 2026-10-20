<?php
/**
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
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

namespace OC\Settings\Controller;

use OC\Log\LogFileReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IMemcache;
use OCP\IRequest;
use OCP\IConfig;
use OCP\AppFramework\Http\DataResponse;
use OCP\IUserSession;

/**
 * Class LogSettingsController
 *
 * @package OC\Settings\Controller
 */
class LogSettingsController extends Controller {
	/** Abrufe der Protokolleinträge je Admin und Minute */
	public const ENTRIES_PER_MINUTE = 60;
	public const DEFAULT_COUNT = 50;
	private const MAX_SEARCH_LENGTH = 200;
	private const MAX_APP_LENGTH = 100;

	/**
	 * @var \OCP\IConfig
	 */
	private $config;

	/**
	 * @var \OCP\IL10N
	 */
	private $l10n;

	/** @var IUserSession */
	private $userSession;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ICacheFactory */
	private $cacheFactory;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var LogFileReader */
	private $logReader;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $config
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IConfig $config,
		IL10N $l10n,
		IUserSession $userSession,
		IGroupManager $groupManager,
		ICacheFactory $cacheFactory,
		ITimeFactory $timeFactory,
		LogFileReader $logReader
	) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->l10n = $l10n;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->cacheFactory = $cacheFactory;
		$this->timeFactory = $timeFactory;
		$this->logReader = $logReader;
	}

	/**
	 * set log level for logger
	 *
	 * @param int $level
	 * @return DataResponse
	 */
	public function setLogLevel($level) {
		if ($level < 0 || $level > 4) {
			return new DataResponse([
				'status' => 'error',
				'data' => [
					'message' => (string)$this->l10n->t('log-level out of allowed range'),
				],
			], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setSystemValue('loglevel', $level);
		return new DataResponse([
			'status' => 'success',
			'data' => [
				'message' => $this->l10n->t('Updated log level'),
				'level' => $level
			]
		]);
	}

	/**
	 * download logfile
	 *
	 * @NoCSRFRequired
	 *
	 * @return StreamResponse
	 */
	public function download() {
		$resp = new StreamResponse(\OC\Log\Owncloud::getLogFilePath());
		$resp->addHeader('Content-Type', 'application/octet-stream');
		$resp->addHeader('Content-Disposition', 'attachment; filename="owncloud.log"');
		return $resp;
	}

	/**
	 * Neueste Einträge der Protokolldatei als JSON, für die Protokollansicht
	 * unter Einstellungen > Allgemein und für Werkzeuge.
	 *
	 * Nur für Admins. Die Annotationen NoAdminRequired und NoSubadminRequired
	 * stehen hier, weil die SecurityMiddleware und die SubadminMiddleware den
	 * Status des Benutzers bestimmen, bevor eine Anmeldung per Basic-Auth oder
	 * App-Passwort greift, und solche Admins sonst mit 403 abweisen. Die Methode
	 * prüft den Admin deshalb selbst. Der CSRF-Schutz bleibt: Die Ansicht schickt
	 * den requesttoken mit, Werkzeuge melden sich mit einem Authorization-Kopf an.
	 *
	 * @NoAdminRequired
	 * @NoSubadminRequired
	 *
	 * @param int $offset next_offset der vorigen Antwort, 0 = ab den neuesten Einträgen
	 * @param int $count Anzahl Einträge, 1 bis 200
	 * @param string $level Mindeststufe 0 (Debug) bis 4 (Fatal), leer = alle
	 * @param string $app nur Einträge dieser App
	 * @param string $search Suchtext
	 * @return JSONResponse
	 */
	public function getEntries($offset = 0, $count = self::DEFAULT_COUNT, $level = '', $app = '', $search = '') {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			return new JSONResponse(['message' => 'Logged in user must be an admin'], Http::STATUS_FORBIDDEN);
		}

		foreach ([$level, $app, $search] as $parameter) {
			// level[]=1 und Ähnliches: kein Text, keine Warnung „Array to string“
			if ($parameter !== null && !\is_scalar($parameter)) {
				return new JSONResponse(['message' => 'level, app and search must be text'], Http::STATUS_BAD_REQUEST);
			}
		}
		$level = \trim((string)$level);
		if ($level !== '' && (!\ctype_digit($level) || (int)$level > 4)) {
			return new JSONResponse(['message' => 'level must be a number from 0 to 4'], Http::STATUS_BAD_REQUEST);
		}
		$app = \mb_substr(\trim((string)$app), 0, self::MAX_APP_LENGTH, 'UTF-8');
		$search = \mb_substr(\trim((string)$search), 0, self::MAX_SEARCH_LENGTH, 'UTF-8');

		$retryAfter = $this->rateLimit($user->getUID());
		if ($retryAfter > 0) {
			$response = new JSONResponse(['message' => 'Too many requests'], Http::STATUS_TOO_MANY_REQUESTS);
			$response->addHeader('Retry-After', (string)$retryAfter);
			return $response;
		}

		if ($this->config->getSystemValue('log_type', 'owncloud') !== 'owncloud') {
			return new JSONResponse(['message' => 'The log is not written to a file (log_type)'], Http::STATUS_NOT_FOUND);
		}
		if (!$this->logReader->isReadable()) {
			return new JSONResponse(['message' => 'The log file does not exist or cannot be read'], Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->logReader->read(
				\max(0, (int)$offset),
				(int)$count,
				$level === '' ? null : (int)$level,
				$app,
				$search
			);
		} catch (\RuntimeException) {
			return new JSONResponse(['message' => 'The log file cannot be read'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse($result);
	}

	/**
	 * Zählt die Abrufe je Admin in einem Minutenfenster. Ohne Memcache bleibt der
	 * Zähler wirkungslos (NullCache); die Größenbudgets von LogFileReader gelten
	 * trotzdem.
	 *
	 * @return int Sekunden bis zum nächsten erlaubten Abruf, 0 = erlaubt
	 */
	private function rateLimit(string $uid): int {
		$now = $this->timeFactory->getTime();
		$window = \intdiv($now, 60);
		$key = \md5($uid) . '-' . $window;
		$cache = $this->cacheFactory->create('settings_log_entries');
		if ($cache instanceof IMemcache) {
			$cache->add($key, 0, 120);
			$calls = (int)$cache->inc($key);
		} else {
			$calls = (int)$cache->get($key) + 1;
			$cache->set($key, $calls, 120);
		}
		if ($calls <= self::ENTRIES_PER_MINUTE) {
			return 0;
		}
		return ($window + 1) * 60 - $now;
	}
}
