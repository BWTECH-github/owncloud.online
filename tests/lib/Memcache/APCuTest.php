<?php

/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-03-16.
 * Changes:
 *   - PHP 8.4 compatibility and owncloud.online design integration
 *   - php8.3 (#41449)
 */

namespace Test\Memcache;

class APCuTest extends Cache {
	protected function setUp(): void {
		parent::setUp();

		if (!\OC\Memcache\APCu::isAvailable()) {
			$this->markTestSkipped('The APCu extension is not available.');
			return;
		}
		$this->instance=new \OC\Memcache\APCu(self::getUniqueID());
	}
}
