<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-03-16.
 * Changes:
 *   - PHP 8.4 compatibility and owncloud.online design integration
 *   - remove unused code from \OC\Archive classes - fixes deprecated usage of Z...
 *   - php8.3 (#41449)
 */

namespace Test\Archive;

use OC\Archive\ZIP;

class ZIPTest extends TestBase {
	protected function setUp(): void {
		parent::setUp();
	}

	protected function getExisting() {
		return new ZIP($this->getArchiveTestDataDir() . '/data.zip');
	}
}
