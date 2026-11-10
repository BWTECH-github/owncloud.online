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

namespace Test\Preview;

use OC\Preview\TIFF;
use OCP\Files\File;
use OCP\ILogger;
use OCP\Util;
use Test\TestCase;

/**
 * Eine abgelehnte Bitmap-Vorschau ist erwartetes Verhalten, kein Fehler.
 *
 * Jede Datei, deren Endung nicht zum Inhalt passt, scheitert am gepinnten Coder. Das
 * kann jeder Nutzer per Upload auslösen, deshalb darf es nur auf DEBUG im Log landen –
 * aber es muss weiterhin protokolliert werden, sonst fehlt beim Eingrenzen jede Spur.
 *
 * @requires extension imagick
 */
class BitmapRejectionLogTest extends TestCase {
	/** @var array<int, array{0: int, 1: string}> */
	private $logged = [];

	public function providesContentTheTiffPinRejects(): array {
		return [
			// echtes PNG, die Datei behauptet aber, ein TIFF zu sein
			'PNG als .tiff' => [(string)\file_get_contents(\OC::$SERVERROOT . '/tests/data/testimage.png')],
			// Textinhalt, den schon isDangerousToDecode() abweist
			'SVG als .tiff' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'],
		];
	}

	/**
	 * @dataProvider providesContentTheTiffPinRejects
	 */
	public function testRejectedPreviewIsLoggedAtDebugLevel(string $content): void {
		if (\count(\Imagick::queryFormats('TIFF')) === 0) {
			$this->markTestSkipped('This ImageMagick build registers no TIFF coder');
		}

		$stream = \fopen('php://memory', 'rb+');
		\fwrite($stream, $content);
		\rewind($stream);
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn($stream);
		$file->method('getMimeType')->willReturn('image/tiff');

		$result = $this->withRecordingLogger(function () use ($file) {
			return (new TIFF())->getThumbnail($file, 32, 32, false);
		});

		$this->assertFalse($result);
		$rejections = \array_values(\array_filter($this->logged, static function (array $entry): bool {
			return \strpos($entry[1], 'ImageMagick says: ') === 0;
		}));
		$this->assertCount(1, $rejections, 'Die Ablehnung muss weiterhin protokolliert werden');
		$this->assertSame(Util::DEBUG, $rejections[0][0], 'Per Upload auslösbar – nur DEBUG, nicht ERROR');
	}

	/**
	 * Server::getLogger() hält den Logger in einer Eigenschaft fest, ein überschriebener
	 * Dienst „Logger“ käme dort nie an. Deshalb wird die Eigenschaft selbst getauscht und
	 * im finally sicher zurückgesetzt.
	 *
	 * @return mixed
	 */
	private function withRecordingLogger(callable $run) {
		$logger = $this->createMock(ILogger::class);
		$logger->method('log')->willReturnCallback(function ($level, $message, array $context = []) {
			$this->logged[] = [$level, (string)$message];
		});

		$server = \OC::$server;
		$property = new \ReflectionProperty($server, 'logger');
		$property->setAccessible(true);
		// getLogger() füllt die Eigenschaft beim ersten Aufruf; vorher abrufen, damit
		// beim Zurücksetzen nicht null eingetragen wird
		$server->getLogger();
		$original = $property->getValue($server);
		$property->setValue($server, $logger);
		try {
			return $run();
		} finally {
			$property->setValue($server, $original);
		}
	}
}
