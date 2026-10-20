<?php
/**
 * @author Georg Ehrke
 * @copyright Copyright (c) 2014 Georg Ehrke <georg@ownCloud.com>
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Tests\Settings\Controller;

use OC\Log\LogFileReader;
use OC\Settings\Application;
use OC\Settings\Controller\LogSettingsController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IMemcache;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * @package Tests\Settings\Controller
 */
class LogSettingsControllerTest extends \Test\TestCase {
	/** @var \OCP\AppFramework\IAppContainer */
	private $container;

	/** @var LogSettingsController */
	private $logSettingsController;

	protected function setUp(): void {
		$app = new Application();
		$this->container = $app->getContainer();
		$this->container['Config'] = $this->getMockBuilder('\OCP\IConfig')
			->disableOriginalConstructor()->getMock();
		$this->container['AppName'] = 'settings';
		$this->logSettingsController = $this->container['LogSettingsController'];
	}

	/**
	 * @dataProvider logLevelData
	 */
	public function testSetLogLevel($level, $inRange) {
		if ($inRange) {
			$this->container['Config']
				->expects($this->once())
				->method('setSystemValue')
				->with('loglevel', $level);
		}

		$response = $this->logSettingsController->setLogLevel($level)->getData();

		if ($inRange) {
			$expectedResponse = ['status' => 'success', 'data' => ['message' => 'Updated log level', 'level' => $level]];
		} else {
			$expectedResponse = ['status' => 'error', 'data' => ['message' => 'log-level out of allowed range']];
		}

		$this->assertSame($expectedResponse, $response);
	}

	public function logLevelData() {
		return [
			[-1, false],
			[0, true],
			[1, true],
			[2, true],
			[3, true],
			[4, true],
			[5, false],
		];
	}

	public function testDownload() {
		$response = $this->logSettingsController->download();

		$this->assertInstanceOf('\OCP\AppFramework\Http\StreamResponse', $response);
		$headers = $response->getHeaders();
		$this->assertEquals('application/octet-stream', $headers['Content-Type']);
		$this->assertEquals('attachment; filename="owncloud.log"', $headers['Content-Disposition']);
	}

	/**
	 * Controller für getEntries() mit nachgebauten Abhängigkeiten.
	 *
	 * @param string|null $uid angemeldeter Benutzer, null = niemand
	 * @param int $callsThisMinute was der Zähler im Memcache nach diesem Abruf meldet
	 */
	private function entriesController(?string $uid, bool $isAdmin, ?LogFileReader $reader = null, int $callsThisMinute = 1, string $logType = 'owncloud'): LogSettingsController {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static function ($key, $default = '') use ($logType) {
			return $key === 'log_type' ? $logType : $default;
		});

		$userSession = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$userSession->method('getUser')->willReturn($user);
		}
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static function ($checkedUid) use ($uid, $isAdmin) {
			return $checkedUid === $uid && $isAdmin;
		});

		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturn($callsThisMinute);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('create')->willReturn($cache);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1760000085); // 45 s in der Minute

		if ($reader === null) {
			$reader = $this->createMock(LogFileReader::class);
			$reader->method('isReadable')->willReturn(true);
		}

		return new LogSettingsController(
			'settings',
			$this->createMock(IRequest::class),
			$config,
			$this->createMock(IL10N::class),
			$userSession,
			$groupManager,
			$cacheFactory,
			$timeFactory,
			$reader
		);
	}

	public function nonAdminProvider() {
		return [
			'nicht angemeldet' => [null, false],
			'normaler Benutzer' => ['bob', false],
		];
	}

	/**
	 * @dataProvider nonAdminProvider
	 */
	public function testGetEntriesIsForAdminsOnly(?string $uid, bool $isAdmin) {
		$reader = $this->createMock(LogFileReader::class);
		$reader->expects($this->never())->method('read');

		$response = $this->entriesController($uid, $isAdmin, $reader)->getEntries();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testGetEntriesPassesValidatedFiltersToTheReader() {
		$result = ['entries' => [['message' => 'x']], 'next_offset' => 17, 'restarted' => false, 'size' => 99, 'scanned' => 82];
		$reader = $this->createMock(LogFileReader::class);
		$reader->method('isReadable')->willReturn(true);
		$reader->expects($this->once())->method('read')
			->with(1234, 500, 3, 'webdav', \str_repeat('ä', 200))
			->willReturn($result);

		$response = $this->entriesController('admin', true, $reader)
			->getEntries(1234, 500, '3', '  webdav ', \str_repeat('ä', 250));

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testGetEntriesWithoutLevelReadsAllLevels() {
		$reader = $this->createMock(LogFileReader::class);
		$reader->method('isReadable')->willReturn(true);
		$reader->expects($this->once())->method('read')
			->with(0, 50, null, '', '')
			->willReturn(['entries' => [], 'next_offset' => null, 'restarted' => false, 'size' => 0, 'scanned' => 0]);

		$response = $this->entriesController('admin', true, $reader)->getEntries(-5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function invalidLevelProvider() {
		return [['5'], ['-1'], ['fatal'], ['1.5']];
	}

	/**
	 * @dataProvider invalidLevelProvider
	 */
	public function testGetEntriesRejectsAnInvalidLevel(string $level) {
		$response = $this->entriesController('admin', true)->getEntries(0, 50, $level);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function arrayParameterProvider() {
		return [
			'level' => [['3'], '', ''],
			'app' => ['', ['files'], ''],
			'search' => ['', '', ['x']],
		];
	}

	/**
	 * @dataProvider arrayParameterProvider
	 */
	public function testGetEntriesRejectsArrayParameters($level, $app, $search) {
		$reader = $this->createMock(LogFileReader::class);
		$reader->expects($this->never())->method('read');

		$response = $this->entriesController('admin', true, $reader)->getEntries(0, 50, $level, $app, $search);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testGetEntriesIsRateLimited() {
		$reader = $this->createMock(LogFileReader::class);
		$reader->method('isReadable')->willReturn(true);
		$reader->expects($this->never())->method('read');

		$response = $this->entriesController('admin', true, $reader, LogSettingsController::ENTRIES_PER_MINUTE + 1)->getEntries();

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$this->assertSame('15', $response->getHeaders()['Retry-After']);
	}

	public function testGetEntriesNeedsTheFileLogger() {
		$response = $this->entriesController('admin', true, null, 1, 'syslog')->getEntries();
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testGetEntriesWithMissingFile() {
		$reader = $this->createMock(LogFileReader::class);
		$reader->method('isReadable')->willReturn(false);
		$reader->expects($this->never())->method('read');

		$response = $this->entriesController('admin', true, $reader)->getEntries();
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testGetEntriesReadsARealFile() {
		$file = \OC::$server->getTempManager()->getTemporaryFile('logentries');
		\file_put_contents($file, \json_encode(['reqId' => 'r', 'level' => 3, 'time' => 't', 'remoteAddr' => '', 'user' => '--', 'app' => 'core', 'method' => 'GET', 'url' => '/', 'message' => 'echt']) . "\n");

		$response = $this->entriesController('admin', true, new LogFileReader($file))->getEntries();
		\unlink($file);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('echt', $response->getData()['entries'][0]['message']);
		$this->assertNotFalse(\json_encode($response->getData()));
	}

	/**
	 * Die Middleware liest jedes At-Zeichen vor einem großgeschriebenen Wort im
	 * Kommentar als Annotation – auch im Fließtext. Hier dürfen nur die beiden
	 * stehen, die die Admin-Prüfung in die Methode verlagern: kein Verzicht auf
	 * CSRF-Schutz, keine öffentliche Seite.
	 */
	public function testGetEntriesKeepsCsrfProtection() {
		$docs = (new \ReflectionMethod(LogSettingsController::class, 'getEntries'))->getDocComment();
		\preg_match_all('/@([A-Z]\w+)/', $docs, $matches);
		$this->assertSame(['NoAdminRequired', 'NoSubadminRequired'], $matches[1]);
	}
}
