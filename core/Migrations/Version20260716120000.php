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
 * Modified by BW-Tech GmbH on 2026-07-16.
 * Changes:
 *   - Bruteforce- und Rate-Limiting-Schutz hinzufügen
 */

namespace OC\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/**
 * Backing table for OCO\Security\Bruteforce\Throttler: records failed
 * authentication attempts (login, WebDAV/OCS basic auth, oco_mcp,
 * public-share-link passwords) keyed by remote IP + targeted identifier,
 * used to compute an increasing sleep delay for repeated failures.
 */
class Version20260716120000 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];

		if (!$schema->hasTable("{$prefix}bruteforce_attempts")) {
			$table = $schema->createTable("{$prefix}bruteforce_attempts");

			$table->addColumn(
				'id',
				Types::BIGINT,
				[
					'autoincrement' => true,
					'unsigned' => true,
					'notnull' => true
				]
			);

			$table->addColumn(
				'action',
				Types::STRING,
				[
					'length' => 64,
					'notnull' => true
				]
			);

			$table->addColumn(
				'occurred',
				Types::BIGINT,
				[
					'unsigned' => true,
					'notnull' => true
				]
			);

			$table->addColumn(
				'ip',
				Types::STRING,
				[
					'length' => 64,
					'notnull' => true
				]
			);

			$table->addColumn(
				'identifier',
				Types::STRING,
				[
					'length' => 255,
					'notnull' => true
				]
			);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['action', 'ip', 'identifier', 'occurred'], 'bruteforce_lookup_index');
			$table->addIndex(['occurred'], 'bruteforce_occurred_index');
		}
	}
}
