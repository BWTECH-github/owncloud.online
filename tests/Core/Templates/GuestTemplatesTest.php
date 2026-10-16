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

namespace Tests\Core\Templates;

use OC_Template;
use Test\TestCase;

/**
 * Gastseiten aus den echten Vorlagen gerendert: Anmeldung, „Neues Passwort“ und
 * Update-Seite.
 *
 * Die Karma-Specs arbeiten mit nachgebauten Ausschnitten dieser Vorlagen. Was
 * dort an role, tabindex, for und aria-describedby steht, gilt nur, solange die
 * Vorlagen es auch ausgeben - das sichern diese Tests ab.
 */
class GuestTemplatesTest extends TestCase {
	/** @var array */
	private $savedScripts;
	/** @var array */
	private $savedStyles;

	protected function setUp(): void {
		parent::setUp();
		// script() und vendor_script() in den Vorlagen merken sich ihre Dateien
		// global - nach dem Test wieder herstellen.
		$this->savedScripts = \OC_Util::$scripts;
		$this->savedStyles = \OC_Util::$styles;
	}

	protected function tearDown(): void {
		\OC_Util::$scripts = $this->savedScripts;
		\OC_Util::$styles = $this->savedStyles;
		parent::tearDown();
	}

	/**
	 * @param string $name Vorlage unter core/templates, ohne .php
	 * @param array $vars
	 * @return string
	 */
	private function render($name, array $vars) {
		$template = new OC_Template('core', $name);
		foreach ($vars as $key => $value) {
			$template->assign($key, $value);
		}
		return $template->fetchPage();
	}

	/**
	 * @param string $html
	 * @return \DOMXPath
	 */
	private function parse($html) {
		$document = new \DOMDocument();
		$previous = \libxml_use_internal_errors(true);
		try {
			$document->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>');
		} finally {
			\libxml_clear_errors();
			\libxml_use_internal_errors($previous);
		}
		return new \DOMXPath($document);
	}

	/**
	 * @param \DOMXPath $xpath
	 * @param string $query
	 * @return \DOMElement
	 */
	private function single(\DOMXPath $xpath, $query) {
		$nodes = $xpath->query($query);
		$this->assertSame(1, $nodes->length, "genau ein Treffer erwartet: $query");
		return $nodes->item(0);
	}

	/**
	 * Jedes Etikett zeigt auf ein Feld, das es gibt.
	 *
	 * @param \DOMXPath $xpath
	 */
	private function assertLabelsPointAtFields(\DOMXPath $xpath) {
		$labels = $xpath->query('//label[@for]');
		$this->assertGreaterThan(0, $labels->length);
		foreach ($labels as $label) {
			$target = $label->getAttribute('for');
			$this->single($xpath, "//input[@id='$target']");
		}
	}

	/**
	 * Keine Verweise auf fremde Projektseiten (Nutzerregel: nur owncloud.online).
	 *
	 * @param string $html
	 */
	private function assertNoForeignLinks($html) {
		$this->assertDoesNotMatchRegularExpression('#owncloud\.(org|com)|github\.com/owncloud#i', $html);
	}

	private function loginVars(array $overrides = []) {
		return \array_merge([
			'messages' => [],
			'strictLoginEnforced' => false,
			'loginName' => '',
			'user_autofocus' => true,
			'rememberLoginAllowed' => true,
			'rememberLoginState' => 0,
			'canResetPassword' => true,
			'resetPasswordLink' => '',
			'alt_login' => [],
			'throttleRetryAfter' => 0,
		], $overrides);
	}

	/**
	 * ?redirect_url[]=x: Ein Array erschien als „Array“ im versteckten Feld
	 * (mit Warnung „Array to string conversion“). Es entfällt wie ein fehlender
	 * Wert.
	 */
	public function testLoginLeavesOutRedirectUrlArray() {
		$html = $this->render('login', $this->loginVars(['redirect_url' => ['x']]));

		$this->assertSame(0, $this->parse($html)->query("//input[@name='redirect_url']")->length);
		$this->assertStringNotContainsString('Array', $html);
	}

	public function testLoginKeepsRedirectUrlString() {
		$html = $this->render('login', $this->loginVars(['redirect_url' => '%2Findex.php%2Ff%2F17']));

		$field = $this->single($this->parse($html), "//input[@type='hidden'][@name='redirect_url']");
		$this->assertSame('%2Findex.php%2Ff%2F17', $field->getAttribute('value'));
	}

	public function testLoginLabelsPointAtTheirFields() {
		$xpath = $this->parse($this->render('login', $this->loginVars()));

		$this->assertLabelsPointAtFields($xpath);
		$this->single($xpath, "//label[@for='remember_login']");
		$this->assertSame(0, $xpath->query("//button[@id='submit'][@aria-describedby]")->length);
	}

	/**
	 * Während der Sperre beschreibt der Hinweis den Anmeldeknopf, das Ende der
	 * Wartezeit meldet eine eigene Statusregion, und der Link zum Zurücksetzen
	 * steht im Hinweis.
	 */
	public function testLoginThrottleNoticeIsWiredUp() {
		$html = $this->render('login', $this->loginVars(['throttleRetryAfter' => 30]));
		$xpath = $this->parse($html);

		$button = $this->single($xpath, "//button[@id='submit']");
		$this->assertSame('login-throttle', $button->getAttribute('aria-describedby'));
		$this->single($xpath, "//*[@id='login-throttle']");
		$status = $this->single($xpath, "//*[@id='login-throttle-status']");
		$this->assertSame('status', $status->getAttribute('role'));
		$this->assertSame('polite', $status->getAttribute('aria-live'));
		$this->single($xpath, "//a[@id='login-throttle-reset']");
		$this->assertNoForeignLinks($html);
	}

	public function testResetPasswordFieldsAreLabelledAndDescribed() {
		$html = $this->render('lostpassword/resetpassword', ['link' => '/reset']);
		$xpath = $this->parse($html);

		$this->assertLabelsPointAtFields($xpath);
		$this->single($xpath, "//label[@for='retypepassword']");
		foreach (['password', 'retypepassword'] as $id) {
			$field = $this->single($xpath, "//input[@id='$id']");
			$this->assertSame('message', $field->getAttribute('aria-describedby'));
		}
		$message = $this->single($xpath, "//*[@id='message']");
		$this->assertSame('alert', $message->getAttribute('role'));
		$this->assertNoForeignLinks($html);
	}

	public function testUpdatePageProgressIsAnnounced() {
		$html = $this->render('update.admin', [
			'productName' => 'owncloud.online',
			'version' => '11.0.22',
			'isAppsOnlyUpgrade' => false,
			'appsToUpgrade' => [],
			'oldTheme' => null,
		]);
		$xpath = $this->parse($html);

		$title = $this->single($xpath, "//h2[@id='update-progress-title']");
		$this->assertSame('-1', $title->getAttribute('tabindex'));
		$this->assertSame('status', $this->single($xpath, "//*[@id='update-progress-message']")->getAttribute('role'));
		$this->assertSame('alert', $this->single($xpath, "//*[@id='update-progress-message-error']")->getAttribute('role'));

		$toggle = $this->single($xpath, "//a[contains(@class, 'update-show-detailed')]");
		$this->assertSame('#', $toggle->getAttribute('href'));
		$this->assertSame('button', $toggle->getAttribute('role'));
		$this->assertSame('false', $toggle->getAttribute('aria-expanded'));
		$controlled = $toggle->getAttribute('aria-controls');
		$this->single($xpath, "//*[@id='$controlled']");
		// Das Pfeilbild ist Zierde: alt vorhanden und leer.
		$caret = $this->single($xpath, "//a[contains(@class, 'update-show-detailed')]/img");
		$this->assertTrue($caret->hasAttribute('alt'));
		$this->assertSame('', $caret->getAttribute('alt'));
		$this->assertNoForeignLinks($html);
	}
}
