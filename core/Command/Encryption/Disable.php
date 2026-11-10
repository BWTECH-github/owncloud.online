<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 *
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
 * Modified by BW-Tech GmbH on 2026-02-26.
 * Changes:
 *   - reset the bogus encryption flag on key files so disabling works after decrypt-all
 *   - doctrine/dbal:3 (#41450)
 */

namespace OC\Core\Command\Encryption;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Disable extends Command {
	/**
	 * Höchstzahl der ausgegebenen, noch verschlüsselten Pfade, damit die
	 * Ausgabe bei vielen Resten auf den Bildschirm passt. Der Rest wird nur
	 * als Anzahl gemeldet.
	 */
	private const MAX_REPORTED_PATHS = 20;

	/** @var IDBConnection */
	protected $db;
	/** @var IConfig */
	protected $config;

	/**
	 * @param IDBConnection $db
	 * @param IConfig $config
	 */
	public function __construct(IDBConnection $db, IConfig $config) {
		parent::__construct();
		$this->db = $db;
		$this->config = $config;
	}

	protected function configure() {
		$this
			->setName('encryption:disable')
			->setDescription('Disable encryption.')
		;
	}

	/**
	 * Schlüsseldateien unter files_encryption/ können im Dateicache fälschlich als
	 * verschlüsselt markiert sein, etwa die Schlüssel der Papierkorb-Kopie, die beim
	 * Empfänger entsteht, wenn er eine Datei in einer empfangenen Freigabe löscht.
	 * decrypt-all geht nur durch files, files_versions und files_trashbin und
	 * erreicht diese Zeilen nie; ohne Bereinigung ließe sich die Verschlüsselung
	 * auf solchen Instanzen nicht mehr abschalten. Schlüsseldateien sind selbst nie
	 * verschlüsselt, das Kennzeichen wird deshalb zurückgesetzt.
	 *
	 * @return int Anzahl der bereinigten Zeilen
	 */
	private function resetFlagOnKeyFiles(): int {
		$qb = $this->db->getQueryBuilder();
		// "_" passt im LIKE auf jedes Zeichen; die genaue Prüfung macht isKeyStoragePath()
		$qb->select('fc.fileid', 'fc.path', 'st.id')
			->from('filecache', 'fc')
			->innerJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
			->where($qb->expr()->gte('fc.encrypted', $qb->expr()->literal('1')))
			->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('%files_encryption/%')));
		$results = $qb->execute();
		$fileIds = [];
		while ($row = $results->fetch()) {
			if ($this->isKeyStoragePath((string)$row['id'], (string)$row['path'])) {
				$fileIds[] = (int)$row['fileid'];
			}
		}
		$results->free();

		foreach (\array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('filecache')
				->set('encrypted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->execute();
		}
		return \count($fileIds);
	}

	/**
	 * Liegt der Pfad im Schlüsselspeicher? Im Heimatspeicher eines Nutzers und im
	 * Wurzelspeicher ist das files_encryption/…, im Wurzelspeicher zusätzlich
	 * <uid>/files_encryption/…. Ein Ordner dieses Namens unter files/ oder auf
	 * einem externen Speicher ist Nutzerinhalt und zählt nicht dazu.
	 *
	 * @param string $storageId Kennung aus oc_storages, z. B. home::anna
	 * @param string $path Pfad innerhalb des Speichers
	 * @return bool
	 */
	protected function isKeyStoragePath(string $storageId, string $path): bool {
		$dataDir = \rtrim((string)$this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data'), '/');
		$isRoot = $storageId === 'local::' . $dataDir . '/' || \strpos($storageId, 'object::store:') === 0;
		$isHome = \strpos($storageId, 'home::') === 0 || \strpos($storageId, 'object::user:') === 0;
		if (($isRoot || $isHome) && \strpos($path, 'files_encryption/') === 0) {
			return true;
		}
		return $isRoot && \preg_match('#^[^/]+/files_encryption/#', $path) === 1;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$resetKeyFiles = $this->resetFlagOnKeyFiles();
		if ($resetKeyFiles > 0) {
			$output->writeln("Reset the encryption flag of $resetKeyFiles key files, they are never encrypted themselves.");
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from('filecache', 'fc')
			->where($qb->expr()->gte('fc.encrypted', $qb->expr()->literal('1')));
		$results = $qb->execute();
		$encryptedCount = (int) $results->fetchOne();
		$results->free();
		if ($encryptedCount > 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fc.path')
				->from('filecache', 'fc')
				->where($qb->expr()->gte('fc.encrypted', $qb->expr()->literal('1')))
				->setMaxResults(self::MAX_REPORTED_PATHS);
			$results = $qb->execute();
			$encryptedPaths = $results->fetchFirstColumn();
			$results->free();

			$output->writeln('<error>The system still has encrypted files. Please decrypt them all before disabling encryption.</error>');
			$output->writeln('The following paths in the file cache are still flagged as encrypted:');
			foreach ($encryptedPaths as $path) {
				$output->writeln("    $path");
			}
			$remaining = $encryptedCount - \count($encryptedPaths);
			if ($remaining > 0) {
				$output->writeln("    ... and $remaining more still encrypted");
			}
			$output->writeln('');
			$output->writeln('Run "occ encryption:decrypt-all" to decrypt these. Entries on shared or');
			$output->writeln('external storage are skipped by decrypt-all and have to be decrypted by');
			$output->writeln('their owner, e.g. via "occ encryption:decrypt-all <user>".');
			// Nach einem vollständigen Lauf mit Nutzerschlüsseln steht
			// encryption_enabled auf "no", decrypt-all meldet dann nur
			// "Nothing to do" und rührt die Reste nicht an.
			$output->writeln('If decrypt-all answers "Nothing to do", run "occ encryption:enable" first.');
			return 1;
		}

		/**
		 * Delete both useMasterKey and userSpecificKey
		 */
		$this->config->deleteAppValue('encryption', 'useMasterKey');
		$this->config->deleteAppValue('encryption', 'userSpecificKey');

		$output->writeln('<info>Cleaned up config</info>');

		if ($this->config->getAppValue('core', 'encryption_enabled', 'no') !== 'yes') {
			$output->writeln('Encryption is already disabled');
		} else {
			$this->config->setAppValue('core', 'encryption_enabled', 'no');
			$output->writeln('<info>Encryption disabled</info>');
		}
		return 0;
	}
}
