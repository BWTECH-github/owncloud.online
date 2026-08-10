<?php
/**
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 *
 *
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
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-16.
 * Changes:
 *   - bundle the market app (neutral, local catalog default)
 */

namespace OCA\Market;

use OCP\App\AppUpdateNotFoundException;

class Listener {
	public function __construct(
		private readonly MarketService $marketService,
	) {
	}

	public function upgradeAppStoreApp(string $app, bool $isMajorUpdate): void {
		$updateVersions = $this->marketService->getAvailableUpdateVersions($app);
		$updateVersion = $this->marketService->chooseCandidate(
			$updateVersions,
			$isMajorUpdate
		);
		if ($updateVersion !== false) {
			$this->marketService->updateApp($app, $updateVersion);
		} else {
			throw new AppUpdateNotFoundException();
		}
	}

	public function reinstallAppStoreApp(string $app): void {
		// only reinstall the code, do not run migrations
		$this->marketService->installApp($app, true);
	}
}
