<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-03-11.
 * Changes:
 *   - remove unused code from \OC\Archive classes - fixes deprecated usage of Z...
 */

namespace Test\Archive;

use OC\Archive\TAR;

class TARTest extends TestBase {
	protected function getExisting() {
		return new TAR($this->getArchiveTestDataDir() . '/data.tar.gz');
	}
}
