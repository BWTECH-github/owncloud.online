<?php
/**
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 *
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
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
 */

namespace OCA\Market;

use DateTime;
use OC\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Notification\IManager;

/**
 * Checks for updates for enabled apps at the marketplace.
 */
class CheckUpdateBackgroundJob extends TimedJob {
	/** @var string[]|null cached on first call */
	private ?array $users = null;

	public function __construct(
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IManager $notificationManager,
		protected readonly IGroupManager $groupManager,
		private readonly MarketService $marketService,
		private readonly IURLGenerator $urlGenerator,
	) {
		// Run daily
		$this->setInterval(60 * 60 * 24);
	}

	#[\Override]
	protected function run($argument) {
		$updates = $this->marketService->getUpdates();

		foreach ($updates as $appId => $appInfo) {
			$url = $this->urlGenerator->linkToRoute('market.page.index') . '#/app/' . $appId;
			// Nur den höchsten Kandidaten melden: createNotifications hat pro App
			// nur EINEN Versions-Merker — zwei Aufrufe (major+minor) löschen sich
			// gegenseitig die Notification und re-notifizieren täglich.
			$version = $appInfo['major'] !== false ? $appInfo['major'] : $appInfo['minor'];
			if ($version !== false) {
				$this->createNotifications($appId, $version, $url);
			}
		}
	}

	protected function createNotifications(string $app, string $version, string $url): void {
		$lastNotification = $this->config->getAppValue('market', $app, false);
		if ($lastNotification === $version) {
			// We already notified about this update
			return;
		}
		if ($lastNotification !== false) {
			// Delete old updates
			$this->deleteOutdatedNotifications($app, $lastNotification);
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('market')
			->setDateTime(
				DateTime::createFromFormat('U', \strval($this->timeFactory->getTime()))
			)
			->setObject($app, $version)
			->setSubject('update_available')
			->setLink($url);

		foreach ($this->getUsersToNotify() as $uid) {
			$notification->setUser($uid);
			$this->notificationManager->notify($notification);
		}

		$this->config->setAppValue('market', $app, $version);
	}

	/**
	 * @return string[]
	 */
	protected function getUsersToNotify(): array {
		if ($this->users !== null) {
			return $this->users;
		}

		$notifyGroups = \json_decode($this->config->getAppValue('market', 'notify_groups', '["admin"]'), true);
		$users = [];
		foreach ($notifyGroups as $group) {
			$groupToNotify = $this->groupManager->get($group);
			if ($groupToNotify instanceof IGroup) {
				foreach ($groupToNotify->getUsers() as $user) {
					$users[$user->getUID()] = true;
				}
			}
		}

		return $this->users = \array_keys($users);
	}

	/**
	 * Delete notifications for old updates.
	 */
	protected function deleteOutdatedNotifications(string $app, string $version): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('market')->setObject($app, $version);
		$this->notificationManager->markProcessed($notification);
	}
}
