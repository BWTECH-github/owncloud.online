<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
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

namespace OC\Core\Command\Config\System;

use OC\Core\Command\Base;
use OC\L10N\Factory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Entfernt default_language, ohne dass Bestandskonten die Sprache wechseln, bevor ihr Browser es sagt:
 * Konten ohne eigene Sprache bekommen den bisherigen Standard als „zuletzt gesehene Browsersprache“.
 * Mails und Hintergrundjobs bleiben so in der gewohnten Sprache, der nächste Besuch übernimmt den Browser.
 */
class DropDefaultLanguage extends Base {
	/** @var IConfig */
	private $config;
	/** @var IUserManager */
	private $userManager;

	public function __construct(IConfig $config, IUserManager $userManager) {
		parent::__construct();
		$this->config = $config;
		$this->userManager = $userManager;
	}

	protected function configure() {
		parent::configure();

		$this
			->setName('config:system:drop-default-language')
			->setDescription('Remove default_language so the browser language applies. Accounts without an own language keep the old default until their browser says otherwise.')
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Only count the accounts that would get the old default, change nothing.'
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$default = $this->config->getSystemValue('default_language', false);
		if (!\is_string($default) || $default === '') {
			$output->writeln('default_language is not set, nothing to do.');
			return 0;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$count = 0;
		$this->userManager->callForAllUsers(function (IUser $user) use ($default, $dryRun, &$count) {
			$uid = $user->getUID();
			if ($this->config->getUserValue($uid, 'core', 'lang', null) !== null) {
				return;
			}
			$count++;
			if (!$dryRun) {
				$this->config->setUserValue($uid, 'core', 'lang', $default);
				$this->config->setUserValue($uid, 'core', Factory::LANG_SOURCE_KEY, Factory::LANG_SOURCE_BROWSER);
			}
		});

		if ($dryRun) {
			$output->writeln("Dry run: $count accounts would keep '$default' until their browser says otherwise.");
			return 0;
		}

		$this->config->deleteSystemValue('default_language');
		$output->writeln("default_language '$default' removed, $count accounts keep it until their browser says otherwise.");
		return 0;
	}
}
