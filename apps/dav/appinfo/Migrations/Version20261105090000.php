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
 */

namespace OCA\DAV\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/**
 * Der Auftragsstatus asynchroner MOVE-Anfragen bekommt den Zeitpunkt seiner
 * letzten Änderung. Ohne ihn ließ sich nicht sagen, welche Zeilen niemand
 * mehr abfragt, und die Tabelle wuchs mit jedem Auftrag. Vorhandene Zeilen
 * stehen auf 0 und gehen beim ersten Lauf von CleanJobStatus.
 */
class Version20261105090000 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];
		if (!$schema->hasTable("{$prefix}dav_job_status")) {
			return;
		}
		$table = $schema->getTable("{$prefix}dav_job_status");
		if (!$table->hasColumn('updated_at')) {
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'unsigned' => true,
			]);
		}
		if (!$table->hasIndex('dav_job_status_updated')) {
			$table->addIndex(['updated_at'], 'dav_job_status_updated');
		}
	}
}
