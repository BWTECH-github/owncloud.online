<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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
 */

namespace Test\legacy;

use OC_Defaults;
use Test\TestCase;

class DefaultsTest extends TestCase {
	/**
	 * @var OC_Defaults | \PHPUnit\Framework\MockObject\MockObject
	 */
	protected $defaults;

	public function testGetShortFooterFromCore() {
		$imprintUrl = 'http://example.org/imprint';
		$privacyPolicyUrl = 'http://example.org/privacy';
		$defaults = $this->getDefaultsMock(
			['getImprintUrl', 'getPrivacyPolicyUrl']
		);
		$defaults->expects($this->atLeastOnce())
			->method('getImprintUrl')
			->willReturn($imprintUrl);
		$defaults->expects($this->atLeastOnce())
			->method('getPrivacyPolicyUrl')
			->willReturn($privacyPolicyUrl);
		$footer = $defaults->getShortFooter();
		$this->assertStringContainsString($privacyPolicyUrl, $footer);
		$this->assertStringContainsString($imprintUrl, $footer);
	}

	/**
	 * Gespeicherte Rechtslinks landen in der Fußzeile der Anmeldeseite. Ein
	 * „javascript:“-Link darf dort nicht erscheinen, und ein Anführungszeichen
	 * darf das href-Attribut nicht verlassen.
	 */
	public function testFooterDropsUnsafeAndEscapesLegalUrls() {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnMap([
			['core', 'legal.imprint_url', '', 'javascript:alert(1)'],
			['core', 'legal.privacy_policy_url', '', 'https://example.org/p?a="><script>x</script>'],
		]);
		$defaults = new OC_Defaults();
		$defaults->setConfig($config);

		$footer = $defaults->getShortFooter();
		$this->assertStringNotContainsString('javascript:', $footer);
		$this->assertStringNotContainsString('Imprint', $footer);
		$this->assertStringNotContainsString('<script>', $footer);
		$this->assertStringContainsString('href="https://example.org/p?a=&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $footer);
	}

	/**
	 * @dataProvider legalUrlProvider
	 */
	public function testSanitizeLegalUrl($input, $expected) {
		$this->assertSame($expected, OC_Defaults::sanitizeLegalUrl($input));
	}

	public function legalUrlProvider() {
		return [
			['https://example.org/impressum', 'https://example.org/impressum'],
			['  http://example.org  ', 'http://example.org'],
			['HTTPS://example.org', 'HTTPS://example.org'],
			['', ''],
			[null, ''],
			['javascript:alert(1)', ''],
			['JavaScript:alert(1)', ''],
			["java\tscript:alert(1)", ''],
			['data:text/html,<script>x</script>', ''],
			['//example.org/impressum', ''],
			['example.org/impressum', ''],
			['https://', ''],
			["https://example.org/\x00x", ''],
			['https://exa mple.org', ''],
		];
	}

	public function testGetImprintWhenNotInstalled() {
		$config = $this->getMockBuilder(\OCP\IConfig::class)
			->disableOriginalConstructor()
			->getMock();
		$config->expects($this->any())
			->method('getAppValue')
			->willThrowException(new \Exception());
		$defaults = new OC_Defaults();
		$defaults->setConfig($config);

		$footer = $defaults->getShortFooter();
		$this->assertStringNotContainsString('Privacy Policy', $footer);
		$this->assertStringNotContainsString('Imprint', $footer);
	}

	protected function getDefaultsMock($mockedMethods) {
		return $this->getMockBuilder(OC_Defaults::class)
			->setMethods($mockedMethods)
			->getMock();
	}
}
