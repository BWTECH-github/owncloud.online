<?php

namespace OC\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OCP\Migration\ISchemaMigration;

/**
 * Index für JobList::getNext(): WHERE reserved_at <= ? ORDER BY last_checked LIMIT 1
 * lief bisher als Full-Scan mit Sort über die komplette oc_jobs-Tabelle —
 * pro Cron-Iteration und damit mehrfach pro Cron-Lauf.
 */
class Version20260611120000 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];
		if ($schema->hasTable("{$prefix}jobs")) {
			$table = $schema->getTable("{$prefix}jobs");
			if (!$table->hasIndex('job_lastcheck_reserved')) {
				$table->addIndex(['last_checked', 'reserved_at'], 'job_lastcheck_reserved');
			}
		}
	}
}
