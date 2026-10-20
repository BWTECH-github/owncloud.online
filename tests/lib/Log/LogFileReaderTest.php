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

namespace Test\Log;

use OC\Log\LogFileReader;
use Test\TestCase;

/**
 * Rückwärts lesen, blättern, filtern und Ausnahmen samt „Caused by“-Kette –
 * gegen Zeilen im Format von OC\Log\Owncloud und Log::logException().
 */
class LogFileReaderTest extends TestCase {
	/** @var string */
	private $file;

	protected function setUp(): void {
		parent::setUp();
		$this->file = \OC::$server->getTempManager()->getTemporaryFile('logreader');
	}

	protected function tearDown(): void {
		if ($this->file !== null && \file_exists($this->file)) {
			@\unlink($this->file);
		}
		parent::tearDown();
	}

	/**
	 * Eine Zeile wie OC\Log\Owncloud::writeExtra() sie schreibt.
	 */
	private function line(int $level, string $message, string $app = 'core', string $reqId = 'req', array $extra = []): string {
		return \json_encode(\array_merge([
			'reqId' => $reqId,
			'level' => $level,
			'time' => '2026-10-19T20:35:05+02:00',
			'remoteAddr' => '192.0.2.10',
			'user' => 'alice',
			'app' => $app,
			'method' => 'PROPFIND',
			'url' => '/remote.php/dav/files/alice/a.txt',
			'message' => $message,
		], $extra)) . "\n";
	}

	/**
	 * Meldung wie Log::logException() sie baut: Präfix, Doppelpunkt, JSON.
	 */
	private function exceptionMessage(string $prefix, string $class, string $message, string $trace = '#0 {main}'): string {
		return $prefix . ': ' . \json_encode([
			'Exception' => $class,
			'Message' => $message,
			'Code' => 0,
			'Trace' => $trace,
			'File' => '/var/www/owncloud/lib/private/Files/View.php',
			'Line' => 42,
		]);
	}

	private function write(string ...$lines): LogFileReader {
		\file_put_contents($this->file, \implode('', $lines));
		return new LogFileReader($this->file);
	}

	private function messages(array $result): array {
		return \array_column($result['entries'], 'message');
	}

	public function testReadsNewestFirstAndPagesWithoutGapsOrDuplicates(): void {
		$lines = [];
		for ($i = 1; $i <= 7; $i++) {
			$lines[] = $this->line(2, "Eintrag $i");
		}
		$reader = $this->write(...$lines);

		$first = $reader->read(0, 3);
		$this->assertSame(['Eintrag 7', 'Eintrag 6', 'Eintrag 5'], $this->messages($first));
		$this->assertNotNull($first['next_offset']);
		$this->assertFalse($first['restarted']);

		// Neue Zeilen am Ende verschieben die Fortsetzung nicht.
		\file_put_contents($this->file, $this->line(2, 'Eintrag 8'), FILE_APPEND);

		$second = $reader->read($first['next_offset'], 3);
		$this->assertSame(['Eintrag 4', 'Eintrag 3', 'Eintrag 2'], $this->messages($second));

		$third = $reader->read($second['next_offset'], 3);
		$this->assertSame(['Eintrag 1'], $this->messages($third));
		$this->assertNull($third['next_offset'], 'Dateianfang erreicht');
	}

	public function testEntryFieldsAndOffsets(): void {
		$reader = $this->write($this->line(3, 'Login failed', 'core', 'abc123'));
		$entry = $reader->read(0, 10)['entries'][0];

		$this->assertSame(0, $entry['offset']);
		$this->assertSame('abc123', $entry['reqId']);
		$this->assertSame(3, $entry['level']);
		$this->assertSame('2026-10-19T20:35:05+02:00', $entry['time']);
		$this->assertSame('192.0.2.10', $entry['remoteAddr']);
		$this->assertSame('alice', $entry['user']);
		$this->assertSame('core', $entry['app']);
		$this->assertSame('PROPFIND', $entry['method']);
		$this->assertSame('/remote.php/dav/files/alice/a.txt', $entry['url']);
		$this->assertSame('Login failed', $entry['message']);
		$this->assertNull($entry['exception']);
		$this->assertSame([], $entry['previous']);
		$this->assertFalse($entry['truncated']);
		$this->assertArrayNotHasKey('_haystack', $entry);
	}

	public function testExceptionWithCausedByChainBecomesOneEntry(): void {
		$reader = $this->write(
			$this->line(1, 'davor', 'core', 'other'),
			$this->line(4, $this->exceptionMessage('Exception: HTTP/1.1 503 Service Unavailable', 'RuntimeException', 'Sitzung weg', "#0 /var/www/remote.php(198): handleException()\n#1 {main}"), 'webdav', 'r1'),
			$this->line(4, $this->exceptionMessage('Caused by', 'Doctrine\DBAL\Exception\DriverException', 'Too many connections'), 'webdav', 'r1'),
			$this->line(4, $this->exceptionMessage('Caused by', 'PDOException', 'SQLSTATE[08004] [1040] Too many connections'), 'webdav', 'r1'),
			$this->line(1, 'danach', 'core', 'other')
		);

		$result = $reader->read(0, 10);
		$this->assertSame(['danach', 'Exception: HTTP/1.1 503 Service Unavailable', 'davor'], $this->messages($result));

		$entry = $result['entries'][1];
		$this->assertSame('RuntimeException', $entry['exception']['class']);
		$this->assertSame('Sitzung weg', $entry['exception']['message']);
		$this->assertSame('/var/www/owncloud/lib/private/Files/View.php', $entry['exception']['file']);
		$this->assertSame(42, $entry['exception']['line']);
		$this->assertStringContainsString('handleException()', $entry['exception']['trace']);
		$this->assertSame(
			['Doctrine\DBAL\Exception\DriverException', 'PDOException'],
			\array_column($entry['previous'], 'class'),
			'die direkte Ursache zuerst'
		);
		$this->assertSame('SQLSTATE[08004] [1040] Too many connections', $entry['previous'][1]['message']);
	}

	public function testCausedByOfAnotherRequestStaysAnEntryOfItsOwn(): void {
		$reader = $this->write(
			$this->line(4, $this->exceptionMessage('Exception', 'RuntimeException', 'eins'), 'webdav', 'r1'),
			$this->line(4, $this->exceptionMessage('Caused by', 'LogicException', 'fremd'), 'webdav', 'r2')
		);

		$result = $reader->read(0, 10);
		$this->assertCount(2, $result['entries']);
		$this->assertSame('Caused by', $result['entries'][0]['message']);
		$this->assertSame('LogicException', $result['entries'][0]['exception']['class']);
		$this->assertSame([], $result['entries'][1]['previous']);
	}

	public function testCausesAtAPageBoundaryAreAttachedOnTheNextPage(): void {
		$this->write(
			$this->line(4, $this->exceptionMessage('Exception', 'RuntimeException', 'Hauptfehler'), 'webdav', 'r1'),
			$this->line(4, $this->exceptionMessage('Caused by', 'LogicException', 'Ursache'), 'webdav', 'r1'),
			$this->line(2, 'jünger', 'core', 'r2')
		);
		// Budget so klein, dass der erste Abruf nach der neuesten Zeile und der
		// „Caused by“-Zeile aufhört, bevor er die Ausnahme erreicht.
		$size = \filesize($this->file);
		$reader = new LogFileReader($this->file, (int)($size / 2));

		$first = $reader->read(0, 10);
		$this->assertSame(['jünger'], $this->messages($first));
		$second = $reader->read($first['next_offset'], 10);
		$this->assertCount(1, $second['entries']);
		$this->assertSame('Hauptfehler', $second['entries'][0]['exception']['message']);
		$this->assertSame('Ursache', $second['entries'][0]['previous'][0]['message']);
		$this->assertNull($second['next_offset']);
	}

	public function testFiltersByMinimumLevelAppAndSearch(): void {
		$reader = $this->write(
			$this->line(0, 'Debug-Zeile', 'files'),
			$this->line(2, 'Warnung zu Ärger', 'Files'),
			$this->line(3, $this->exceptionMessage('Exception', 'OCP\Files\NotFoundException', 'nicht da', '#0 lib/private/Preview.php(639): getDimensions()'), 'files'),
			$this->line(4, 'Fatal in dav', 'dav', 'reqid-zum-suchen')
		);

		$this->assertSame(['Fatal in dav', 'Exception', 'Warnung zu Ärger'], $this->messages($reader->read(0, 10, 2)));
		$this->assertSame(['Exception', 'Warnung zu Ärger', 'Debug-Zeile'], $this->messages($reader->read(0, 10, null, 'FILES')), 'App ohne Groß-/Kleinschreibung');
		$this->assertSame(['Warnung zu Ärger'], $this->messages($reader->read(0, 10, null, '', 'ärger')), 'Suche ohne Groß-/Kleinschreibung, auch bei Umlauten');
		$this->assertSame(['Exception'], $this->messages($reader->read(0, 10, null, '', 'Preview.php')), 'Suche im Trace');
		$this->assertSame(['Exception'], $this->messages($reader->read(0, 10, null, '', 'NotFoundException')), 'Suche in der Ausnahmeklasse');
		$this->assertSame(['Fatal in dav'], $this->messages($reader->read(0, 10, null, '', 'reqid-zum')), 'Suche in der Anfrage-ID');
		$this->assertSame([], $reader->read(0, 10, 3, 'files', 'Ärger')['entries']);
	}

	public function testScanBudgetReturnsAContinuationThatReachesTheStart(): void {
		$lines = [];
		for ($i = 0; $i < 200; $i++) {
			$lines[] = $this->line(0, "Rauschen $i");
		}
		$lines[0] = $this->line(4, 'der eine Treffer');
		$this->write(...$lines);
		$reader = new LogFileReader($this->file, 4096);

		$offset = 0;
		$found = [];
		$calls = 0;
		do {
			$result = $reader->read($offset, 10, 4);
			$found = \array_merge($found, $this->messages($result));
			$this->assertLessThan(4096 + 1024, $result['scanned']);
			if ($result['next_offset'] !== null) {
				$this->assertTrue($offset === 0 || $result['next_offset'] < $offset, 'jeder Abruf kommt voran');
			}
			$offset = $result['next_offset'];
			$calls++;
		} while ($offset !== null && $calls < 100);

		$this->assertSame(['der eine Treffer'], $found);
		$this->assertGreaterThan(1, $calls);
	}

	public function testRotatedFileRestartsFromTheEnd(): void {
		$reader = $this->write($this->line(2, 'alt 1'), $this->line(2, 'alt 2'), $this->line(2, 'alt 3'));
		$first = $reader->read(0, 1);
		$this->assertNotNull($first['next_offset']);

		// Rotation: neue, kürzere Datei
		\file_put_contents($this->file, $this->line(2, 'neu'));
		$result = $reader->read($first['next_offset'], 10);
		$this->assertTrue($result['restarted']);
		$this->assertSame(['neu'], $this->messages($result));

		// Mitten in eine Zeile zeigende Position gilt ebenfalls als ungültig.
		$result = $reader->read(5, 10);
		$this->assertTrue($result['restarted']);
	}

	public function testIgnoresALineThatIsStillBeingWritten(): void {
		$reader = $this->write($this->line(2, 'fertig'), '{"reqId":"x","level":4,"time":"2026-');
		$this->assertSame(['fertig'], $this->messages($reader->read(0, 10)));
	}

	public function testForeignAndBrokenLinesAreShownAsText(): void {
		$reader = $this->write(
			"PHP Warning:  kein JSON\n",
			// json_encode() lehnt ungültiges UTF-8 ab, die Zeile deshalb von Hand
			'{"reqId":"r","level":2,"time":"t","remoteAddr":"","user":"--","app":"core","method":"GET","url":"\/","message":"kaputt ' . "\xC3\x28" . ' UTF-8"}' . "\n"
		);
		$result = $reader->read(0, 10);

		$this->assertCount(2, $result['entries']);
		$this->assertNotFalse(\json_encode($result), 'Antwort bleibt als JSON kodierbar');
		$this->assertSame('PHP Warning:  kein JSON', $result['entries'][1]['message']);
		$this->assertNull($result['entries'][1]['level']);
		$this->assertTrue($result['entries'][1]['truncated']);
		$this->assertNull($reader->read(0, 10, 0)['entries'][1] ?? null, 'ohne Stufe fällt die Zeile aus jedem Stufenfilter');
	}

	public function testHugeLineIsReadFromItsBeginning(): void {
		$huge = $this->line(4, $this->exceptionMessage('Exception', 'RuntimeException', 'riesig', \str_repeat('#0 x', 400000)), 'webdav', 'gross');
		$this->assertGreaterThan(LogFileReader::MAX_LINE_BYTES, \strlen($huge));
		$reader = $this->write($this->line(2, 'davor'), $huge, $this->line(2, 'danach'));

		$result = $reader->read(0, 10);
		$this->assertSame('danach', $result['entries'][0]['message']);
		$big = $result['entries'][1];
		$this->assertTrue($big['truncated']);
		$this->assertSame(4, $big['level']);
		$this->assertSame('webdav', $big['app']);
		$this->assertSame('gross', $big['reqId']);
		$this->assertLessThanOrEqual(LogFileReader::MAX_FIELD_BYTES, \strlen($big['message']));
		$this->assertSame('davor', $result['entries'][2]['message']);
		$this->assertNotFalse(\json_encode($result));
	}

	public function testCountIsCapped(): void {
		$lines = [];
		for ($i = 0; $i < LogFileReader::MAX_COUNT + 5; $i++) {
			$lines[] = $this->line(2, "Zeile $i");
		}
		$reader = $this->write(...$lines);
		$this->assertCount(LogFileReader::MAX_COUNT, $reader->read(0, 10000)['entries']);
		$this->assertCount(1, $reader->read(0, 0)['entries']);
	}

	public function testEmptyFile(): void {
		$reader = $this->write();
		$result = $reader->read(0, 10);
		$this->assertSame([], $result['entries']);
		$this->assertNull($result['next_offset']);
		$this->assertSame(0, $result['size']);
	}
}
