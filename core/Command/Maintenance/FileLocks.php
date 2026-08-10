<?php
/**
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
 * Modified by BW-Tech GmbH on 2026-05-29.
 * Changes:
 *   - Add documentation and file lock maintenance command
 */

namespace OC\Core\Command\Maintenance;

use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FileLocks extends Command {
	/**
	 * @var IDBConnection
	 */
	private $connection;

	/**
	 * @var IConfig
	 */
	private $config;

	public function __construct(IDBConnection $connection, IConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('maintenance:file-locks')
			->setDescription('Inspect and clean transactional file locks')
			->addOption(
				'cleanup-expired',
				null,
				InputOption::VALUE_NONE,
				'Delete expired file locks. This is safe to run while the instance is online.'
			)
			->addOption(
				'all',
				null,
				InputOption::VALUE_NONE,
				'Delete all file locks. This requires maintenance mode and no active uploads or sync clients.'
			)
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Show what would be deleted without changing the database.'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->connection->tableExists('file_locks')) {
			$output->writeln('<info>No file_locks table found.</info>');
			return 0;
		}

		$cleanupExpired = (bool)$input->getOption('cleanup-expired');
		$cleanupAll = (bool)$input->getOption('all');
		$dryRun = (bool)$input->getOption('dry-run');

		if ($cleanupExpired && $cleanupAll) {
			$output->writeln('<error>Use either --cleanup-expired or --all, not both.</error>');
			return 1;
		}

		$maintenance = (bool)$this->config->getSystemValue('maintenance', false);
		$before = $this->getStats();
		$this->renderStats($output, $before, $maintenance);

		if (!$cleanupExpired && !$cleanupAll) {
			$output->writeln('<comment>No cleanup option selected. Use --cleanup-expired for safe cleanup or --all in maintenance mode for emergency cleanup.</comment>');
			return 0;
		}

		if ($cleanupAll && !$maintenance) {
			$output->writeln('<error>Refusing to delete all file locks while maintenance mode is disabled.</error>');
			$output->writeln('<comment>Run: occ maintenance:mode --on</comment>');
			return 2;
		}

		if ($dryRun) {
			$count = $cleanupAll ? $before['total'] : $before['expired'];
			$output->writeln("<info>Dry run: {$count} file locks would be deleted.</info>");
			return 0;
		}

		$deleted = $cleanupAll ? $this->deleteAllLocks() : $this->deleteExpiredLocks();
		$output->writeln("<info>Deleted {$deleted} file locks.</info>");

		$after = $this->getStats();
		$this->renderStats($output, $after, $maintenance);
		return 0;
	}

	private function getStats(): array {
		$now = \time();

		return [
			'total' => $this->countLocks(),
			'active' => $this->countLocks('active', $now),
			'released' => $this->countLocks('released', $now),
			'expired' => $this->countLocks('expired', $now),
			'expired_active' => $this->countLocks('expired_active', $now),
		];
	}

	private function countLocks($filter = null, $now = null): int {
		$builder = $this->connection->getQueryBuilder();
		$builder->select($builder->createFunction('COUNT(*)'))
			->from('file_locks');

		if ($filter === 'active') {
			$builder->where($builder->expr()->neq('lock', $builder->createNamedParameter(0)));
		} elseif ($filter === 'released') {
			$builder->where($builder->expr()->eq('lock', $builder->createNamedParameter(0)));
		} elseif ($filter === 'expired') {
			$builder->where($builder->expr()->lt('ttl', $builder->createNamedParameter((int)$now)));
		} elseif ($filter === 'expired_active') {
			$builder->where(
				$builder->expr()->andX(
					$builder->expr()->lt('ttl', $builder->createNamedParameter((int)$now)),
					$builder->expr()->neq('lock', $builder->createNamedParameter(0))
				)
			);
		}

		$result = $builder->execute();
		$count = (int)$result->fetchOne();
		$result->free();

		return $count;
	}

	private function deleteExpiredLocks(): int {
		$builder = $this->connection->getQueryBuilder();
		$builder->delete('file_locks')
			->where($builder->expr()->lt('ttl', $builder->createNamedParameter(\time())));

		return (int)$builder->execute();
	}

	private function deleteAllLocks(): int {
		$builder = $this->connection->getQueryBuilder();
		$builder->delete('file_locks');

		return (int)$builder->execute();
	}

	private function renderStats(OutputInterface $output, array $stats, bool $maintenance): void {
		$table = new Table($output);
		$table
			->setHeaders(['Metric', 'Value'])
			->setRows([
				['maintenance mode', $maintenance ? 'enabled' : 'disabled'],
				['total locks', $stats['total']],
				['active locks', $stats['active']],
				['released locks', $stats['released']],
				['expired locks', $stats['expired']],
				['expired active locks', $stats['expired_active']],
			]);
		$table->render();
	}
}
