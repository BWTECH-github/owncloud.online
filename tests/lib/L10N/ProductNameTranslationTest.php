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

namespace Test\L10N;

use Test\TestCase;

/**
 * Sichtbare Übersetzungen nennen das Produkt owncloud.online. Geprüft werden
 * die Übersetzungsdateien des Kerns und der mitgelieferten Apps, jeweils die
 * .json (PHP) und die .js (Browser), auf Schreibvarianten des alten Namens.
 *
 * Nicht gemeint sind Pfade wie username@example.com/owncloud in der
 * Beispielsyntax beim Teilen: Davor steht ein Schrägstrich.
 */
class ProductNameTranslationTest extends TestCase {
	/**
	 * Alter Name samt Tippfehlern und Umschrift: ownCloud, OwnCloud, Owncloud,
	 * ownClound, ownClud, ownClowd, onwCloud, wnCloud, #owncloud, оунКлауд.
	 * Davor darf weder ein Buchstabe noch ein Schrägstrich stehen, dahinter
	 * nicht „.online“.
	 */
	private const OLD_PRODUCT_NAME = '/(?<![\/\p{L}])(?:own|onw|wn)cl(?:ou|o|u)n?[dw](?!\.online)|(?<!\p{L})оунклауд/iu';

	/**
	 * @return string[]
	 */
	private static function translationFiles() {
		$files = [];
		foreach (['core/l10n', 'apps/*/l10n'] as $dir) {
			foreach (['json', 'js'] as $extension) {
				$files = \array_merge($files, \glob(\OC::$SERVERROOT . "/$dir/*.$extension"));
			}
		}
		\sort($files);
		return $files;
	}

	/**
	 * @param string $file
	 * @return array|null Übersetzungen der Datei, null wenn sie sich nicht lesen lässt
	 */
	private static function readTranslations($file) {
		$content = \file_get_contents($file);
		if (\substr($file, -5) === '.json') {
			$data = \json_decode($content, true);
			return $data['translations'] ?? null;
		}
		// OC.L10N.register("app", { ... }, "nplurals=...");
		$register = \strpos($content, 'OC.L10N.register(');
		$start = $register === false ? false : \strpos($content, '{', $register);
		$end = \strrpos($content, '},');
		if ($start === false || $end === false || $end < $start) {
			return null;
		}
		return \json_decode(\substr($content, $start, $end - $start + 1), true);
	}

	public function testTranslationsUseTheCurrentProductName() {
		$files = self::translationFiles();
		$this->assertNotEmpty($files);

		$found = [];
		foreach ($files as $file) {
			$translations = self::readTranslations($file);
			$this->assertIsArray($translations, "$file lässt sich nicht lesen");
			foreach ($translations as $key => $value) {
				foreach ((array)$value as $text) {
					if (\preg_match_all(self::OLD_PRODUCT_NAME, $text, $matches)) {
						$found[] = \substr($file, \strlen(\OC::$SERVERROOT) + 1)
							. ": $key => " . \implode(', ', $matches[0]);
					}
				}
			}
		}

		$this->assertSame([], $found, "Alter Produktname in Übersetzungen:\n" . \implode("\n", $found));
	}

	public function providesOldAndCurrentNames() {
		return [
			'alter Name' => ['Diese ownCloud-Instanz', true],
			'Tippfehler' => ['Diese ownClound-Instanz', true],
			'Großschreibung' => ['Servidor OwnCloud', true],
			'Hashtag' => ['Federated Cloud #owncloud %s', true],
			'kyrillische Umschrift' => ['Поверљиви оунКлауд сервери', true],
			'ohne o' => ['núcleo wnCloud', true],
			'neuer Name' => ['Diese owncloud.online-Instanz', false],
			'neuer Name als Hashtag' => ['Federated Cloud #owncloud.online %s', false],
			'Pfad im Beispiel' => ['username@example.com/owncloud', false],
			'Pfad mit Tippfehler im Beispiel' => ['nomeusuario@exemplo.com/ouwncloud', false],
			'anderes Wort' => ['Download', false],
		];
	}

	/**
	 * @dataProvider providesOldAndCurrentNames
	 *
	 * @param string $text
	 * @param bool $isOldName
	 */
	public function testPatternRecognisesOnlyTheOldName($text, $isOldName) {
		$this->assertSame($isOldName, \preg_match(self::OLD_PRODUCT_NAME, $text) === 1);
	}
}
