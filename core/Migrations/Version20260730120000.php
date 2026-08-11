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
 * Modified by BW-Tech GmbH on 2026-07-30.
 * Changes:
 *   - detect password spraying across a whole IP subnet, not just one IP
 */

namespace OC\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/**
 * Adds ip_bucket to oc_bruteforce_attempts: a /24 (IPv4) or /64 (IPv6)
 * subnet key, computed in PHP via inet_pton()-based masking (see
 * OCO\Security\Bruteforce\Throttler::computeIpBucket()), so password
 * spraying rotated across multiple IPs of the same subnet is detected the
 * same way spraying from one exact IP already is.
 *
 * Nullable and not backfilled: rows predating this migration age out of the
 * 12h lookback window (and are pruned by CleanupJob) on their own, and a
 * NULL bucket never matches a WHERE ip_bucket = ... lookup, so it correctly
 * contributes nothing to the new dimension in the meantime.
 */
class Version20260730120000 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];

		if ($schema->hasTable("{$prefix}bruteforce_attempts")) {
			$table = $schema->getTable("{$prefix}bruteforce_attempts");

			if (!$table->hasColumn('ip_bucket')) {
				$table->addColumn(
					'ip_bucket',
					Types::STRING,
					[
						'length' => 48,
						'notnull' => false,
					]
				);
			}

			if (!$table->hasIndex('bruteforce_bucket_index')) {
				$table->addIndex(['action', 'ip_bucket', 'occurred'], 'bruteforce_bucket_index');
			}
		}
	}
}
