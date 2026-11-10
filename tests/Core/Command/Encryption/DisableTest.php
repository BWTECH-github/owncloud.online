<?php
/**
 * @author Joas Schilling <nickvergessen@owncloud.com>
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
 * Modified by BW-Tech GmbH on 2026-02-26.
 * Changes:
 *   - doctrine/dbal:3 (#41450)
 */

namespace Tests\Core\Command\Encryption;

use Doctrine\DBAL\Result;
use OC\Core\Command\Encryption\Disable;
use OC\DB\QueryBuilder\ExpressionBuilder\ExpressionBuilder;
use OC\DB\QueryBuilder\QueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Test\TestCase;

class DisableTest extends TestCase {
	/** @var MockObject */
	protected $db;
	/** @var MockObject */
	protected $config;
	/** @var MockObject */
	protected $consoleInput;
	/** @var MockObject */
	protected $consoleOutput;

	/** @var \Symfony\Component\Console\Command\Command */
	protected $command;
	/** @var array Werte, die als benannte Parameter in Abfragen gingen */
	private $namedParameters = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->consoleInput = $this->createMock(InputInterface::class);
		$this->consoleOutput = $this->createMock(OutputInterface::class);

		/** @var IConfig $config */
		$this->command = new Disable($this->db, $this->config);
	}

	public function dataDisable() {
		return [
			// [oldStatus, isUpdating, masterKeyEnabled, encryptedCount, reportedPaths, expectedString]
			['yes', true, '1', 0, [], 'Encryption disabled'],
			['yes', true, '', 0, [], 'Encryption disabled'],
			['no', false, false, 0, [], 'Encryption is already disabled'],
			['yes', true, '1', 1, ['files_versions/foo.txt'], 'Encryption disabled'],
			['yes', true, '', 1, ['files_trashbin/files/bar.txt.d123'], 'Encryption disabled'],
			['no', false, false, 1, ['files/baz.txt'], 'Encryption is already disabled'],
			// mehr verschlüsselte Zeilen als ausgegeben -> Zusammenfassung "... and N more"
			['no', false, false, 25, ['files/a.txt', 'files_versions/b.txt'], 'Encryption is already disabled'],
		];
	}

	/**
	 * @dataProvider dataDisable
	 *
	 * @param string $oldStatus
	 * @param bool $isUpdating
	 * @param bool $masterKeyEnabled
	 * @param int $encryptedCount Anzahl der Dateicache-Zeilen, die noch als verschlüsselt markiert sind
	 * @param string[] $reportedPaths begrenzte Liste der Pfade, die der Befehl ausgibt
	 * @param string $expectedString
	 */
	public function testDisable($oldStatus, $isUpdating, $masterKeyEnabled, $encryptedCount, $reportedPaths, $expectedString) {
		$stmt = $this->createMock(Result::class);
		// erste Abfrage zählt die verschlüsselten Zeilen, zweite holt die begrenzte Pfadliste
		$stmt->method('fetchOne')
			->willReturn($encryptedCount);
		$stmt->method('fetchFirstColumn')
			->willReturn($reportedPaths);
		// keine falsch markierten Schlüsseldateien: die Bereinigung findet nichts
		$stmt->method('fetch')
			->willReturn(false);
		$qbMock = $this->queryBuilderReturning($stmt);
		$qbMock->expects($this->never())->method('update');

		if ($encryptedCount === 0) {
			$this->config->expects($this->exactly(1))
				->method('getAppValue')
				->willReturnMap(
					[
						['core', 'encryption_enabled', 'no', $oldStatus],
					]
				);

			$this->consoleOutput->expects($this->exactly(2))
				->method('writeln')
				->will($this->onConsecutiveCalls([
					$this->stringContains('<info>Cleaned up config</info>'),
					$this->stringContains($expectedString),
				]));

			if ($isUpdating) {
				$this->config
					->method('setAppValue')
					->willReturnMap(
						[
							['core', 'encryption_enabled', 'no', true],
						]
					);
				$this->config
					->method('deleteAppValue')
					->willReturnMap([
						['encryption', 'useMasterKey', true],
						['encryption', 'userSpecificKey', true],
					]);
			}
			$expectedExitCode = 0;
		} else {
			$writtenLines = [];
			$this->consoleOutput->expects($this->atLeastOnce())
				->method('writeln')
				->willReturnCallback(function ($line) use (&$writtenLines) {
					$writtenLines[] = $line;
				});
			$expectedExitCode = 1;
		}

		$exitCode = self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
		$this->assertEquals($expectedExitCode, $exitCode);

		if ($encryptedCount > 0) {
			$output = \implode("\n", $writtenLines);
			$this->assertStringContainsString('The system still has encrypted files.', $output);
			// Ausweg, wenn decrypt-all nach einem vollständigen Lauf "Nothing to do" meldet
			$this->assertStringContainsString('run "occ encryption:enable" first', $output);
			foreach ($reportedPaths as $path) {
				$this->assertStringContainsString($path, $output);
			}
			$remaining = $encryptedCount - \count($reportedPaths);
			if ($remaining > 0) {
				$this->assertStringContainsString("... and $remaining more still encrypted", $output);
			} else {
				$this->assertStringNotContainsString('more still encrypted', $output);
			}
		}
	}

	/**
	 * @param MockObject $stmt Ergebnis, das jede Abfrage liefert
	 * @return MockObject
	 */
	private function queryBuilderReturning($stmt) {
		$qbMock = $this->createMock(QueryBuilder::class);
		$qbMock->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));
		$qbMock->method('createFunction')->willReturnArgument(0);
		$this->namedParameters = [];
		$qbMock->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->namedParameters[] = $value;
			return $value;
		});
		foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'setMaxResults', 'update', 'set'] as $fluent) {
			$qbMock->method($fluent)->willReturnSelf();
		}
		$qbMock->method('execute')->willReturn($stmt);
		$this->db->method('getQueryBuilder')->willReturn($qbMock);
		return $qbMock;
	}

	public function providesKeyStoragePaths(): array {
		$root = 'local::/var/www/data/';
		return [
			// Schlüssel der Papierkorb-Kopie im Heimatspeicher
			['home::bernd', 'files_encryption/keys/files_trashbin/files/a.bin.d123/OC_DEFAULT_MODULE/fileKey', true],
			['object::user:bernd', 'files_encryption/keys/files/a.txt/OC_DEFAULT_MODULE/bernd.shareKey', true],
			// derselbe Schlüssel, über den Wurzelspeicher eingetragen
			[$root, 'bernd/files_encryption/keys/files_trashbin/files/a.bin.d123/OC_DEFAULT_MODULE/fileKey', true],
			[$root, 'files_encryption/OC_DEFAULT_MODULE/master_1234.privateKey', true],
			['object::store:amazon::bucket', 'bernd/files_encryption/keys/files/a.txt/OC_DEFAULT_MODULE/fileKey', true],
			// Nutzerinhalt, der nur so heißt, bleibt eine echte verschlüsselte Datei
			['home::bernd', 'files/files_encryption/geheim.txt', false],
			['home::bernd', 'files/projekt/files_encryption/keys/geheim.txt', false],
			['home::bernd', 'files_trashbin/files/files_encryption/geheim.txt.d1', false],
			[$root, 'bernd/files/files_encryption/geheim.txt', false],
			// externer Speicher, auch ein lokaler außerhalb des Datenverzeichnisses
			['local::/mnt/extern/', 'files_encryption/geheim.txt', false],
			['smb::user@host//share//', 'files_encryption/geheim.txt', false],
			['home::bernd', 'filesXencryption/geheim.txt', false],
		];
	}

	/**
	 * @dataProvider providesKeyStoragePaths
	 */
	public function testIsKeyStoragePath(string $storageId, string $path, bool $expected): void {
		$this->config->method('getSystemValue')->with('datadirectory')->willReturn('/var/www/data');
		$this->assertSame($expected, self::invokePrivate($this->command, 'isKeyStoragePath', [$storageId, $path]));
	}

	/**
	 * Falsch markierte Schlüsseldateien blockieren das Abschalten nicht mehr: Ihr
	 * Kennzeichen wird zurückgesetzt, eine gleich benannte Nutzerdatei nicht.
	 */
	public function testResetsTheFlagOnKeyFilesOnly(): void {
		$this->config->method('getSystemValue')->with('datadirectory')->willReturn('/var/www/data');
		$this->config->method('getAppValue')->willReturn('yes');

		$stmt = $this->createMock(Result::class);
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(
			['fileid' => 41, 'path' => 'files_encryption/keys/files_trashbin/files/a.bin.d1/OC_DEFAULT_MODULE/fileKey', 'id' => 'home::bernd'],
			['fileid' => 42, 'path' => 'files/files_encryption/geheim.txt', 'id' => 'home::bernd'],
			['fileid' => 43, 'path' => 'bernd/files_encryption/keys/files/b.txt/OC_DEFAULT_MODULE/fileKey', 'id' => 'local::/var/www/data/'],
			false
		);
		// danach zählt die Abfrage keine verschlüsselten Zeilen mehr
		$stmt->method('fetchOne')->willReturn(0);

		$qbMock = $this->queryBuilderReturning($stmt);
		$qbMock->expects($this->once())->method('update')->with('filecache');

		$writtenLines = [];
		$this->consoleOutput->method('writeln')->willReturnCallback(function ($line) use (&$writtenLines) {
			$writtenLines[] = $line;
		});

		$exitCode = self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);

		$this->assertSame(0, $exitCode);
		$this->assertContains([41, 43], $this->namedParameters, 'Nur die zwei Schlüsseldateien werden zurückgesetzt');
		$output = \implode("\n", $writtenLines);
		$this->assertStringContainsString('Reset the encryption flag of 2 key files', $output);
		$this->assertStringContainsString('Encryption disabled', $output);
	}
}
