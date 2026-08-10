<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH
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
 * Modified by BW-Tech GmbH on 2026-07-08.
 * Changes:
 *   - sync bundled market app with fork fixes
 *   - bundle the market app (neutral, local catalog default)
 */

namespace OCA\Market\Command;

use DomainException;
use Exception;
use OCA\Market\MarketService;
use OCA\Market\VersionHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InstallApp extends Command {
	private int $exitCode = 0;

	public function __construct(
		private readonly MarketService $marketService,
		private readonly VersionHelper $versionHelper,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('market:install')
			->setDescription('Install apps from the marketplace. If already installed and an update is available the update will be installed.')
			->addArgument(
				'ids',
				InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
				'Ids of the apps'
			)
			->addOption(
				'local',
				'l',
				InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
				'Optional path to a local app packages'
			);
	}

	/**
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->marketService->canInstall()) {
			throw new Exception("Installing apps is not supported because the app folder is not writable.");
		}

		$appIds = \array_unique($input->getArgument('ids'));
		$localPackagesArray = \array_unique($input->getOption('local'));

		if (!\count($localPackagesArray) && !\count($appIds)) {
			$output->writeln("No appId or path to a local package specified. Nothing to do.");
			return $this->exitCode;
		}

		if (\count($localPackagesArray)) {
			foreach ($localPackagesArray as $localPackage) {
				$this->installLocalPackage($localPackage, $output);
			}
			return $this->exitCode;
		}

		foreach ($appIds as $appId) {
			$this->installRemoteApp($appId, $output);
		}
		return $this->exitCode;
	}

	private function installLocalPackage(string $localPackage, OutputInterface $output): void {
		$appInfo = $this->marketService->readAppPackage($localPackage);
		$appId = $appInfo['id'];
		try {
			if ($this->marketService->isAppInstalled($appId)) {
				$installedAppInfo = $this->marketService->getInstalledAppInfo($appId);
				$currentVersion = (string) $installedAppInfo['version'];
				$packageVersion = (string) $appInfo['version'];
				try {
					$this->checkVersion($currentVersion, $packageVersion);
					$output->writeln("$appId: Installing new version from $localPackage");
					$this->marketService->updatePackage($localPackage);
					$output->writeln("$appId: App updated.");
				} catch (DomainException $e) {
					$output->writeln("$appId: $localPackage {$e->getMessage()}");
				}
			} else {
				$output->writeln("$appId: Installing new app from $localPackage");
				$this->marketService->installPackage($localPackage);
				$output->writeln("$appId: App installed.");
			}
		} catch (Exception $ex) {
			$output->writeln("<error> $appId: {$ex->getMessage()} </error>");
			$this->exitCode = 1;
		}
	}

	private function installRemoteApp(string $appId, OutputInterface $output): void {
		try {
			if ($this->marketService->isAppInstalled($appId)) {
				$updateVersions = $this->marketService->getAvailableUpdateVersions($appId);
				$updateVersion = $updateVersions['minor'];
				if ($updateVersion !== false) {
					$output->writeln("$appId: Installing new version $updateVersion ...");
					// Zielversion explizit übergeben: ohne targetVersion nimmt
					// downloadPackage die neueste Release — ggf. ein stilles
					// Major-Upgrade statt des gemeldeten Minor-Updates.
					$this->marketService->updateApp($appId, $updateVersion);
					$output->writeln("$appId: App updated.");
					return;
				}
				$updateMessage = $updateVersions['major'] === false
					? 'App already installed and no update available'
					: "Major update is available, use market:upgrade --major $appId.";
				$output->writeln("$appId: $updateMessage");
				return;
			}
			$output->writeln("$appId: Installing new app ...");
			$this->marketService->installApp($appId);
			$output->writeln("$appId: App installed.");
		} catch (Exception $ex) {
			$output->writeln("<error>$appId: {$ex->getMessage()}</error>");
			$this->exitCode = 1;
		}
	}

	/**
	 * @throws DomainException
	 */
	protected function checkVersion(string $installedVersion, string $packageVersion): void {
		if ($this->versionHelper->lessThanOrEqualTo($packageVersion, $installedVersion)) {
			throw new DomainException('has the same or older version of the app.');
		}
		$isMajorUpdate = !$this->versionHelper->isSameMajorVersion($installedVersion, $packageVersion);
		if ($isMajorUpdate) {
			throw new DomainException('has a different major version, try market:upgrade --major instead.');
		}
	}
}
