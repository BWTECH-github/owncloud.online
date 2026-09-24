<?php
/**
 * @author BW-Tech GmbH
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Test\Log;

use OC\Log\Rotate;
use Test\TestCase;

/**
 * Bis zu dieser Fassung stand 'log_rotate_size' ohne Zutun auf false: wer die
 * Rotation nie eingerichtet hatte, bekam eine owncloud.log, die nur durch die
 * Platte begrenzt war. Der Vorgabewert schliesst das, darf aber ein
 * ausdrueckliches Abschalten nicht ueberfahren - genau darum geht es hier.
 *
 * @group DB
 */
class RotateTest extends TestCase {
	/** @var string */
	private $logFile;

	/** @var mixed */
	private $restoreRotateSize;

	/** @var bool */
	private $hadRotateSize;

	protected function setUp(): void {
		parent::setUp();
		$config = \OC::$server->getConfig();
		$this->restoreRotateSize = $config->getSystemValue('log_rotate_size', null);
		$this->hadRotateSize = $this->restoreRotateSize !== null;
		$this->logFile = \OC::$server->getTempManager()->getTemporaryFile('rotatetest');
	}

	protected function tearDown(): void {
		$config = \OC::$server->getConfig();
		if ($this->hadRotateSize) {
			$config->setSystemValue('log_rotate_size', $this->restoreRotateSize);
		} else {
			$config->deleteSystemValue('log_rotate_size');
		}
		foreach ([$this->logFile, $this->logFile . '.1'] as $file) {
			if ($file !== null && \file_exists($file)) {
				@\unlink($file);
			}
		}
		parent::tearDown();
	}

	public function testDefaultIsHundredMebibytes() {
		// Zwei Dateien zu 100 MiB sind auf jedem Server zu verkraften; alles
		// darueber war der Fall, den wir abstellen wollen.
		$this->assertSame(100 * 1024 * 1024, Rotate::DEFAULT_MAX_SIZE);
	}

	public function maxSizeProvider() {
		return [
			// Der Eintrag fehlt: die Aufrufer reichen den Vorgabewert durch.
			'missing entry' => [Rotate::DEFAULT_MAX_SIZE, Rotate::DEFAULT_MAX_SIZE],
			// Ausdrueckliches Abschalten bleibt Abschalten - sonst wuerden wir
			// Instanzen, die ihre Logs mit logrotate verwalten, dazwischenfunken.
			'explicit false' => [false, 0],
			'explicit zero' => [0, 0],
			'explicit zero string' => ['0', 0],
			'empty string' => ['', 0],
			'null' => [null, 0],
			'negative' => [-1, 0],
			'garbage' => ['later', 0],
			// true hiess wortwoertlich "rotiere bei jedem Lauf"; gemeint war
			// immer "schalte die Rotation ein".
			'true means on' => [true, Rotate::DEFAULT_MAX_SIZE],
			'integer' => [5 * 1024 * 1024, 5 * 1024 * 1024],
			'numeric string' => ['5242880', 5 * 1024 * 1024],
			'human readable' => ['100 MB', 104857600],
			'human readable without space' => ['512MB', 536870912],
		];
	}

	/**
	 * @dataProvider maxSizeProvider
	 * @param mixed $configured
	 */
	public function testMaxSize($configured, int $expected) {
		$this->assertSame($expected, Rotate::maxSize($configured));
	}

	public function testRotatesOnceTheLimitIsReached() {
		\file_put_contents($this->logFile, \str_repeat('x', 64));
		\OC::$server->getConfig()->setSystemValue('log_rotate_size', 32);

		(new Rotate())->run($this->logFile);

		$this->assertFileExists($this->logFile . '.1');
		$this->assertFalse(\file_exists($this->logFile), 'the active log file was moved aside');
		$this->assertSame(64, \filesize($this->logFile . '.1'));
	}

	public function testKeepsTheLogFileBelowTheLimit() {
		\file_put_contents($this->logFile, \str_repeat('x', 16));
		\OC::$server->getConfig()->setSystemValue('log_rotate_size', 32);

		(new Rotate())->run($this->logFile);

		$this->assertFileExists($this->logFile);
		$this->assertFalse(\file_exists($this->logFile . '.1'));
	}

	public function testDisabledRotationLeavesEvenAnOversizedLogAlone() {
		\file_put_contents($this->logFile, \str_repeat('x', 64));
		\OC::$server->getConfig()->setSystemValue('log_rotate_size', false);

		(new Rotate())->run($this->logFile);

		$this->assertFileExists($this->logFile);
		$this->assertFalse(\file_exists($this->logFile . '.1'), 'an explicit false still switches rotation off');
	}

	public function testRotatesWithoutAnyConfiguredSize() {
		// Der eigentliche Punkt der Aenderung: ohne Eintrag in der config.php
		// wird rotiert. Mit einer 100-MiB-Datei zu pruefen waere teuer, also
		// wird der Vorgabewert selbst als Grenze eingetragen und eine Datei
		// gebaut, die ihn ueberschreitet - ohne sie zu schreiben.
		\OC::$server->getConfig()->deleteSystemValue('log_rotate_size');
		$this->assertSame(
			Rotate::DEFAULT_MAX_SIZE,
			Rotate::maxSize(\OC::$server->getConfig()->getSystemValue('log_rotate_size', Rotate::DEFAULT_MAX_SIZE)),
			'a missing entry rotates at the default'
		);

		$handle = \fopen($this->logFile, 'w');
		\fseek($handle, Rotate::DEFAULT_MAX_SIZE);
		\fwrite($handle, 'x');
		\fclose($handle);

		(new Rotate())->run($this->logFile);

		$this->assertFileExists($this->logFile . '.1');
		$this->assertFalse(\file_exists($this->logFile), 'an oversized log rotates without any configuration');
	}
}
