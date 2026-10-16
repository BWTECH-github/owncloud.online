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

namespace Test\legacy;

use OC_Template;
use Test\TestCase;

/**
 * Kopfelemente, die Apps per \OCP\Util::addHeader() in den Seitenkopf schreiben.
 *
 * Die oauth2-App legt beim Anmelden ein <data key="oauth2" …> ohne Inhalt ab.
 * Als "<data …/>" ausgegeben blieb das Element offen und umschloss die ganze
 * Seite – die Anmeldekarte verlor ihr Flex-Layout und rutschte nach links oben.
 */
class TemplateHeadersTest extends TestCase {
	public function testDataElementWithoutTextIsClosed(): void {
		$html = OC_Template::renderHeaders([
			['tag' => 'data', 'attributes' => ['key' => 'oauth2', 'client' => 'Desktop Client'], 'text' => null],
		]);

		$this->assertSame('<data key="oauth2" client="Desktop Client"></data>', $html);
	}

	public function testVoidElementsKeepTheShortForm(): void {
		$html = OC_Template::renderHeaders([
			['tag' => 'meta', 'attributes' => ['property' => 'og:title', 'content' => 'Wolke'], 'text' => null],
			['tag' => 'LINK', 'attributes' => ['rel' => 'stylesheet', 'href' => '/a.css'], 'text' => null],
		]);

		$this->assertSame('<meta property="og:title" content="Wolke"/><LINK rel="stylesheet" href="/a.css"/>', $html);
	}

	public function testTextIsEscapedAndClosed(): void {
		$html = OC_Template::renderHeaders([
			['tag' => 'script', 'attributes' => ['nonce' => 'n"1'], 'text' => '</script><b>'],
		]);

		$this->assertSame('<script nonce="n&quot;1">&lt;/script&gt;&lt;b&gt;</script>', $html);
	}

	public function testEmptyListGivesEmptyString(): void {
		$this->assertSame('', OC_Template::renderHeaders([]));
	}

	public function testEmptyTextForcesClosingTag(): void {
		$html = OC_Template::renderHeaders([
			['tag' => 'data', 'attributes' => ['key' => 'x'], 'text' => ''],
			['tag' => 'script', 'attributes' => ['src' => '/a.js'], 'text' => ''],
		]);

		$this->assertSame('<data key="x"></data><script src="/a.js"></script>', $html);
	}

	public function testElementWithoutAttributesIsClosed(): void {
		$html = OC_Template::renderHeaders([
			['tag' => 'data', 'attributes' => [], 'text' => null],
		]);

		$this->assertSame('<data></data>', $html);
	}

	/**
	 * Bisheriges Verhalten festhalten: "" erzwingt das Endtag auch bei leeren
	 * HTML-Elementen. Der Parser verwirft das überzählige </meta> im Kopf.
	 */
	public function testVoidElementWithEmptyTextKeepsClosingTag(): void {
		$html = OC_Template::renderHeaders([
			['tag' => 'meta', 'attributes' => ['name' => 'x', 'content' => 'y'], 'text' => ''],
		]);

		$this->assertSame('<meta name="x" content="y"></meta>', $html);
	}

	/**
	 * Echter Ausgabeweg wie beim Anmelden mit oauth2: \OCP\Util::addHeader()
	 * → OC_Template::fetchPage() → TemplateLayout „guest“ →
	 * core/templates/layout.guest.php. Als Inhalt dient die 403-Vorlage, sie
	 * kommt ohne Variablen aus.
	 */
	public function testGuestPageClosesDataHeaderInsideHead(): void {
		$savedHeaders = \OC_Util::$headers;
		try {
			\OC_Util::$headers = [];
			\OCP\Util::addHeader('data', ['key' => 'oauth2', 'client' => 'Desktop Client']);
			$html = (new OC_Template('core', '403', 'guest'))->fetchPage();
		} finally {
			\OC_Util::$headers = $savedHeaders;
		}

		$closed = '<data key="oauth2" client="Desktop Client"></data>';
		$this->assertStringContainsString($closed, $html);
		$this->assertDoesNotMatchRegularExpression('/<data\b[^>]*\/>/', $html);
		// Im Quelltext steht das geschlossene Element vor </head> und umschließt die Karte nicht.
		// Im DOM landet <data> dennoch im body: Der Parser beendet den Kopf an diesem Tag.
		$this->assertLessThan(\strpos($html, '</head>'), \strpos($html, $closed));
		$this->assertStringContainsString('<div class="v-align">', $html);
	}
}
