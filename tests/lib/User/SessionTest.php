<?php

/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-03.
 * Changes:
 *   - kill public-upload reload loop, parallelise small uploads, batch propagation
 */

namespace Test\User;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OC\AppFramework\Http\Request;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\DefaultToken;
use OC\Authentication\Token\DefaultTokenProvider;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\Security\CSRF\CsrfTokenManager;
use OC\Session\Memory;

use OC\User\Manager;
use OC\User\Session;
use OCP\App\IServiceLoader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\IAuthModule;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Session\Exceptions\SessionNotAvailableException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;
use Test\TestCase;
use OCP\Authentication\IApacheBackend;
use OCP\UserInterface;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;

/**
 * @group DB
 * @package Test\User
 */
class SessionTest extends TestCase {
	/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject */
	private $timeFactory;

	/** @var DefaultTokenProvider | \PHPUnit\Framework\MockObject\MockObject */
	protected $tokenProvider;

	/** @var IConfig | \PHPUnit\Framework\MockObject\MockObject */
	private $config;
	/** @var ILogger | \PHPUnit\Framework\MockObject\MockObject */
	private $logger;
	/** @var IServiceLoader */
	private $serviceLoader;
	/** @var \OC\User\SyncService */
	protected $userSyncService;
	/** @var  EventDispatcher */
	protected $eventDispatcher;

	private $rootNode;
	private $userNode;

	protected function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->expects($this->any())
			->method('getTime')
			->will($this->returnValue(10000));
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->serviceLoader = $this->createMock(IServiceLoader::class);
		$this->userSyncService = $this->createMock(\OC\User\SyncService::class);
		$this->eventDispatcher = new EventDispatcher();

		// need to overwrite the getUserFolder for the login tests
		$this->userNode = $this->createMock(Folder::class);
		$this->rootNode = $this->createMock(IRootFolder::class);
		$this->rootNode->method('getUserFolder')->willReturn($this->userNode);
		$this->overwriteService('RootFolder', $this->rootNode);
	}

	protected function tearDown(): void {
		$this->restoreService('RootFolder');
		parent::tearDown();
	}

	public function testGetUser() {
		$token = new DefaultToken();
		$token->setLoginName('User123');
		$token->setLastCheck(200);

		/** @var IUser | \PHPUnit\Framework\MockObject\MockObject $expectedUser */
		$expectedUser = $this->createMock(IUser::class);
		$expectedUser->expects($this->any())
			->method('getUID')
			->will($this->returnValue('user123'));
		/** @var Memory | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session
			->expects($this->exactly(2))
			->method('get')
			->withConsecutive(
				['user_id'],
				['app_password'],
			)
			->willReturnOnConsecutiveCalls(
				$expectedUser->getUID(),
				null, // No password set -> browser session
			);
		$sessionId = 'abcdef12345';

		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with($sessionId)
			->will($this->returnValue($token));
		$this->tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, $sessionId)
			->will($this->returnValue('passme'));
		$manager->expects($this->once())
			->method('checkPassword')
			->with('User123', 'passme')
			->will($this->returnValue(true));
		$expectedUser->expects($this->once())
			->method('isEnabled')
			->will($this->returnValue(true));
				
		$this->tokenProvider->expects($this->once())
			->method('updateTokenActivity')
			->with($token);

		$manager
			->method('get')
			//->with($expectedUser->getUID())
			->will($this->returnValue($expectedUser));

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		$user = $userSession->getUser();
		$this->assertSame($expectedUser, $user);
		$userSession->validateSession();
		$this->assertSame(10000, $token->getLastCheck());
	}

	public function isLoggedInData() {
		return [
			'User is logged in' => [true],
			'User is not logged in' => [false],
		];
	}

	/**
	 * @dataProvider isLoggedInData
	 * @param $isLoggedIn
	 */
	public function testIsLoggedIn($isLoggedIn) {
		$session = $this->createMock(Memory::class);

		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();

		/** @var \PHPUnit\Framework\MockObject\MockObject | Session $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods([
				'getUser'
			])
			->getMock();
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())->method('isEnabled')->willReturn(true);
		$userSession->expects($this->once())
			->method('getUser')
			->will($this->returnValue($isLoggedIn ? $user : null));
		$this->assertEquals($isLoggedIn, $userSession->isLoggedIn());
	}

	public function testSetUser() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session->expects($this->once())
			->method('set')
			->with('user_id', 'foo');

		/** @var Manager $manager */
		$manager = $this->createMock(Manager::class);

		/** @var IUser | \PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())
			->method('getUID')
			->will($this->returnValue('foo'));

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		$userSession->setUser($user);
	}

	public function testLoginValidPasswordEnabled() {
		$session = $this->createMock(Memory::class);
		$session->expects($this->once())
			->method('regenerateId');
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('bar')
			->will($this->throwException(new InvalidTokenException()));
		$session->expects($this->exactly(2))
			->method('set')
			->with($this->callback(function ($key) {
				switch ($key) {
					case 'user_id':
					case 'loginname':
						return true;
						break;
					default:
						return false;
						break;
				}
			}));

		$managerMethods = \get_class_methods(Manager::class);
		//keep following methods intact in order to ensure hooks are
		//working
		$doNotMock = ['__construct', 'emit', 'listen'];
		foreach ($doNotMock as $methodName) {
			$i = \array_search($methodName, $managerMethods, true);
			if ($i !== false) {
				unset($managerMethods[$i]);
			}
		}
		//		$manager = $this->getMockBuilder(Manager::class)
		//			->setMethods($managerMethods)
		//			->getMock();

		$manager = $this->createMock(Manager::class);
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('isEnabled')
			->will($this->returnValue(true));
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('foo'));
		$user->expects($this->once())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue($user));

		/** @var $eventDispatcher | \PHPUnit\Framework\MockObject\MockObject */
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $eventDispatcher])
			->setMethods([
				'prepareUserLogin'
			])
			->getMock();
		$userSession->expects($this->once())
			->method('prepareUserLogin');
		$beforeEvent = new GenericEvent(
			null,
			['loginType' => 'password', 'login' => 'foo', 'uid' => 'foo',
				'_uid' => 'deprecated: please use \'login\', the real uid is not yet known',
				'password' => 'bar']
		);
		$afterEvent = new GenericEvent(
			null,
			['loginType' => 'password', 'user' => $user,
				'uid' => 'foo', 'password' => 'bar'
			]
		);
		$eventDispatcher->expects($this->exactly(2))
			->method('dispatch')
			->withConsecutive(
				[$this->equalTo($beforeEvent), $this->equalTo('user.beforelogin')],
				[$this->equalTo($afterEvent), $this->equalTo('user.afterlogin')]
			);
		$userSession->login('foo', 'bar');
	}

	/**
	 */
	public function testLoginValidPasswordDisabled() {
		$this->expectException(\OC\User\LoginException::class);

		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('bar')
			->will($this->throwException(new InvalidTokenException()));

		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);

		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('isEnabled')
			->will($this->returnValue(false));
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue($user));

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		$userSession->login('foo', 'bar');
	}

	public function testLoginInvalidPassword() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);
		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$user = $this->createMock(IUser::class);

		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('bar')
			->will($this->throwException(new InvalidTokenException()));

		$user->expects($this->never())
			->method('isEnabled');
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue(false));

		$userSession->login('foo', 'bar');
	}

	public function testLoginNonExisting() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);
		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('bar')
			->will($this->throwException(new InvalidTokenException()));

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue(false));

		$userSession->login('foo', 'bar');
	}

	/**
	 * When using a device token, the loginname must match the one that was used
	 * when generating the token on the browser.
	 */
	public function testLoginWithDifferentTokenLoginName() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);
		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		$username = 'user123';
		$token = new DefaultToken();
		$token->setLoginName($username);

		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');
		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('bar')
			->will($this->returnValue($token));

		$manager->expects($this->once())
			->method('checkPassword')
			->with('foo', 'bar')
			->will($this->returnValue(false));

		$userSession->login('foo', 'bar');
	}

	/**
	 */
	public function testLogClientInNoTokenPasswordWith2fa() {
		$this->expectException(\OC\Authentication\Exceptions\PasswordLoginForbiddenException::class);

		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		$session = $this->createMock(ISession::class);
		/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('12.34.56.78');

		/** @var EventDispatcher | \PHPUnit\Framework\MockObject\MockObject $eventDispatcher */
		$eventDispatcher = $this->createMock(EventDispatcher::class);

		/** @var Session $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $eventDispatcher])
			->setMethods(['login', 'supportsCookies', 'createSessionToken', 'getUser'])
			->getMock();

		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('doe')
			->will($this->throwException(new InvalidTokenException()));
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('token_auth_enforced', false)
			->will($this->returnValue(true));
		$this->logger->expects($this->once())->method('warning')->with("Login failed: 'john' (Remote IP: '12.34.56.78')");
		$eventDispatcher->expects($this->once())->method('dispatch')->with(new GenericEvent(null, ['user' => 'john']), 'user.loginfailed');

		$userSession->logClientIn('john', 'doe', $request);
	}

	public function dataLogClientInUnexist() {
		return [
			[false, 2],
			[true, 0],
		];
	}

	/**
	 * @dataProvider dataLogClientInUnexist
	 * @param $strictLoginCheck
	 * @param $expectedGetByEmailCalls
	 */
	public function testLogClientInUnexist($strictLoginCheck, $expectedGetByEmailCalls) {
		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		$session = $this->createMock(ISession::class);
		/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		/** @var Session $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods(['login', 'supportsCookies', 'createSessionToken', 'getUser'])
			->getMock();

		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('doe')
			->will($this->throwException(new InvalidTokenException()));
		$this->config
			->expects($this->exactly(3))
			->method('getSystemValue')
			->withConsecutive(
				['token_auth_enforced', false],
				['strict_login_enforced', false],
				['strict_login_enforced', false],
			)
			->willReturnOnConsecutiveCalls(
				false,
				$strictLoginCheck,
				$strictLoginCheck,
			);
		$manager->expects($this->exactly($expectedGetByEmailCalls))
			->method('getByEmail')
			->willReturn([]);

		$this->assertFalse($userSession->logClientIn('unexist', 'doe', $request));
	}

	public function testLogClientInWithTokenPassword() {
		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		$session = $this->createMock(ISession::class);
		/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods(['isTokenPassword', 'login', 'supportsCookies', 'createSessionToken', 'getUser'])
			->getMock();

		$userSession->expects($this->once())
			->method('isTokenPassword')
			->will($this->returnValue(true));
		$userSession->expects($this->once())
			->method('login')
			->with('john', 'I-AM-AN-APP-PASSWORD')
			->will($this->returnValue(true));

		$session->expects($this->once())
			->method('set')
			->with('app_password', 'I-AM-AN-APP-PASSWORD');

		$this->assertTrue($userSession->logClientIn('john', 'I-AM-AN-APP-PASSWORD', $request));
	}

	/**
	 */
	public function testLogClientInNoTokenPasswordNo2fa() {
		$this->expectException(\OC\Authentication\Exceptions\PasswordLoginForbiddenException::class);

		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		$session = $this->createMock(ISession::class);
		/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('12.34.56.78');

		/** @var EventDispatcher | \PHPUnit\Framework\MockObject\MockObject $eventDispatcher */
		$eventDispatcher = $this->createMock(EventDispatcher::class);

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $eventDispatcher])
			->setMethods(['login', 'isTwoFactorEnforced'])
			->getMock();

		$this->tokenProvider->expects($this->once())
			->method('getToken')
			->with('doe')
			->will($this->throwException(new InvalidTokenException()));
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('token_auth_enforced', false)
			->will($this->returnValue(false));

		$userSession->expects($this->once())
			->method('isTwoFactorEnforced')
			->with('john')
			->will($this->returnValue(true));
		$this->logger->expects($this->once())->method('warning')->with("Login failed: 'john' (Remote IP: '12.34.56.78')");
		$eventDispatcher->expects($this->once())->method('dispatch')->with(new GenericEvent(null, ['user' => 'john']), 'user.loginfailed');

		$userSession->logClientIn('john', 'doe', $request);
	}

	public function testRememberLoginValidToken() {
		$session = $this->createMock(Memory::class);
		$session->expects($this->exactly(2))
			->method('set')
			->withConsecutive(
				['user_id', 'foo'],
				['loginname', 'foo'],
			);
		$session->expects($this->once())
			->method('regenerateId');

		$manager = $this->createMock(Manager::class);
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('foo'));
		$user->method('isEnabled')->willReturn(true);
		$user->expects($this->once())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('get')
			->with('foo')
			->will($this->returnValue($user));

		//prepare login token
		$token = 'goodToken';
		$this->config->method('getUserValue')
			->will($this->returnValueMap([
				['foo', 'login_token', \hash('snefru', $token), null, \time()],
			]));
		$this->config->expects($this->once())
			->method('deleteUserValue')
			->with('foo', 'login_token', \hash('snefru', $token));

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			//override, otherwise tests will fail because of setcookie()
			->setMethods(['setMagicInCookie'])
			//there  are passed as parameters to the constructor
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->getMock();

		$this->assertTrue($userSession->loginWithCookie('foo', $token));
	}

	public function testRememberLoginInvalidToken() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('foo'));
		$user->method('isEnabled')->willReturn(true);
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('get')
			->with('foo')
			->will($this->returnValue($user));

		//prepare login token
		$token = 'goodToken';
		$this->config->method('getUserValue')
			->will($this->returnValueMap([
				['foo', 'login_token', \sha1($token), null, \time()],
			]));
		$this->config->expects($this->never())
			->method('deleteUserValue')
			->with('foo', 'login_token', \sha1($token));

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$calledLoginFailedEvent = [];
		$this->eventDispatcher->addListener(
			'user.loginfailed',
			function (GenericEvent $event) use (&$calledLoginFailedEvent) {
				$calledLoginFailedEvent[] = 'user.loginfailed';
				$calledLoginFailedEvent[] = $event;
			}
		);

		$granted = $userSession->loginWithCookie('foo', 'badToken');

		$this->assertFalse($granted);
		$this->assertEquals('user.loginfailed', $calledLoginFailedEvent[0]);
		$this->assertInstanceOf(GenericEvent::class, $calledLoginFailedEvent[1]);
		$this->assertArrayHasKey('user', $calledLoginFailedEvent[1]);
		$this->assertEquals('foo', $calledLoginFailedEvent[1]->getArgument('user'));
	}

	public function testRememberLoginExpiredToken() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);
		$user = $this->createMock(IUser::class);
		$user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('foo'));
		$user->method('isEnabled')->willReturn(true);
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('get')
			->with('foo')
			->will($this->returnValue($user));

		//prepare login token
		$token = 'goodToken';
		$this->config->method('getUserValue')
			->will($this->returnValueMap([
				['foo', 'login_token', \hash('snefru', $token), null, "50"],
			]));
		$this->config->expects($this->once())
			->method('deleteUserValue')
			->with('foo', 'login_token', \hash('snefru', $token));

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$calledLoginFailedEvent = [];
		$this->eventDispatcher->addListener(
			'user.loginfailed',
			function (GenericEvent $event) use (&$calledLoginFailedEvent) {
				$calledLoginFailedEvent[] = 'user.loginfailed';
				$calledLoginFailedEvent[] = $event;
			}
		);

		$granted = $userSession->loginWithCookie('foo', $token);

		$this->assertFalse($granted);
		$this->assertEquals('user.loginfailed', $calledLoginFailedEvent[0]);
		$this->assertInstanceOf(GenericEvent::class, $calledLoginFailedEvent[1]);
		$this->assertArrayHasKey('user', $calledLoginFailedEvent[1]);
		$this->assertEquals('foo', $calledLoginFailedEvent[1]->getArgument('user'));
	}

	public function testRememberLoginInvalidUser() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session->expects($this->never())
			->method('set');
		$session->expects($this->once())
			->method('regenerateId');

		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);
		$user = $this->createMock(IUser::class);

		$user->expects($this->never())
			->method('getUID');
		$user->expects($this->never())
			->method('updateLastLoginTimestamp');

		$manager->expects($this->once())
			->method('get')
			->with('foo')
			->will($this->returnValue(null));

		//prepare login token
		$token = 'goodToken';
		\OC::$server->getConfig()->setUserValue('foo', 'login_token', $token, \time());

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		$granted = $userSession->loginWithCookie('foo', $token);

		$this->assertFalse($granted);
	}

	public function testActiveUserAfterSetSession() {
		$user1 = $this->createMock(IUser::class);
		$user2 = $this->createMock(IUser::class);
		$users = [
			'foo' => $user1,
			'bar' => $user2
		];

		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();

		$manager->expects($this->any())
			->method('get')
			->will($this->returnCallback(function ($uid) use ($users) {
				return $users[$uid];
			}));

		$session = new Memory('');
		$session->set('user_id', 'foo');
		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods([
				'validateSession'
			])
			->getMock();
		$userSession->expects($this->any())
			->method('validateSession');

		$this->assertEquals($users['foo'], $userSession->getUser());

		$session2 = new Memory('');
		$session2->set('user_id', 'bar');
		$userSession->setSession($session2);
		$this->assertEquals($users['bar'], $userSession->getUser());
	}

	public function testCreateSessionToken() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->createMock(Manager::class);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		$user = $this->createMock(IUser::class);
		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$random = $this->createMock(ISecureRandom::class);
		$config = $this->createMock(IConfig::class);
		$csrf = $this->getMockBuilder(CsrfTokenManager::class)
			->disableOriginalConstructor()
			->getMock();
		$request = new Request([
			'server' => [
				'HTTP_USER_AGENT' => 'Firefox',
			]
		], $random, $config, $csrf);

		$uid = 'user123';
		$loginName = 'User123';
		$password = 'passme';
		$sessionId = 'abcxyz';

		$manager->expects($this->once())
			->method('get')
			->with($uid)
			->will($this->returnValue($user));
		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		// getToken is now called twice: once for the idempotency pre-check on the
		// session id (miss → InvalidTokenException, so a new token is created), and
		// once inside getPassword() on the supplied password (also a miss here).
		$this->tokenProvider->expects($this->exactly(2))
			->method('getToken')
			->withConsecutive([$sessionId], [$password])
			->will($this->throwException(new InvalidTokenException()));

		$this->tokenProvider->expects($this->once())
			->method('generateToken')
			->with($sessionId, $uid, $loginName, $password, 'Firefox');

		$this->assertTrue($userSession->createSessionToken($request, $uid, $loginName, $password));
	}

	public function testCreateSessionTokenWithTokenPassword() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var IToken | \PHPUnit\Framework\MockObject\MockObject $token */
		$token = $this->createMock(IToken::class);
		$user = $this->createMock(IUser::class);
		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$random = $this->createMock(ISecureRandom::class);
		$config = $this->createMock(IConfig::class);
		$csrf = $this->getMockBuilder(CsrfTokenManager::class)
			->disableOriginalConstructor()
			->getMock();
		$request = new Request([
			'server' => [
				'HTTP_USER_AGENT' => 'Firefox',
			]
		], $random, $config, $csrf);

		$uid = 'user123';
		$loginName = 'User123';
		$password = 'iamatoken';
		$realPassword = 'passme';
		$sessionId = 'abcxyz';

		$manager->expects($this->once())
			->method('get')
			->with($uid)
			->will($this->returnValue($user));
		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		// getToken is called twice: first the idempotency pre-check on the session id
		// (miss → InvalidTokenException), then inside getPassword() on the supplied
		// password, which here resolves to an existing token carrying the real password.
		$this->tokenProvider->expects($this->exactly(2))
			->method('getToken')
			->withConsecutive([$sessionId], [$password])
			->willReturnCallback(function ($arg) use ($sessionId, $token) {
				if ($arg === $sessionId) {
					throw new InvalidTokenException();
				}
				return $token;
			});
		$this->tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, $password)
			->will($this->returnValue($realPassword));

		$this->tokenProvider->expects($this->once())
			->method('generateToken')
			->with($sessionId, $uid, $loginName, $realPassword, 'Firefox');

		$this->assertTrue($userSession->createSessionToken($request, $uid, $loginName, $password));
	}

	public function testCreateSessionTokenWithNonExistentUser() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $manager */
		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		/** @var IRequest $request */
		$request = $this->createMock(IRequest::class);

		$uid = 'user123';
		$loginName = 'User123';
		$password = 'passme';

		$manager->expects($this->once())
			->method('get')
			->with($uid)
			->will($this->returnValue(null));

		$this->assertFalse($userSession->createSessionToken($request, $uid, $loginName, $password));
	}

	public function testInvalidateSessionToken() {
		$manager = $this->createMock(Manager::class);
		$session = $this->createMock(ISession::class);

		$session->method('getId')->willReturn('sessionid');

		$this->tokenProvider->expects($this->once())
			->method('invalidateToken')
			->with($this->equalTo('sessionid'));

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		$this->assertNull($userSession->invalidateSessionToken());
	}

	/**
	 */
	public function testTryTokenLoginWithDisabledUser() {
		$this->expectException(\OC\User\LoginException::class);

		$manager = $this->getMockBuilder(Manager::class)
			->disableOriginalConstructor()
			->getMock();
		$session = new Memory('');
		$token = new DefaultToken();
		$token->setLoginName('fritz');
		$token->setUid('fritz0');
		$token->setLastCheck(100); // Needs check
		$user = $this->createMock(IUser::class);
		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setMethods(['logout'])
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->getMock();
		/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);

		$request->expects($this->once())
			->method('getHeader')
			->with('Authorization')
			->will($this->returnValue('token xxxxx'));
		$this->tokenProvider->expects($this->exactly(2))  // one for validation and other for login
			->method('getToken')
			->with('xxxxx')
			->will($this->returnValue($token));
		$this->tokenProvider->expects($this->exactly(2))  // one for validation and other for login
			->method('getPassword')
			->will($this->returnValue('myPassword'));
		$manager->expects($this->exactly(2))  // one for validation and other for login
			->method('get')
			->with('fritz0')
			->will($this->returnValue($user));
		$manager->expects($this->once())
			->method('checkPassword')
			->with('fritz', 'myPassword')
			->willReturn($user);
		$user->expects($this->once())
			->method('isEnabled')
			->will($this->returnValue(false));

		$userSession->tryTokenLogin($request);
	}

	public function testValidateSessionDisabledUser() {
		$userManager = $this->createMock(IUserManager::class);
		$session = $this->createMock(ISession::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$tokenProvider = $this->createMock(IProvider::class);

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$userManager, $session, $timeFactory, $tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods(['logout'])
			->getMock();

		/** @var IUser | \PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);
		$token = new DefaultToken();
		$token->setLoginName('susan');
		$token->setLastCheck(20);

		$session->expects($this->once())
			->method('get')
			->with('app_password')
			->will($this->returnValue('APP-PASSWORD'));
		$tokenProvider->expects($this->once())
			->method('getToken')
			->with('APP-PASSWORD')
			->will($this->returnValue($token));
		$timeFactory->expects($this->once())
			->method('getTime')
			->will($this->returnValue(1000)); // more than 5min since last check
		$tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, 'APP-PASSWORD')
			->will($this->returnValue('123456'));
		$userManager->expects($this->once())
			->method('checkPassword')
			->with('susan', '123456')
			->will($this->returnValue(true));
		$user->expects($this->once())
			->method('isEnabled')
			->will($this->returnValue(false));
		$tokenProvider->expects($this->once())
			->method('invalidateToken')
			->with('APP-PASSWORD');
		$userSession->expects($this->once())
			->method('logout');
		$user->method('getBackendClassName')
						->will($this->returnValue('LDAP'));
		$userManager->method('get')
						->will($this->returnValue($user));

		$userSession->setUser($user);
		$userSession->validateSession();
	}

	public function testValidateSessionNoPassword() {
		$userManager = $this->createMock(IUserManager::class);
		$session = $this->createMock(ISession::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$tokenProvider = $this->createMock(IProvider::class);
		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$userManager, $session, $timeFactory, $tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods(['logout'])
			->getMock();

		/** @var IUser | \PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);
		$token = new DefaultToken();
		$token->setLastCheck(20);

		$session->expects($this->once())
			->method('get')
			->with('app_password')
			->will($this->returnValue('APP-PASSWORD'));
		$tokenProvider->expects($this->once())
			->method('getToken')
			->with('APP-PASSWORD')
			->will($this->returnValue($token));
		$timeFactory->expects($this->once())
			->method('getTime')
			->will($this->returnValue(1000)); // more than 5min since last check
		$tokenProvider->expects($this->once())
			->method('getPassword')
			->with($token, 'APP-PASSWORD')
			->will($this->throwException(new PasswordlessTokenException()));
		$tokenProvider->expects($this->once())
			->method('updateToken')
			->with($token);

		$userSession->setUser($user);
		$userSession->validateSession();

		$this->assertEquals(1000, $token->getLastCheck());
	}

	public function testUpdateSessionTokenPassword() {
		/** @var IUserManager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(IUserManager::class);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$password = '123456';
		$sessionId ='session1234';
		$token = new DefaultToken();

		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		$tokenProvider->expects($this->once())
			->method('getToken')
			->with($sessionId)
			->will($this->returnValue($token));
		$tokenProvider->expects($this->once())
			->method('setPassword')
			->with($token, $sessionId, $password);

		$userSession->updateSessionTokenPassword($password);
	}

	public function testUpdateSessionTokenPasswordNoSessionAvailable() {
		/** @var IUserManager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(IUserManager::class);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$session->expects($this->once())
			->method('getId')
			->will($this->throwException(new SessionNotAvailableException()));

		$userSession->updateSessionTokenPassword('1234');
	}

	public function testUpdateSessionTokenPasswordInvalidTokenException() {
		/** @var IUserManager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(IUserManager::class);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$password = '123456';
		$sessionId ='session1234';
		$token = new DefaultToken();

		$session->expects($this->once())
			->method('getId')
			->will($this->returnValue($sessionId));
		$tokenProvider->expects($this->once())
			->method('getToken')
			->with($sessionId)
			->will($this->returnValue($token));
		$tokenProvider->expects($this->once())
			->method('setPassword')
			->with($token, $sessionId, $password)
			->will($this->throwException(new InvalidTokenException()));

		$userSession->updateSessionTokenPassword($password);
	}

	public function testClearRememberMeTokensForLoggedInUser() {
		$manager = $this->createMock(IUserManager::class);
		$session = $this->createMock(ISession::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$userSession = new Session(
			$manager,
			$session,
			$timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$uid = 'mockedUser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		$userSession->setUser($user);

		$timeFactory->method('getTime')->willReturn(60*60*24*366);

		$hash1 = \hash('snefru', 'token1');
		$hash2 = \hash('snefru', 'token2');
		$hash3 = \hash('snefru', 'token3');
		$tokenList = [
			$hash1 => \strval(60 * 60 * 24 * 365),
			$hash2 => "30",
			$hash3 => \strval(60 * 60 * 24 * 365),
		];

		$this->config->method('getSystemValue')
			->will($this->returnValueMap([
				['remember_login_cookie_lifetime', 60*60*24*15, 60*60*24*15]
			]));
		$this->config->method('getUserKeys')
			->with($uid, 'login_token')
			->willReturn(\array_keys($tokenList));
		$this->config->method('getUserValue')
			->will($this->returnValueMap([
				[$uid, 'login_token', $hash1, null, $tokenList[$hash1]],
				[$uid, 'login_token', $hash2, null, $tokenList[$hash2]],
				[$uid, 'login_token', $hash3, null, $tokenList[$hash3]],
			]));

		$this->config->expects($this->exactly(1))
			->method('deleteUserValue')
			->withConsecutive(
				[$uid, 'login_token', $hash2]
			);
		$userSession->clearRememberMeTokensForLoggedInUser(null);
	}

	public function testClearRememberMeTokensForLoggedInUserWithToken() {
		$manager = $this->createMock(IUserManager::class);
		$session = $this->createMock(ISession::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$userSession = new Session(
			$manager,
			$session,
			$timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$uid = 'mockedUser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		$userSession->setUser($user);

		$timeFactory->method('getTime')->willReturn(60*60*24*366);

		$hash1 = \hash('snefru', 'token1');
		$hash2 = \hash('snefru', 'token2');
		$hash3 = \hash('snefru', 'token3');
		$tokenList = [
			$hash1 => \strval(60 * 60 * 24 * 365),
			$hash2 => "30",
			$hash3 => \strval(60 * 60 * 24 * 365),
		];

		$this->config->method('getSystemValue')
			->will($this->returnValueMap([
				['remember_login_cookie_lifetime', 60*60*24*15, 60*60*24*15]
			]));
		$this->config->method('getUserKeys')
			->with($uid, 'login_token')
			->willReturn(\array_keys($tokenList));
		$this->config->method('getUserValue')
			->will($this->returnValueMap([
				[$uid, 'login_token', $hash1, null, $tokenList[$hash1]],
				[$uid, 'login_token', $hash2, null, $tokenList[$hash2]],
				[$uid, 'login_token', $hash3, null, $tokenList[$hash3]],
			]));

		$this->config->expects($this->exactly(2))
			->method('deleteUserValue')
			->withConsecutive(
				[$uid, 'login_token', $hash1],
				[$uid, 'login_token', $hash2]
			);
		$userSession->clearRememberMeTokensForLoggedInUser('token1');
	}

	public function testCancelLogout() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session->expects($this->once())
			->method('set')
			->with('user_id', 'foo');

		/** @var Manager $manager */
		$manager = $this->createMock(Manager::class);

		/** @var IUser | \PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())
			->method('getUID')
			->will($this->returnValue('foo'));

		$userSession = new Session(
			$manager,
			$session,
			$this->timeFactory,
			$this->tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);
		$userSession->setUser($user);

		$called['cancel'] = false;

		$this->eventDispatcher->addListener('\OC\User\Session::pre_logout', function ($event) use (&$called) {
			$called['cancel'] = true;
			$event['cancel'] = $called['cancel'];
		});

		$this->assertTrue($userSession->logout());

		$calledBeforeLogout = [];
		\OC::$server->getEventDispatcher()->addListener('user.beforelogout', function (GenericEvent $event) use (&$calledBeforeLogout) {
			$calledBeforeLogout[] = 'user.beforelogout';
			$calledBeforeLogout[] = $event;
		});

		$this->assertTrue($userSession->logout());
		$this->assertInstanceOf(GenericEvent::class, $calledBeforeLogout[1]);
		$this->assertArrayHasKey('uid', $calledBeforeLogout[1]);
		$this->assertEquals('user.beforelogout', $calledBeforeLogout[0]);
	}

	public function testLogout() {
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(Memory::class);
		$session->expects($this->once())
			->method('set')
			->with('user_id', 'foo');

		/** @var Manager $manager */
		$manager = $this->createMock(Manager::class);

		/** @var IUser | \PHPUnit\Framework\MockObject\MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())
			->method('getUID')
			->will($this->returnValue('foo'));

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $session, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods(['getAuthModules', 'unsetMagicInCookie'])
			->getMock();
		$userSession->setUser($user);

		$this->assertTrue($userSession->logout());

		$calledBeforeLogout = [];
		\OC::$server->getEventDispatcher()->addListener('user.beforelogout', function (GenericEvent $event) use (&$calledBeforeLogout) {
			$calledBeforeLogout[] = 'user.beforelogout';
			$calledBeforeLogout[] = $event;
		});
		$calledAfterLogout = [];
		\OC::$server->getEventDispatcher()->addListener('user.afterlogout', function (GenericEvent $event) use (&$calledAfterLogout) {
			$calledAfterLogout[] = 'user.afterlogout';
			$calledAfterLogout[] = $event;
		});

		$this->assertEquals(true, $userSession->logout());
		$this->assertInstanceOf(GenericEvent::class, $calledBeforeLogout[1]);
		$this->assertArrayHasKey('uid', $calledBeforeLogout[1]);
		$this->assertEquals('user.beforelogout', $calledBeforeLogout[0]);
		$this->assertInstanceOf(GenericEvent::class, $calledAfterLogout[1]);
		$this->assertArrayHasKey('uid', $calledAfterLogout[1]);
		$this->assertEquals('user.afterlogout', $calledAfterLogout[0]);
	}

	public function testApacheLogin() {
		/** @var IUserManager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(IUserManager::class);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		// Fail if not userid returned
		$apacheBackend = $this->createMock(IApacheBackend::class);
		$apacheBackend->expects($this->once())->method('getCurrentUserId')->willReturn(null);

		$loginVal = $userSession->loginWithApache($apacheBackend);
		$this->assertFalse($loginVal);

		// Fail if not a user interface supplied
		$apacheBackend = $this->createMock(IApacheBackend::class);
		$apacheBackend->expects($this->once())->method('getCurrentUserId')->willReturn('userid');
		$loginVal = $userSession->loginWithApache($apacheBackend);
		$this->assertFalse($loginVal);
	}

	public function testFailedLoginWithApache() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$apacheBackend = $this->createMock(IApacheBackend::class);
		$userInterface = $this->createMock(UserInterface::class);
		$apacheBackend->expects($this->once())->method('getCurrentUserId')->willReturn(['foo', $userInterface]);
		$apacheBackend->expects($this->once())->method('isSessionActive')->willReturn(true);

		$failedEvent = new GenericEvent(null, ['user' => 'foo']);
		$beforeLoginEvent = new GenericEvent(null, ['login' => 'foo', 'uid' => 'foo', 'password' => '', 'loginType' => 'apache']);
		$eventDispatcher->expects($this->once())
			->method('dispatch')
			->with($this->equalTo($failedEvent), $this->equalTo('user.loginfailed'));

		$userSession->loginWithApache($apacheBackend);
	}

	public function testLoginWithApache() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$apacheBackend = $this->createMock(IApacheBackend::class);
		$userInterface = $this->createMock(UserInterface::class);
		$apacheBackend->expects($this->once())->method('getCurrentUserId')->willReturn(['foo', $userInterface]);
		$apacheBackend->expects($this->once())->method('isSessionActive')->willReturn(true);

		$iUser = $this->createMock(IUser::class);
		$iUser->expects($this->exactly(2))
			->method('isEnabled')
			->willReturn(true);
		$iUser->expects($this->exactly(5))
			->method('getUID')
			->willReturn('foo');

		$userManager->expects($this->exactly(2))
			->method('get')
			->willReturn($iUser);

		$beforeLoginEvent = new GenericEvent(null, ['login' => 'foo', 'uid' => 'foo', 'password' => '', 'loginType' => 'apache', '_uid' => 'deprecated: please use \'login\', the real uid is not yet known',]);
		$afterLoginEvent = new GenericEvent(null, ['user' => $iUser, 'uid' => 'foo', 'password' => '', 'loginType' => 'apache']);
		$eventDispatcher->expects($this->exactly(2))
			->method('dispatch')
			->withConsecutive(
				[$this->equalTo($beforeLoginEvent), $this->equalTo('user.beforelogin')],
				[$this->equalTo($afterLoginEvent), $this->equalTo('user.afterlogin')]
			);

		$this->assertTrue($userSession->loginWithApache($apacheBackend));
	}

	public function testFailedLoginWithPassword() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		$userManager->expects($this->once())->method('checkPassword')->willReturn(false);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$failedLogin = new GenericEvent(null, ['user' => 'foo']);
		$eventDispatcher->expects($this->once())
			->method('dispatch')
			->with($this->equalTo($failedLogin), $this->equalTo('user.loginfailed'));

		self::invokePrivate($userSession, 'loginWithPassword', ['foo', 'foo']);
	}

	public function testLoginWithPassword() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);

		$iUser = $this->createMock(IUser::class);
		$iUser->expects($this->any())
			->method('isEnabled')
			->willReturn(true);
		$iUser->expects($this->any())
			->method('getUID')
			->willReturn('foo');

		$userManager->expects($this->once())->method('checkPassword')->willReturn($iUser);

		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$beforeEvent = new GenericEvent(
			null,
			['loginType' => 'password', 'login' => 'foo', 'uid' => 'foo',
				'_uid' => 'deprecated: please use \'login\', the real uid is not yet known',
				'password' => 'foopass']
		);
		$afterEvent = new GenericEvent(null, ['loginType' => 'password', 'user' => $iUser, 'uid' => 'foo', 'password' => 'foopass']);
		$eventDispatcher->expects($this->exactly(2))
			->method('dispatch')
			->withConsecutive(
				[$this->equalTo($beforeEvent), $this->equalTo('user.beforelogin')],
				[$this->equalTo($afterEvent), $this->equalTo('user.afterlogin')]
			);

		$result = self::invokePrivate($userSession, 'loginWithPassword', ['foo', 'foopass']);

		$this->assertTrue($result);
	}

	public function testLoginFailedWithToken() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$iToken = $this->createMock(IToken::class);
		$iToken->expects($this->once())
			->method('getUID')->willReturn('foo');
		$tokenProvider->expects($this->once())
			->method('getToken')->willReturn($iToken);
		$tokenProvider->expects($this->once())
			->method('getPassword')
			->with($iToken, 'token')
			->willReturn('foobar');

		$userManager->expects($this->once())
			->method('get')
			->willReturn(null);

		$failedEvent = new GenericEvent(null, ['user' => 'foo']);
		$eventDispatcher->expects($this->once())
			->method('dispatch')
			->with($this->equalTo($failedEvent), $this->equalTo('user.loginfailed'));

		self::invokePrivate($userSession, 'loginWithToken', ['token']);
	}

	public function testLoginWithToken() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$iToken = $this->createMock(IToken::class);
		$iToken->expects($this->once())
			->method('getUID')->willReturn('foo');
		$tokenProvider->expects($this->once())
			->method('getToken')->willReturn($iToken);
		$tokenProvider->expects($this->once())
			->method('getPassword')
			->with($iToken, 'token')
			->willReturn('foobar');

		$iUser = $this->createMock(IUser::class);
		$iUser->expects($this->any())
			->method('isEnabled')
			->willReturn(true);
		$iUser->expects($this->any())
			->method('getUID')
			->willReturn('foo');

		$userManager->expects($this->once())
			->method('get')
			->willReturn($iUser);

		$beforeEvent = new GenericEvent(
			null,
			[
				'login' => 'foo', 'uid' => 'foo', 'password' => 'foobar', 'loginType' => 'token', '_uid' => 'deprecated: please use \'login\', the real uid is not yet known',
			]
		);
		$afterEvent = new GenericEvent(
			null,
			[
				'user' => $iUser, 'uid' => 'foo', 'password' => 'foobar', 'loginType' => 'token'
			]
		);

		$eventDispatcher->expects($this->exactly(2))
			->method('dispatch')
			->withConsecutive(
				[$this->equalTo($beforeEvent), $this->equalTo('user.beforelogin')],
				[$this->equalTo($afterEvent), $this->equalTo('user.afterlogin')]
			);

		$result = self::invokePrivate($userSession, 'loginWithToken', ['token']);
		$this->assertTrue($result);
	}

	public function providesModules() {
		$nullModule = $this->createMock(IAuthModule::class);
		$nullModule->expects($this->any())->method('auth')->willReturn(null);
		$throwingModule = $this->createMock(IAuthModule::class);
		$throwingModule->expects($this->any())->method('auth')->willThrowException(new \Exception('Invalid token'));
		$user1 = $this->createMock(IUser::class);
		$user1->expects($this->any())->method('getUID')->willReturn('user1');
		$user1Module = $this->createMock(IAuthModule::class);
		$user1Module->expects($this->any())->method('auth')->willReturn($user1);
		$user2 = $this->createMock(IUser::class);
		$user2->expects($this->any())->method('getUID')->willReturn('user2');
		$user2Module = $this->createMock(IAuthModule::class);
		$user2Module->expects($this->any())->method('auth')->willReturn($user2);
		return [
			'no modules' => [true, []],
			'module returning null' => [true, [$nullModule]],
			'module returning a user' => [true, [$user1Module]],
			'module throwing an exception' => [false, [$throwingModule]],
			'modules returning different user' => [false, [$user1Module, $user2Module]],
			'logged in user does not match' => [false, [$user2Module], $user1],
		];
	}

	/**
	 * @dataProvider providesModules
	 * @param $expectedReturn
	 * @param array $modules
	 * @param null $loggedInUser
	 */
	public function testVerifyAuthHeaders($expectedReturn, array $modules, $loggedInUser = null) {
		/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);
		/** @var IUserManager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(IUserManager::class);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$userManager, $session, $timeFactory, $tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods(['getAuthModules', 'logout', 'isLoggedIn', 'getUser'])
			->getMock();
		$session->expects($this->any())->method('getAuthModules')->willReturn($modules);

		$session->expects($this->any())->method('isLoggedIn')->willReturn($loggedInUser !== null);
		$session->expects($this->any())->method('getUser')->willReturn($loggedInUser);

		$session->expects($expectedReturn ? $this->never() : $this->once())->method('logout');
		$this->assertEquals($expectedReturn, $session->verifyAuthHeaders($request));
	}

	public function providesModulesForLogin() {
		$nullModule = $this->createMock(IAuthModule::class);
		$nullModule->expects($this->any())->method('auth')->willReturn(null);
		$throwingModule = $this->createMock(IAuthModule::class);
		$throwingModule->expects($this->any())->method('auth')->willThrowException(new \Exception('Invalid token'));
		$user1 = $this->createMock(IUser::class);
		$user1->expects($this->any())->method('isEnabled')->willReturn('true');
		$user1->expects($this->any())->method('getUID')->willReturn('user1');
		$user1Module = $this->createMock(IAuthModule::class);
		$user1Module->expects($this->any())->method('auth')->willReturn($user1);
		return [
			'no modules' => [false, []],
			'module returning null' => [false, [$nullModule]],
			'module returning a user' => [true, [$user1Module]],
			'module throwing an exception' => [new \Exception('Invalid token'), [$throwingModule]],
		];
	}

	/**
	 * @dataProvider providesModulesForLogin
	 * @param mixed $expectedReturn
	 * @param array $modules
	 * @throws \Exception
	 */
	public function testTryAuthModuleLogin($expectedReturn, array $modules) {
		/** @var IRequest | \PHPUnit\Framework\MockObject\MockObject $request */
		$request = $this->createMock(IRequest::class);
		/** @var IUserManager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(IUserManager::class);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$userManager, $session, $timeFactory, $tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->setMethods(['getAuthModules', 'createSessionToken', 'loginUser', 'getUser'])
			->getMock();
		$session->expects($this->any())->method('getAuthModules')->willReturn($modules);

		if ($expectedReturn instanceof \Exception) {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('Invalid token');
		} else {
			$session->expects($expectedReturn ? $this->once() : $this->never())->method('createSessionToken');
			$session->expects($expectedReturn ? $this->once() : $this->never())->method('loginUser')->willReturn($expectedReturn);
		}

		$this->assertEquals($expectedReturn, $session->tryAuthModuleLogin($request));
	}

	public function testFailedLoginUser() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$failedEvent = new GenericEvent(null, ['user' => null]);
		$eventDispatcher->expects($this->once())
			->method('dispatch')
			->withConsecutive(
				[$this->equalTo($failedEvent), $this->equalTo('user.loginfailed')]
			);

		self::invokePrivate($userSession, 'loginUser', [null, 'foo']);
	}

	public function testLoginUser() {
		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$this->eventDispatcher
		);

		$iUser = $this->createMock(IUser::class);
		$iUser->expects($this->any())
			->method('isEnabled')
			->willReturn(true);
		$iUser->expects($this->atLeastOnce())
			->method('updateLastLoginTimestamp');

		// loginPolicyManager shouldn't have any policy active here, so it won't do anything

		$result = self::invokePrivate($userSession, 'loginUser', [$iUser, 'foo', 'authModuleClassname']);
		$this->assertTrue($result);
	}

	/**
	 */
	public function testFailedLoginUserDisabled() {
		$this->expectException(\OC\User\LoginException::class);
		$this->expectExceptionMessage('User disabled');

		/** @var Manager | \PHPUnit\Framework\MockObject\MockObject $userManager */
		$userManager = $this->createMock(Manager::class);
		$userManager->expects($this->any())->method('emit')->willReturn(null);
		/** @var ISession | \PHPUnit\Framework\MockObject\MockObject $session */
		$session = $this->createMock(ISession::class);
		/** @var ITimeFactory | \PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		/** @var IProvider | \PHPUnit\Framework\MockObject\MockObject $tokenProvider */
		$tokenProvider = $this->createMock(IProvider::class);
		$eventDispatcher = $this->createMock(EventDispatcher::class);
		$userSession = new Session(
			$userManager,
			$session,
			$timeFactory,
			$tokenProvider,
			$this->config,
			$this->logger,
			$this->serviceLoader,
			$this->userSyncService,
			$eventDispatcher
		);

		$iUser = $this->createMock(IUser::class);
		$iUser->expects($this->once())
			->method('isEnabled')
			->willReturn(false);
		$iUser->method('getUID')
			->willReturn('foo');
		$iUser->expects($this->never())
			->method('updateLastLoginTimestamp');

		$beforeEvent = new GenericEvent(
			null,
			['loginType' => 'authModuleClassname', 'login' => 'foo', 'uid' => 'foo',  // loginType == null because no authModule specified
				'_uid' => 'deprecated: please use \'login\', the real uid is not yet known',
				'password' => 'bar']
		);
		$eventDispatcher->expects($this->once())
			->method('dispatch')
			->with($this->equalTo($beforeEvent), $this->equalTo('user.beforelogin'));

		self::invokePrivate($userSession, 'loginUser', [$iUser, 'bar', 'authModuleClassname']);
	}

	/**
	 * Sitzung, die ein Client selbst angemeldet hat (WebDAV), mit Kennung
	 * 'sessionid'; logout() und die Anmeldemodule sind ersetzt.
	 *
	 * @param array $values zusätzliche Sitzungswerte
	 * @param IAuthModule[] $modules
	 * @param bool $enabled
	 * @param string $backend Benutzerverwaltung des Nutzers
	 * @return array [Session (Mock), Sitzungsspeicher, Nutzerverwaltung (Mock)]
	 */
	private function createDavSession(array $values, array $modules = [], $enabled = true, $backend = 'Database') {
		$store = new class extends Memory {
			public function getId() {
				return 'sessionid';
			}
		};
		$values += [
			'user_id' => 'user1',
			'loginname' => 'User1',
			'AUTHENTICATED_TO_DAV_BACKEND' => 'user1',
		];
		foreach ($values as $key => $value) {
			$store->set($key, $value);
		}
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('isEnabled')->willReturn($enabled);
		$user->method('getBackendClassName')->willReturn($backend);
		$manager = $this->createMock(Manager::class);
		$manager->method('get')->willReturnCallback(function ($uid) use ($user) {
			return $uid === 'user1' ? $user : null;
		});
		$this->config->method('getAppValue')->willReturnMap([['core', 'last_check_timeout', 5, '5']]);

		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$manager, $store, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->onlyMethods(['logout', 'getAuthModules'])
			->getMock();
		$userSession->method('getAuthModules')->willReturn($modules);
		return [$userSession, $store, $manager];
	}

	/**
	 * @param string $uid
	 * @param int $lastCheck
	 * @return DefaultToken
	 */
	private function createDavToken($uid, $lastCheck = 10000) {
		$token = new DefaultToken();
		$token->setUid($uid);
		$token->setLoginName(\ucfirst($uid));
		$token->setLastCheck($lastCheck);
		$token->setLastActivity(10000);
		return $token;
	}

	/**
	 * Ohne Angabe ein PROPFIND an WebDAV.
	 *
	 * @param array $server
	 * @param string $method
	 * @return Request
	 */
	private function createDavRequest(array $server = [], $method = 'PROPFIND') {
		return new Request(
			[
				'server' => $server + [
					'HTTP_USER_AGENT' => 'mirall',
					'REQUEST_URI' => '/remote.php/dav/files/user1/',
					'SCRIPT_NAME' => '/remote.php',
				],
				'method' => $method,
			],
			$this->createMock(ISecureRandom::class),
			$this->createMock(IConfig::class),
			$this->createMock(CsrfTokenManager::class)
		);
	}

	/**
	 * Tokens nach Kennung: Fehlt eine, gilt das Token als nicht vorhanden.
	 *
	 * @param IToken[] $tokens
	 */
	private function withTokens(array $tokens) {
		$this->tokenProvider->method('getToken')->willReturnCallback(function ($id) use ($tokens) {
			if (!isset($tokens[$id])) {
				throw new InvalidTokenException();
			}
			return $tokens[$id];
		});
	}

	public function testValidateDavSessionKeepsAppPasswordSession() {
		[$userSession, $store] = $this->createDavSession(['app_password' => 'APPPW', 'session_token_created' => true]);
		$sessionToken = $this->createDavToken('user1');
		$this->withTokens(['APPPW' => $this->createDavToken('user1'), 'sessionid' => $sessionToken]);
		$this->tokenProvider->expects($this->once())->method('updateTokenActivity')->with($sessionToken);
		$userSession->expects($this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest());
		$this->assertSame('APPPW', $store->get('app_password'));
	}

	public function providesInvalidAppPasswords() {
		return [
			'widerrufen' => [[]],
			'Token eines anderen Nutzers' => [['APPPW' => 'user2']],
		];
	}

	/**
	 * Widerrufenes App-Passwort: Die Sitzung endet, auch wenn der Client nur das
	 * Cookie schickt und das Sitzungstoken noch besteht.
	 *
	 * @dataProvider providesInvalidAppPasswords
	 * @param array $owners Token => Nutzer
	 */
	public function testValidateDavSessionEndsWhenAppPasswordIsInvalid(array $owners) {
		[$userSession] = $this->createDavSession(['app_password' => 'APPPW', 'session_token_created' => true]);
		$tokens = ['sessionid' => $this->createDavToken('user1')];
		foreach ($owners as $id => $uid) {
			$tokens[$id] = $this->createDavToken($uid);
		}
		$this->withTokens($tokens);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('ended'), $this->callback(function ($context) {
				return $context['reason'] === 'app password no longer valid';
			}));
		$userSession->expects($this->once())->method('logout');
		// Nach dem Ende nichts mehr an der Sitzung: weder Token noch Aktivität
		$this->tokenProvider->expects($this->never())->method('generateToken');
		$this->tokenProvider->expects($this->never())->method('updateTokenActivity');

		$userSession->validateDavSession($this->createDavRequest());
	}

	public function providesStoredPasswordChecks() {
		return [
			'Prüfung noch nicht fällig' => [10000 - 299, false, true, false],
			'Prüfung genau fällig, Kennwort gilt' => [10000 - 300, true, true, false],
			'Prüfung fällig, Kennwort geändert' => [10000 - 301, true, false, true],
		];
	}

	/**
	 * Erwartet die Prüfung des hinterlegten Kennworts: erst übernommen (last_check
	 * atomar fortgeschrieben), dann abgeglichen, damit parallele Anfragen mit
	 * demselben Token sie nicht wiederholen.
	 *
	 * @param Manager | \PHPUnit\Framework\MockObject\MockObject $manager
	 * @param DefaultToken $token
	 * @param string $tokenId
	 * @param bool $checks
	 * @param bool $passwordValid
	 * @return \ArrayObject Reihenfolge der Schritte
	 */
	private function expectStoredPasswordCheck($manager, DefaultToken $token, $tokenId, $checks, $passwordValid) {
		$steps = new \ArrayObject();
		$this->tokenProvider->method('getPassword')->willReturnCallback(function ($dbToken, $id) use ($token, $tokenId) {
			$this->assertSame($token, $dbToken);
			$this->assertSame($tokenId, $id);
			return 'altes-kennwort';
		});
		$this->tokenProvider->expects($checks ? $this->once() : $this->never())->method('claimCredentialCheck')
			->with($token, 10000)
			->willReturnCallback(function (DefaultToken $dbToken, $now) use ($steps) {
				$steps[] = 'vermerkt';
				$dbToken->setLastCheck($now);
				return true;
			});
		$manager->expects($checks ? $this->once() : $this->never())->method('checkPassword')
			->with('User1', 'altes-kennwort')
			->willReturnCallback(function () use ($steps, $passwordValid) {
				$steps[] = 'geprüft';
				return $passwordValid ? $this->createMock(IUser::class) : false;
			});
		return $steps;
	}

	/**
	 * App-Passwort-Sitzung: Das Kennwort, mit dem das App-Passwort angelegt wurde,
	 * prüft der Server wie bei einer neuen Anmeldung damit alle last_check_timeout
	 * Minuten. Gilt es nicht mehr, wird das App-Passwort ungültig und die Sitzung
	 * endet – auch wenn der Client nur das Cookie schickt.
	 *
	 * @dataProvider providesStoredPasswordChecks
	 * @param int $lastCheck
	 * @param bool $checks
	 * @param bool $passwordValid
	 * @param bool $ends
	 */
	public function testValidateDavSessionChecksPasswordOfAppPassword($lastCheck, $checks, $passwordValid, $ends) {
		[$userSession, , $manager] = $this->createDavSession(['app_password' => 'APPPW', 'session_token_created' => true]);
		$appPasswordToken = $this->createDavToken('user1', $lastCheck);
		$this->withTokens(['APPPW' => $appPasswordToken, 'sessionid' => $this->createDavToken('user1')]);
		$steps = $this->expectStoredPasswordCheck($manager, $appPasswordToken, 'APPPW', $checks, $passwordValid);
		$this->tokenProvider->expects($ends ? $this->once() : $this->never())->method('invalidateToken')->with('APPPW');
		$userSession->expects($ends ? $this->once() : $this->never())->method('logout');
		if ($ends) {
			$this->logger->expects($this->once())->method('info')
				->with($this->stringContains('ended'), $this->callback(function ($context) {
					return $context['reason'] === 'password of the app password changed';
				}));
		}

		$userSession->validateDavSession($this->createDavRequest());

		$this->assertSame($checks ? ['vermerkt', 'geprüft'] : [], $steps->getArrayCopy());
		$this->assertSame($checks ? 10000 : $lastCheck, $appPasswordToken->getLastCheck());
	}

	/**
	 * App-Passwörter von Konten aus LDAP prüft auch die Anmeldung mit dem
	 * App-Passwort nicht gegen das Kennwort (sonst löschte schon ein kurzer Ausfall
	 * des Verzeichnisses die App-Passwörter): hier ebenso.
	 */
	public function testValidateDavSessionKeepsAppPasswordOfLdapAccount() {
		[$userSession, , $manager] = $this->createDavSession(['app_password' => 'APPPW', 'session_token_created' => true], [], true, 'LDAP');
		$this->withTokens(['APPPW' => $this->createDavToken('user1', 10000 - 301), 'sessionid' => $this->createDavToken('user1')]);
		$this->tokenProvider->expects($this->never())->method('claimCredentialCheck');
		$manager->expects($this->never())->method('checkPassword');
		$this->tokenProvider->expects($this->never())->method('getPassword');
		$this->tokenProvider->expects($this->never())->method('invalidateToken');
		$userSession->expects($this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest());
	}

	public function providesUncheckableStoredPasswords() {
		return [
			'Token ohne Kennwort' => [new PasswordlessTokenException()],
			'Benutzerverwaltung nicht erreichbar' => [new \RuntimeException('directory unavailable')],
		];
	}

	/**
	 * Lässt sich das hinterlegte Kennwort nicht prüfen (kein Kennwort im Token,
	 * Ausnahme der Benutzerverwaltung), bleibt die Sitzung bis zur nächsten Prüfung.
	 *
	 * @dataProvider providesUncheckableStoredPasswords
	 * @param \Exception $exception
	 */
	public function testValidateDavSessionKeepsSessionWhenStoredPasswordCannotBeChecked(\Exception $exception) {
		[$userSession, , $manager] = $this->createDavSession(['app_password' => 'APPPW', 'session_token_created' => true]);
		$appPasswordToken = $this->createDavToken('user1', 10000 - 301);
		$this->withTokens(['APPPW' => $appPasswordToken, 'sessionid' => $this->createDavToken('user1')]);
		$this->tokenProvider->expects($this->once())->method('claimCredentialCheck')
			->willReturnCallback(function (DefaultToken $dbToken, $now) {
				$dbToken->setLastCheck($now);
				return true;
			});
		if ($exception instanceof PasswordlessTokenException) {
			$this->tokenProvider->method('getPassword')->willThrowException($exception);
			$manager->expects($this->never())->method('checkPassword');
		} else {
			$this->tokenProvider->method('getPassword')->willReturn('altes-kennwort');
			$manager->expects($this->once())->method('checkPassword')->willThrowException($exception);
			$this->logger->expects($this->once())->method('logException')->with($exception);
		}
		$this->tokenProvider->expects($this->never())->method('invalidateToken');
		$userSession->expects($this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest());
		$this->assertSame(10000, $appPasswordToken->getLastCheck());
	}

	/**
	 * Hat eine parallele Anfrage die fällige Prüfung schon übernommen, prüft diese
	 * nicht noch einmal und lässt die Sitzung gelten.
	 */
	public function testValidateDavSessionLeavesStoredPasswordCheckToParallelRequest() {
		[$userSession, , $manager] = $this->createDavSession(['app_password' => 'APPPW', 'session_token_created' => true]);
		$this->withTokens(['APPPW' => $this->createDavToken('user1', 10000 - 301), 'sessionid' => $this->createDavToken('user1')]);
		$this->tokenProvider->expects($this->once())->method('claimCredentialCheck')->willReturn(false);
		$this->tokenProvider->expects($this->never())->method('getPassword');
		$manager->expects($this->never())->method('checkPassword');
		$userSession->expects($this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest());
	}

	/**
	 * Kennwort-Sitzung, die nur das Cookie schickt: Das bei der Anmeldung
	 * hinterlegte Kennwort prüft der Server alle last_check_timeout Minuten, wie
	 * für Browser; nach einer Kennwortänderung endet die Sitzung.
	 *
	 * @dataProvider providesStoredPasswordChecks
	 * @param int $lastCheck
	 * @param bool $checks
	 * @param bool $passwordValid
	 * @param bool $ends
	 */
	public function testValidateDavSessionChecksPasswordOfCookieOnlyPasswordSession($lastCheck, $checks, $passwordValid, $ends) {
		[$userSession, , $manager] = $this->createDavSession(['session_token_created' => true]);
		$sessionToken = $this->createDavToken('user1', $lastCheck);
		$this->withTokens(['sessionid' => $sessionToken]);
		$steps = $this->expectStoredPasswordCheck($manager, $sessionToken, 'sessionid', $checks, $passwordValid);
		$userSession->expects($ends ? $this->once() : $this->never())->method('logout');
		if ($ends) {
			$this->logger->expects($this->once())->method('info')
				->with($this->stringContains('ended'), $this->callback(function ($context) {
					return $context['reason'] === 'password changed';
				}));
		}

		$userSession->validateDavSession($this->createDavRequest());

		$this->assertSame($checks ? ['vermerkt', 'geprüft'] : [], $steps->getArrayCopy());
	}

	/**
	 * Schickt die Kennwort-Sitzung das Kennwort mit (HTTP-Basic), prüft es
	 * BasicAuthModule wie bisher alle last_check_timeout Minuten; das hinterlegte
	 * fragt validateDavSession() dann nicht zusätzlich ab.
	 */
	public function testValidateDavSessionLeavesPasswordOfBasicRequestToBasicAuthModule() {
		[$userSession, , $manager] = $this->createDavSession(['session_token_created' => true]);
		$this->withTokens(['sessionid' => $this->createDavToken('user1', 10000 - 301)]);
		$this->tokenProvider->expects($this->never())->method('claimCredentialCheck');
		$manager->expects($this->never())->method('checkPassword');
		$this->tokenProvider->expects($this->never())->method('getPassword');
		$userSession->expects($this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest(['PHP_AUTH_USER' => 'user1', 'PHP_AUTH_PW' => 'secret']));
	}

	/**
	 * Sitzung unter „Sitzungen“ entfernt: Das Token wird nicht neu angelegt.
	 */
	public function testValidateDavSessionEndsWhenSessionTokenWasRemoved() {
		[$userSession] = $this->createDavSession(['session_token_created' => true]);
		$this->withTokens([]);
		$this->tokenProvider->expects($this->never())->method('generateToken');
		$userSession->expects($this->once())->method('logout');

		$userSession->validateDavSession($this->createDavRequest(['PHP_AUTH_USER' => 'user1', 'PHP_AUTH_PW' => 'secret']));
	}

	/**
	 * Zweite Anfrage einer Sitzung: Das Token entsteht wie bisher erst jetzt,
	 * mit dem Kennwort der Anfrage, und die Sitzung merkt sich das.
	 */
	public function testValidateDavSessionCreatesSessionTokenOnce() {
		[$userSession, $store] = $this->createDavSession([]);
		$this->withTokens([]);
		$this->tokenProvider->expects($this->once())->method('generateToken')
			->with('sessionid', 'user1', 'User1', 'secret', 'mirall');
		$userSession->expects($this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest(['PHP_AUTH_USER' => 'user1', 'PHP_AUTH_PW' => 'secret']));
		$this->assertTrue($store->get('session_token_created'));
	}

	public function providesExistingSessionTokens() {
		return [
			'Token der Sitzung besteht schon' => [false],
			'parallel angelegt (Unique-Verletzung)' => [true],
		];
	}

	/**
	 * Auch wenn das Token schon besteht, merkt sich die Sitzung, dass sie eines hat.
	 *
	 * @dataProvider providesExistingSessionTokens
	 * @param bool $concurrent
	 */
	public function testCreateSessionTokenMarksSessionWithExistingToken($concurrent) {
		$store = new class extends Memory {
			public function getId() {
				return 'sessionid';
			}
		};
		$manager = $this->createMock(Manager::class);
		$manager->method('get')->willReturn($this->createMock(IUser::class));
		$userSession = new Session($manager, $store, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher);
		if ($concurrent) {
			$this->withTokens([]);
			$this->tokenProvider->expects($this->once())->method('generateToken')
				->willThrowException($this->createMock(UniqueConstraintViolationException::class));
		} else {
			$this->withTokens(['sessionid' => $this->createDavToken('user1')]);
			$this->tokenProvider->expects($this->never())->method('generateToken');
		}

		$this->assertTrue($userSession->createSessionToken($this->createDavRequest(), 'user1', 'User1'));
		$this->assertTrue($store->get('session_token_created'));
	}

	public function providesSessionsFromBeforeTheCheck() {
		return [
			'App-Passwort, nur Cookie' => [['app_password' => 'APPPW'], [], false],
			'Kennwort über Basic' => [[], ['HTTP_AUTHORIZATION' => 'Basic dXNlcjE6c2VjcmV0'], false],
			'Kennwort über Basic ohne Authorization-Kopf (mod_php)' => [[], ['PHP_AUTH_USER' => 'user1', 'PHP_AUTH_PW' => 'secret'], false],
			'nur Cookie, weder App-Passwort noch Kennwort' => [[], [], true],
		];
	}

	/**
	 * Sitzung von vor dieser Prüfung (Token ohne Vermerk): Sie behält ihr Token
	 * und gilt ab jetzt wie jede neue. Woher sie stammt, zeigt erst die Anfrage;
	 * nur mit Cookie und ohne App-Passwort ist das unbekannt – einmal neu anmelden.
	 *
	 * @dataProvider providesSessionsFromBeforeTheCheck
	 * @param array $values
	 * @param array $server
	 * @param bool $ends
	 */
	public function testValidateDavSessionOfSessionFromBeforeTheCheck(array $values, array $server, $ends) {
		[$userSession, $store] = $this->createDavSession($values);
		$this->withTokens(['APPPW' => $this->createDavToken('user1'), 'sessionid' => $this->createDavToken('user1')]);
		$this->tokenProvider->expects($this->never())->method('generateToken');
		$userSession->expects($ends ? $this->once() : $this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest($server));
		$this->assertTrue($store->get('session_token_created'));
	}

	public function testValidateDavSessionEndsWhenSessionTokenBelongsToOtherUser() {
		[$userSession] = $this->createDavSession(['session_token_created' => true]);
		$this->withTokens(['sessionid' => $this->createDavToken('user2')]);
		$userSession->expects($this->once())->method('logout');

		$userSession->validateDavSession($this->createDavRequest());
	}

	public function testValidateDavSessionEndsForDisabledUser() {
		[$userSession] = $this->createDavSession(['session_token_created' => true], [], false);
		$this->withTokens(['sessionid' => $this->createDavToken('user1')]);
		$userSession->expects($this->once())->method('logout');

		$userSession->validateDavSession($this->createDavRequest());
	}

	/**
	 * Kennwort über HTTP-Basic: kein Anmeldemodul beteiligt, die Sitzung bleibt.
	 */
	public function testValidateDavSessionKeepsPasswordSession() {
		$module = $this->createMock(IAuthModule::class);
		$module->expects($this->never())->method('auth');
		[$userSession] = $this->createDavSession(['session_token_created' => true], [$module]);
		$this->withTokens(['sessionid' => $this->createDavToken('user1')]);
		$userSession->expects($this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest(['HTTP_AUTHORIZATION' => 'Basic dXNlcjE6c2VjcmV0']));
	}

	public function providesModulesForUnmarkedSessions() {
		return [
			'zweites Modul bestätigt den Nutzer' => ['user1', false],
			'kein Modul kennt den Kopf' => [null, true],
			'Modul nennt einen anderen Nutzer' => ['user2', true],
			'Modul lehnt ab' => [new \OC\User\LoginException('Invalid token'), true],
		];
	}

	/**
	 * Bearer an einer Sitzung ohne Vermerk eines Anmeldemoduls (begonnen vor
	 * dieser Prüfung): Bestätigt ein Modul den Nutzer, wird es vermerkt, sonst
	 * endet die Sitzung.
	 *
	 * @dataProvider providesModulesForUnmarkedSessions
	 * @param string|\Exception|null $moduleResult
	 * @param bool $ends
	 */
	public function testValidateDavSessionOfUnmarkedSessionWithBearer($moduleResult, $ends) {
		$first = $this->createMock(IAuthModule::class);
		$first->method('auth')->willReturn(null);
		$second = $this->createMock(IAuthModule::class);
		$auth = $second->expects($this->once())->method('auth');
		if ($moduleResult instanceof \Exception) {
			$auth->willThrowException($moduleResult);
		} elseif ($moduleResult !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($moduleResult);
			$auth->willReturn($user);
		} else {
			$auth->willReturn(null);
		}
		[$userSession, $store] = $this->createDavSession(['session_token_created' => true], [$first, $second]);
		$this->withTokens(['sessionid' => $this->createDavToken('user1')]);
		$userSession->expects($ends ? $this->once() : $this->never())->method('logout');

		$userSession->validateDavSession($this->createDavRequest(['HTTP_AUTHORIZATION' => 'Bearer abc']));
		if (!$ends) {
			$this->assertSame(
				['class' => \get_class($second), 'credential' => \hash('sha256', 'Bearer abc')],
				$store->get('auth_module')
			);
		}
	}

	public function providesAuthModuleRequests() {
		$bearer = function ($token) {
			return ['HTTP_AUTHORIZATION' => "Bearer $token"];
		};
		$tokenEndpoint = ['REQUEST_URI' => '/index.php/apps/oauth2/api/v1/token', 'SCRIPT_NAME' => '/index.php'];
		$clientBasic = ['HTTP_AUTHORIZATION' => 'Basic Y2xpZW50OnNlY3JldA=='];
		$rejected = new \OC\User\LoginException('Invalid token');
		// [Kopf und Pfad, Methode, last_check, Modul gefragt, Antwort des Moduls, Ausgang]
		return [
			'derselbe Bearer, eben geprüft' => [$bearer('abc'), 'PROPFIND', 10000, false, null, 'gilt'],
			'derselbe Bearer nach Rewrite, eben geprüft' => [['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc'], 'PROPFIND', 10000, false, null, 'gilt'],
			'nur Cookie' => [[], 'PROPFIND', 10000, true, null, 'endet'],
			'nur Cookie, Modul wirft' => [[], 'PROPFIND', 10000, true, $rejected, 'endet'],
			'Basic an WebDAV' => [['HTTP_AUTHORIZATION' => 'Basic dXNlcjE6c2VjcmV0'], 'PROPFIND', 10000, true, null, 'endet'],
			'erneuerter Bearer' => [$bearer('neu'), 'PROPFIND', 10000, true, 'user1', 'gilt'],
			'Bearer eines anderen Nutzers' => [$bearer('fremd'), 'PROPFIND', 10000, true, 'user2', 'endet'],
			'anderer Bearer, vom Modul abgelehnt (etwa der alte nach der Erneuerung)' => [$bearer('alt'), 'PROPFIND', 10000, true, $rejected, 'bleibt'],
			'anderer Bearer, kein Modul kennt ihn' => [$bearer('unbekannt'), 'PROPFIND', 10000, true, null, 'endet'],
			'derselbe Bearer, Prüfung fällig, gilt noch' => [$bearer('abc'), 'PROPFIND', 10000 - 301, true, 'user1', 'gilt'],
			'derselbe Bearer, Prüfung genau fällig' => [$bearer('abc'), 'PROPFIND', 10000 - 300, true, 'user1', 'gilt'],
			'derselbe Bearer, Prüfung fällig, abgelaufen oder widerrufen' => [$bearer('abc'), 'PROPFIND', 10000 - 301, true, $rejected, 'bleibt'],
			'derselbe Bearer, Prüfung fällig, Modul kennt ihn nicht mehr' => [$bearer('abc'), 'PROPFIND', 10000 - 301, true, null, 'endet'],
			'derselbe Bearer, Prüfung gerade noch nicht fällig' => [$bearer('abc'), 'PROPFIND', 10000 - 299, false, null, 'gilt'],
			'Token-Endpunkt, Client-Daten über Basic' => [$tokenEndpoint + $clientBasic, 'POST', 10000, false, null, 'bleibt'],
			'Token-Endpunkt, Client-Daten im Formular' => [$tokenEndpoint, 'POST', 10000, false, null, 'bleibt'],
			'Token-Endpunkt, Prüfung fällig' => [$tokenEndpoint + $clientBasic, 'POST', 10000 - 301, false, null, 'bleibt'],
			'OpenID-Connect-Discovery' => [['REQUEST_URI' => '/index.php/apps/openidconnect/config', 'SCRIPT_NAME' => '/index.php'], 'GET', 10000, false, null, 'bleibt'],
			'GET am Token-Pfad' => [$tokenEndpoint + $clientBasic, 'GET', 10000, true, null, 'endet'],
			'Token-Pfad unter anderem Präfix' => [['REQUEST_URI' => '/index.php/apps/files/apps/oauth2/api/v1/token', 'SCRIPT_NAME' => '/index.php'] + $clientBasic, 'POST', 10000, true, null, 'endet'],
		];
	}

	/**
	 * Sitzung eines Anmeldemoduls (OAuth2): Jede Anfrage muss einen Authorization-
	 * Kopf tragen, den das Modul für den Nutzer der Sitzung bestätigt; ein anderer
	 * Kopf wird sofort geprüft, derselbe nach last_check_timeout (5 Minuten).
	 * - gilt: bestätigt (Vermerk und Prüfzeit fortgeschrieben, wenn gefragt)
	 * - bleibt: weder bestätigt noch widerlegt – lehnt das Modul den Kopf ab
	 *   (abgelaufenes Token), weisen WebDAV, OCS und Weboberfläche die Anfrage
	 *   selbst ab; Token-Endpunkt und Discovery brauchen die Sitzung nicht. Die
	 *   Sitzung bleibt unverändert, auch ihre Aktivität.
	 * - endet: kein oder ein fremder Nutzer
	 *
	 * @dataProvider providesAuthModuleRequests
	 * @param array $server
	 * @param string $method
	 * @param int $lastCheck
	 * @param bool $asksModule
	 * @param string|\Exception|null $moduleResult Nutzer, Ausnahme oder null
	 * @param string $outcome gilt, bleibt oder endet
	 */
	public function testValidateDavSessionOfAuthModule(array $server, $method, $lastCheck, $asksModule, $moduleResult, $outcome) {
		$module = $this->createMock(IAuthModule::class);
		$auth = $module->expects($asksModule ? $this->once() : $this->never())->method('auth');
		if ($moduleResult instanceof \Exception) {
			$auth->willThrowException($moduleResult);
		} elseif ($moduleResult !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($moduleResult);
			$auth->willReturn($user);
		} else {
			$auth->willReturn(null);
		}
		$authModule = ['class' => \get_class($module), 'credential' => \hash('sha256', 'Bearer abc')];
		[$userSession, $store] = $this->createDavSession(['session_token_created' => true, 'auth_module' => $authModule], [$module]);
		$sessionToken = $this->createDavToken('user1', $lastCheck);
		$this->withTokens(['sessionid' => $sessionToken]);
		$userSession->expects($outcome === 'endet' ? $this->once() : $this->never())->method('logout');
		$this->tokenProvider->expects($outcome === 'gilt' ? $this->once() : $this->never())->method('updateTokenActivity');
		$this->tokenProvider->expects($outcome === 'gilt' && $asksModule ? $this->once() : $this->never())->method('updateToken');

		$userSession->validateDavSession($this->createDavRequest($server, $method));

		if ($outcome === 'gilt' && $asksModule) {
			$authorization = $server['HTTP_AUTHORIZATION'] ?? '';
			$this->assertSame(\hash('sha256', $authorization), $store->get('auth_module')['credential']);
			$this->assertSame(10000, $sessionToken->getLastCheck());
		} elseif ($outcome !== 'endet') {
			$this->assertSame($authModule, $store->get('auth_module'));
			$this->assertSame($lastCheck, $sessionToken->getLastCheck());
		}
	}

	/**
	 * Sitzung von vor dieser Prüfung am Token-Endpunkt: Die Anfrage lässt sie
	 * unberührt, auch ohne Vermerk – woher sie stammt, zeigt erst die nächste.
	 */
	public function testValidateDavSessionLeavesSessionFromBeforeTheCheckAtTokenEndpoint() {
		[$userSession, $store] = $this->createDavSession([]);
		$this->withTokens(['sessionid' => $this->createDavToken('user1')]);
		$userSession->expects($this->never())->method('logout');
		$this->tokenProvider->expects($this->never())->method('updateTokenActivity');

		$userSession->validateDavSession($this->createDavRequest(
			['REQUEST_URI' => '/index.php/apps/oauth2/api/v1/token', 'SCRIPT_NAME' => '/index.php'],
			'POST'
		));
		$this->assertFalse($store->exists('session_token_created'));
	}

	/**
	 * Ist die App des Anmeldemoduls nicht mehr da, lässt sich der Kopf nicht
	 * prüfen: Die Sitzung endet.
	 */
	public function testValidateDavSessionEndsWhenAuthModuleIsGone() {
		$authModule = ['class' => 'OCA\Gone\AuthModule', 'credential' => \hash('sha256', 'Bearer abc')];
		[$userSession] = $this->createDavSession(['session_token_created' => true, 'auth_module' => $authModule]);
		$this->withTokens(['sessionid' => $this->createDavToken('user1')]);
		$userSession->expects($this->once())->method('logout');

		$userSession->validateDavSession($this->createDavRequest(['HTTP_AUTHORIZATION' => 'Bearer neu']));
	}

	/**
	 * Anmeldung über ein Anmeldemodul: Die Sitzung merkt sich Modul und
	 * Fingerabdruck des Authorization-Kopfs, nicht den Kopf selbst.
	 */
	public function testTryAuthModuleLoginRemembersModuleAndCredential() {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('isEnabled')->willReturn(true);
		$module = $this->createMock(IAuthModule::class);
		$module->method('auth')->willReturn($user);
		$store = new Memory();
		/** @var Session | \PHPUnit\Framework\MockObject\MockObject $userSession */
		$userSession = $this->getMockBuilder(Session::class)
			->setConstructorArgs([$this->createMock(Manager::class), $store, $this->timeFactory, $this->tokenProvider, $this->config, $this->logger, $this->serviceLoader, $this->userSyncService, $this->eventDispatcher])
			->onlyMethods(['getAuthModules', 'createSessionToken', 'loginUser'])
			->getMock();
		$userSession->method('getAuthModules')->willReturn([$module]);
		$userSession->expects($this->once())->method('loginUser')->willReturn(true);
		$userSession->expects($this->once())->method('createSessionToken');

		$this->assertTrue($userSession->tryAuthModuleLogin($this->createDavRequest(['HTTP_AUTHORIZATION' => 'Bearer abc'])));
		$this->assertSame('user1', $store->get('AUTHENTICATED_TO_DAV_BACKEND'));
		$this->assertSame(
			['class' => \get_class($module), 'credential' => \hash('sha256', 'Bearer abc')],
			$store->get('auth_module')
		);
	}
}
