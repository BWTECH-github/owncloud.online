<?php
/**
 * @author Tom Needham
 * @copyright Copyright (c) 2016 Tom Needham tom@owncloud.com
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-08-06.
 * Changes:
 *   - make the chunk cache mock store bytes and pin the encryption help link
 */

namespace Tests\Settings\Panels\Admin;

use OC\Settings\Panels\Admin\Encryption;

/**
 * @package Tests\Settings\Panels\Admin
 */
class EncryptionTest extends \Test\TestCase {
	/** @var Encryption */
	private $panel;

	public function setUp(): void {
		parent::setUp();
		$this->panel = new Encryption();
	}

	public function testGetSection() {
		$this->assertEquals('encryption', $this->panel->getSectionID());
	}

	public function testGetPriority() {
		$this->assertIsInt($this->panel->getPriority());
		$this->assertGreaterThan(-100, $this->panel->getPriority());
		$this->assertLessThan(100, $this->panel->getPriority());
	}

	public function testGetPanel() {
		$templateHtml = $this->panel->getPanel()->fetchPage();
		$this->assertStringContainsString('id="encryptionAPI"', $templateHtml);
		// Der Hilfe-Link muss auf die eigene Doku zeigen. Vorher pruefte dieser
		// Test nur auf die Zeichenfolge "com" - das traf allein die
		// doc.owncloud.com-URL und schlug daher fehl, sobald der Link korrekt auf
		// docs.owncloud.online umgebogen wurde.
		$this->assertStringContainsString(
			\link_to_docs(\OCP\Constants::DOCS_ADMIN_ENCRYPTION),
			$templateHtml
		);
		$this->assertStringNotContainsString('doc.owncloud.com', $templateHtml);
	}
}
