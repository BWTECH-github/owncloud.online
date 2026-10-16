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
 * Gastseiten aus den echten Vorlagen gerendert.
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
}
