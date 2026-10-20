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

namespace Test;

use OC\SystemConfig;
use OCP\IRequest;
use OCP\Util;
use Sabre\DAV\Exception\ServiceUnavailable;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Fehlerpfad von remote.php bei WebDAV-Anfragen: Was landet im Protokoll, was
 * beim Client?
 *
 * remote.php ist ein Einstiegsskript, das beim Einbinden sofort läuft und
 * Klassen sowie Funktionen global deklariert. Jeder Test bindet es deshalb in
 * einem eigenen Prozess ein. Den Fehler löst eine nachgebaute Anfrage aus,
 * deren getPathInfo() wirft – so früh, dass weder Datenbank noch Dateisystem
 * beteiligt sind. Mitgeschnitten wird über das Ereignis log.beforewrite, das
 * der echte Logger für jeden Eintrag auslöst, unabhängig von der Logstufe.
 *
 * Der Zweig für andere Anfragen endet in OC_Template::printExceptionErrorPage()
 * mit die() und lässt sich so nicht prüfen; er protokolliert seit jeher die
 * ursprüngliche Ausnahme.
 */
class RemoteExceptionHandlingTest extends TestCase {
	private const ORIGINAL_MESSAGE = 'Sitzung konnte nicht gestartet werden';

	/** @var array[] */
	private $logged = [];

	private function prepare(?\Throwable $pathInfoException, string $pathInfo = '/dav/files/alice/a.txt'): void {
		$_SERVER['REQUEST_METHOD'] = 'PROPFIND';
		$_SERVER['REQUEST_URI'] = '/remote.php' . $pathInfo;

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function ($name) {
			return \strtolower($name) === 'content-type' ? 'text/xml; charset=utf-8' : null;
		});
		if ($pathInfoException !== null) {
			$request->method('getPathInfo')->willThrowException($pathInfoException);
		} else {
			$request->method('getPathInfo')->willReturn($pathInfo);
		}
		$this->overwriteService('Request', $request);

		// debug=false wie im Betrieb: Der Client darf nichts über die Ursache erfahren.
		$systemConfig = $this->createMock(SystemConfig::class);
		$systemConfig->method('getValue')->willReturnCallback(static function ($key, $default = '') {
			return $key === 'debug' ? false : $default;
		});
		$this->overwriteService('SystemConfig', $systemConfig);

		\OC::$server->getEventDispatcher()->addListener('log.beforewrite', function (GenericEvent $event) {
			$this->logged[] = $event->getArguments();
		});
	}

	private function runRemotePhp(): string {
		\ob_start();
		try {
			require \OC::$SERVERROOT . '/remote.php';
		} finally {
			$output = \ob_get_clean();
		}
		return $output;
	}

	/**
	 * Nur Einträge mit Ausnahme – PHP-Hinweise, die der Fehlerhandler nebenbei
	 * protokolliert, gehören nicht zur Prüfung.
	 *
	 * @return array[]
	 */
	private function loggedExceptions(): array {
		return \array_values(\array_filter($this->logged, static function (array $entry) {
			return $entry['exception'] !== null;
		}));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLogsTheOriginalExceptionWithItsCauseButNotTheWrapper(): void {
		$cause = new \LogicException('Verbindung zur Datenbank verloren');
		$original = new \RuntimeException(self::ORIGINAL_MESSAGE, 0, $cause);
		$this->prepare($original);

		$body = $this->runRemotePhp();

		$logged = $this->loggedExceptions();
		$this->assertCount(2, $logged, 'erwartet: Ausnahme und Ursache, sonst nichts');
		[$main, $causedBy] = $logged;
		$this->assertSame($original, $main['exception']);
		$this->assertSame('remote', $main['app']);
		$this->assertSame(Util::ERROR, $main['loglevel']);
		$this->assertStringStartsWith('Exception: {"Exception":"RuntimeException","Message":"' . self::ORIGINAL_MESSAGE . '"', $main['formattedMessage']);
		$this->assertSame($cause, $causedBy['exception']);
		$this->assertStringStartsWith('Caused by: {"Exception":"LogicException"', $causedBy['formattedMessage']);

		foreach ($logged as $entry) {
			$this->assertNotInstanceOf(ServiceUnavailable::class, $entry['exception'], 'die Hülle für den Client gehört nicht ins Protokoll');
		}

		// Der Client bekommt weiter nur die allgemeine Antwort.
		$this->assertStringContainsString('<s:exception>Sabre\DAV\Exception\ServiceUnavailable</s:exception>', $body);
		$this->assertStringContainsString('<s:message>Internal Server Error</s:message>', $body);
		$this->assertStringNotContainsString('Sitzung', $body);
		$this->assertStringNotContainsString('Datenbank', $body);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRemoteExceptionIsNotLogged(): void {
		// Leerer Pfad: remote.php wirft selbst eine RemoteException (404).
		$this->prepare(null, '');

		$body = $this->runRemotePhp();

		$this->assertSame([], $this->loggedExceptions());
		$this->assertStringContainsString('<s:exception>Sabre\DAV\Exception\NotFound</s:exception>', $body);
	}
}
