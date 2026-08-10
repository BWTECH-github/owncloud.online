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

use InvalidArgumentException;
use OCP\App\IAppManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
	public function __construct(
		protected readonly IManager $notificationManager,
		protected readonly IAppManager $appManager,
		protected readonly IFactory $l10NFactory,
	) {
	}

	/**
	 * @throws InvalidArgumentException When the notification was not prepared by a notifier
	 */
	#[\Override]
	public function prepare(INotification $notification, $languageCode): INotification {
		if (
			$notification->getApp() !== 'market'
			|| $notification->getObjectType() === 'core'
		) {
			throw new InvalidArgumentException();
		}

		$l = $this->l10NFactory->get('market', $languageCode);
		$appInfo = $this->getAppInfo($notification->getObjectType());
		$appName = ($appInfo === null) ? $notification->getObjectType() : $appInfo['name'];
		$appVersions = $this->getAppVersions();
		if (isset($appVersions[$notification->getObjectType()])) {
			$this->updateAlreadyInstalledCheck($notification, $appVersions[$notification->getObjectType()]);
		} else {
			throw new InvalidArgumentException();
		}

		$notification->setParsedSubject(
			$l->t(
				'Update for %1$s to version %2$s is available.',
				[$appName, $notification->getObjectId()]
			)
		);
		return $notification;
	}

	/**
	 * Remove the notification and prevent rendering
	 * when either the update is installed or the app was removed.
	 *
	 * @throws InvalidArgumentException When the update is already installed
	 */
	protected function updateAlreadyInstalledCheck(INotification $notification, string $installedVersion): void {
		if (
			$this->appManager->getAppPath($notification->getObjectType()) === false
			|| \version_compare($notification->getObjectId(), $installedVersion, '<=')
		) {
			$this->notificationManager->markProcessed($notification);
			throw new InvalidArgumentException();
		}
	}

	/**
	 * @return array<string, string>
	 */
	protected function getAppVersions(): array {
		$versions = [];
		foreach ((array) $this->appManager->getAllApps() as $appId) {
			$appInfo = $this->appManager->getAppInfo($appId);
			if (\is_array($appInfo) && isset($appInfo['version'])) {
				$versions[$appId] = (string) $appInfo['version'];
			}
		}
		return $versions;
	}

	/**
	 * @return array|null
	 */
	protected function getAppInfo(string $appId) {
		return $this->appManager->getAppInfo($appId);
	}
}
