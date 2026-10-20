<?php
/**
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
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
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-03-16.
 * Changes:
 *   - PHP 8.4 compatibility and owncloud.online design integration
 *   - php8.3 (#41449)
 */

namespace Test\User;

use OC\User\BasicAuthModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use Test\TestCase;

class BasicAuthModuleTest extends TestCase {
	/** @var IConfig | \PHPUnit\Framework\MockObject\MockObject */
	private $config;
	/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject */
	private $logger;
	/** @var IUserManager | \PHPUnit\Framework\MockObject\MockObject */
	private $manager;
	/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject */
	private $request;
	/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject */
	private $timeFactory;
	/** @var IUser | \PHPUnit\Framework\MockObject\MockObject */
	private $user;
	/** @var ISession | \PHPUnit\Framework\MockObject\MockObject */
	private $session;

	public function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->manager = $this->createMock(IUserManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(ISession::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->user = $this->createMock(IUser::class);
		$this->user->expects($this->any())->method('getUID')->willReturn('user1');

		$this->manager->expects($this->any())->method('checkPassword')
			->willReturnMap([
				['user1', '123456', $this->user],
				['user@example.com', '123456', $this->user],
				['user2', '123456', null],
				['not-unique@example.com', '123456', null],
				['unique@example.com', '123456', null],
			]);

		$this->manager->expects($this->any())->method('getByEmail')
			->willReturnMap([
				['not-unique@example.com', [$this->user, $this->user]],
				['unique@example.com', [$this->user]],
				['user2', []]
			]);

		// make config return default last_check_timeout
		$this->config->method('getAppValue')->with('core', 'last_check_timeout', 5)->willReturn(5);
	}

	/**
	 * @dataProvider providesCredentials
	 * @param mixed $expectedResult
	 * @param string $userId
	 */
	public function testAuth($expectedResult, $userId) {
		$this->session->method('exists')->will($this->returnValueMap([
			['app_password', false],
			['last_check_timeout', true]
		]));

		// check auth
		$time = \time();
		$this->session->method('get')->will($this->returnValueMap([
			['user_id', $userId],
			['last_check_timeout', $time - 60 * 5]
		]));
		$this->timeFactory->method('getTime')->willReturn($time);

		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);
		$this->request->server = [
			'PHP_AUTH_USER' => $userId,
			'PHP_AUTH_PW' => '123456',
		];
		if ($expectedResult instanceof \Exception) {
			$this->expectException(\get_class($expectedResult));
			$this->expectExceptionMessage($expectedResult->getMessage());
		}
		$this->assertEquals($expectedResult ? $this->user : null, $module->auth($this->request));
	}

	public function testAppPassword() {
		$this->session->method('exists')->will($this->returnValueMap([
			['app_password', true],
			['last_check_timeout', true]
		]));

		// check auth
		$time = \time();
		$this->session->method('get')->will($this->returnValueMap([
			['user_id', 'user'],
			['last_check_timeout', $time - 60 * 5]
		]));
		$this->timeFactory->method('getTime')->willReturn($time);

		$this->manager
			->expects($this->never())
			->method('checkPassword');

		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);
		$this->request->server = [
			'PHP_AUTH_USER' => 'user',
			'PHP_AUTH_PW' => 'app-pass-word',
		];
		$this->assertEquals(null, $module->auth($this->request));
	}

	public function testGetUserPassword() {
		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);
		$this->request->server = [
			'PHP_AUTH_USER' => 'user1',
			'PHP_AUTH_PW' => '123456',
		];
		$this->assertEquals('123456', $module->getUserPassword($this->request));

		$this->request->server = [];
		$this->assertEquals('', $module->getUserPassword($this->request));
	}

	public function providesCredentials() {
		return [
			'no user is' => [false, ''],
			'user1 can login' => [true, 'user1'],
			'user1 can login with email' => [true, 'user@example.com'],
			'unique email can login' => [true, 'unique@example.com'],
			'not unique email can not login' => [new \Exception('Invalid credentials'), 'not-unique@example.com'],
			'user2 is not known' => [new \Exception('Invalid credentials'), 'user2'],
		];
	}

	public function testTimeout() {
		$this->session->method('exists')->will($this->returnValueMap([
			['app_password', false],
			['last_check_timeout', true]
		]));

		$time = \time();
		$this->session->method('get')->will($this->returnValueMap([
			['last_check_timeout', $time - 60 * 4],
			['user_id', 'user1']
		]));

		$this->timeFactory->method('getTime')->willReturn($time);

		$this->manager
			->expects($this->never())
			->method('checkPassword');

		$this->manager->expects($this->once())->method('get')
			->with('user1')->willReturn($this->user);

		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);

		$this->request->server = [
			'PHP_AUTH_USER' => 'user1',
			'PHP_AUTH_PW' => '123456',
		];

		$this->assertEquals($this->user, $module->auth($this->request));
	}

	/**
	 * Am Token-Endpunkt der App oauth2 trägt HTTP-Basic Kennung und Geheimnis des
	 * Clients (RFC 6749, Abschnitt 2.3.1), keine Benutzerdaten. Als Anmeldung
	 * gewertet, stand bei jeder Token-Erneuerung „Login failed“ im Protokoll, die
	 * Bremse gegen Passwort-Raten zählte mit, und eine mitgeschickte Sitzung des
	 * Desktop-Clients wurde beendet.
	 */
	public function testClientCredentialsAtOAuth2TokenEndpointAreNoUserLogin() {
		$this->session->method('exists')->willReturn(false);
		$this->timeFactory->method('getTime')->willReturn(\time());
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getRawPathInfo')->willReturn('/apps/oauth2/api/v1/token');
		$this->request->server = [
			'PHP_AUTH_USER' => 'user2',
			'PHP_AUTH_PW' => '123456',
		];
		$this->manager->expects($this->never())->method('checkPassword');
		$this->manager->expects($this->never())->method('getByEmail');
		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);

		$user = $module->auth($this->request);

		$this->assertNull($user);
	}

	public function providesRequestsWhereBasicAuthIsAUserLogin() {
		return [
			'other route' => ['POST', '/apps/files/ajax/upload.php'],
			'GET on the token path' => ['GET', '/apps/oauth2/api/v1/token'],
			'trailing slash' => ['POST', '/apps/oauth2/api/v1/token/'],
			'longer path' => ['POST', '/apps/oauth2/api/v1/tokens'],
			'encoded path' => ['POST', '/apps/oauth2/api/v1/%74oken'],
			'token path below another prefix' => ['POST', '/apps/files/apps/oauth2/api/v1/token'],
		];
	}

	/**
	 * Überall sonst bleibt HTTP-Basic eine Benutzeranmeldung: falsche Daten
	 * werden geprüft und abgewiesen wie bisher.
	 *
	 * @dataProvider providesRequestsWhereBasicAuthIsAUserLogin
	 */
	public function testBasicAuthStaysAUserLoginElsewhere($method, $path) {
		$this->session->method('exists')->willReturn(false);
		$this->timeFactory->method('getTime')->willReturn(\time());
		$this->request->method('getMethod')->willReturn($method);
		$this->request->method('getRawPathInfo')->willReturn($path);
		$this->request->server = [
			'PHP_AUTH_USER' => 'user2',
			'PHP_AUTH_PW' => '123456',
		];
		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid credentials');
		$module->auth($this->request);
	}

	public function testBasicAuthStaysAUserLoginWhenThePathCannotBeDetermined() {
		$this->session->method('exists')->willReturn(false);
		$this->timeFactory->method('getTime')->willReturn(\time());
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getRawPathInfo')->willThrowException(new \Exception('The requested uri cannot be processed'));
		$this->request->server = [
			'PHP_AUTH_USER' => 'user2',
			'PHP_AUTH_PW' => '123456',
		];
		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid credentials');
		$module->auth($this->request);
	}

	public function invalidUserIdProvider() {
		return [
			[''], [null],
		];
	}

	/**
	 * @dataProvider invalidUserIdProvider
	 */
	public function testInvalidUserId($userId) {
		$this->expectException(\UnexpectedValueException::class);

		$this->session->method('exists')->will($this->returnValueMap([
			['app_password', false],
			['last_check_timeout', true]
		]));

		$time = \time();
		$this->session->method('get')->will($this->returnValueMap([
			['last_check_timeout', $time - 60 * 4],
			['user_id', $userId]
		]));

		$this->timeFactory->method('getTime')->willReturn($time);

		$this->manager
			->expects($this->never())
			->method('checkPassword');

		$this->manager
			->expects($this->never())
			->method('get');

		$module = new BasicAuthModule($this->config, $this->logger, $this->manager, $this->session, $this->timeFactory);

		$this->request->server = [
			'PHP_AUTH_USER' => 'user1',
			'PHP_AUTH_PW' => '123456',
		];

		$module->auth($this->request);
	}
}
