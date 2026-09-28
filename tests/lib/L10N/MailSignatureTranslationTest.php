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

use OC\L10N\Factory;
use OC\L10N\L10N;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Theme\IThemeService;
use Test\TestCase;

/**
 * Die Signatur jeder Mail (core/templates/*.mail.footer.php) muss in jeder
 * deutschen Sprache deutsch sein. Die L10N-Factory laedt nur die Datei der
 * genauen Sprache, ein Konto mit de_AT oder de_CH faellt nicht auf de zurueck:
 * fehlen die Eintraege dort, endet eine sonst deutsche Mail englisch.
 *
 * Die Signatur folgt der Anrede der Mailtexte derselben Datei: de, de_AT und
 * de_CH duzen ('mit dir/Dir geteilt', 'dein Passwort'), de_DE siezt.
 */
class MailSignatureTranslationTest extends TestCase {
	/**
	 * @return Factory
	 */
	private function getFactory() {
		return new Factory(
			$this->createMock(IConfig::class),
			$this->createMock(IRequest::class),
			$this->createMock(IThemeService::class),
			\OC::$SERVERROOT,
			$this->createMock(IUserSession::class)
		);
	}

	public function providesGermanLanguages() {
		return [
			'de (Du-Form)' => ['de', 'Viele Grüße,', 'dein owncloud.online-Team', 'mit dir geteilt'],
			'de_DE (Sie-Form)' => ['de_DE', 'Mit freundlichen Grüßen,', 'Ihr owncloud.online-Team', 'mit Ihnen geteilt'],
			'de_AT (Du-Form)' => ['de_AT', 'Viele Grüße,', 'dein owncloud.online-Team', 'mit Dir geteilt'],
			'de_CH (Du-Form, ohne ß)' => ['de_CH', 'Viele Grüsse,', 'dein owncloud.online-Team', 'mit Dir geteilt'],
		];
	}

	/**
	 * @dataProvider providesGermanLanguages
	 *
	 * @param string $lang
	 * @param string $greeting
	 * @param string $team
	 * @param string $sharedWithYou Anrede im Text der Freigabe-Mail
	 */
	public function testMailSignatureIsTranslated($lang, $greeting, $team, $sharedWithYou) {
		$l = new L10N($this->getFactory(), 'core', $lang, [\OC::$SERVERROOT . "/core/l10n/$lang.json"]);

		$this->assertSame($greeting, (string)$l->t('Best regards,'));
		$this->assertSame($team, (string)$l->t('your %s Team', ['owncloud.online']));
		$this->assertSame('Eine Marke der', (string)$l->t('A trademark of'));
		// Text und Signatur derselben Mail (core/templates/altmail.php)
		// sprechen den Empfaenger gleich an.
		$this->assertStringContainsString(
			$sharedWithYou,
			(string)$l->t("Hey there,\n\njust letting you know that %s shared %s with you.\nView it: %s\n\n", ['Frau Lehmann', 'Plan.pdf', 'http://x/s/abc'])
		);
	}

	/**
	 * The browser loads the .js sibling, the server the .json file: both must
	 * be valid and carry the same entries.
	 *
	 * @dataProvider providesGermanLanguages
	 *
	 * @param string $lang
	 */
	public function testJsAndJsonCarryTheSameTranslations($lang) {
		$json = \json_decode(\file_get_contents(\OC::$SERVERROOT . "/core/l10n/$lang.json"), true);
		$this->assertIsArray($json, "core/l10n/$lang.json is not valid JSON");

		$js = \file_get_contents(\OC::$SERVERROOT . "/core/l10n/$lang.js");
		$this->assertSame(1, \preg_match('/^OC\.L10N\.register\(\s*"core",\s*(\{.*\}),\s*("[^"]*")\);\s*$/s', $js, $matches), "core/l10n/$lang.js has an unexpected layout");
		$this->assertSame($json['translations'], \json_decode($matches[1], true));
		$this->assertSame($json['pluralForm'], \json_decode($matches[2], true));
	}
}
