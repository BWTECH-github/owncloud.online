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

namespace Test\DB;

use OC\DB\PostgreSqlMigrator;
use Test\TestCase;

/**
 * Der Vergleich der Vorgabewerte braucht kein PostgreSQL, nur die Wahrheitstabelle.
 */
class PostgreSqlMigratorTest extends TestCase {
	public function providesDefaults(): array {
		return [
			// eine Spalte ohne Vorgabewert bekommt neu den Vorgabewert 0: SET DEFAULT muss laufen
			'kein Vorgabewert → 0' => [null, 0, true],
			'kein Vorgabewert → 0.0' => [null, 0.0, true],
			'kein Vorgabewert → "0"' => [null, '0', true],
			'kein Vorgabewert bleibt' => [null, null, false],
			'kein Vorgabewert → leere Zeichenkette' => [null, '', false],
			// PostgreSQL meldet negative Werte in Klammern, das ist keine Änderung
			'(-1) → -1' => ['(-1)', -1, false],
			'(0) → 0' => ['(0)', 0, false],
			'0 → 0' => ['0', 0, false],
			'5 → 6' => ['5', 6, true],
			'Text gleich' => ['abc', 'abc', false],
			'Text anders' => ['abc', 'abd', true],
		];
	}

	/**
	 * @dataProvider providesDefaults
	 * @param mixed $fromDefault
	 * @param mixed $toDefault
	 */
	public function testDefaultChanged($fromDefault, $toDefault, bool $expected): void {
		$method = new \ReflectionMethod(PostgreSqlMigrator::class, 'defaultChanged');
		$method->setAccessible(true);
		$this->assertSame($expected, $method->invoke(null, $fromDefault, $toDefault));
	}
}
