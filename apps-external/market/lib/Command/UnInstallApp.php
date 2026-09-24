<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH
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

namespace OCA\Market\Command;

use Exception;
use OCA\Market\MarketService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnInstallApp extends Command {
	private int $exitCode = 0;

	public function __construct(
		private readonly MarketService $marketService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('market:uninstall')
			->setDescription('Un-Install apps.')
			->addArgument(
				'ids',
				InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
				'Ids of the apps'
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->marketService->canInstall()) {
			throw new Exception("Un-Installing apps is not supported because the app folder is not writable.");
		}

		$appIds = \array_unique($input->getArgument('ids'));

		if (!\count($appIds)) {
			$output->writeln("No appIds specified. Nothing to do.");
			return 0;
		}

		foreach ($appIds as $appId) {
			try {
				$output->writeln("$appId: Un-Installing ...");
				$this->marketService->uninstallApp($appId);
				$output->writeln("$appId: App uninstalled.");
			} catch (Exception $ex) {
				$output->writeln("<error>$appId: {$ex->getMessage()}</error>");
				$this->exitCode = 1;
			}
		}
		return $this->exitCode;
	}
}
