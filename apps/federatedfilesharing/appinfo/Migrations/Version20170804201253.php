<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-02-26.
 * Changes:
 *   - doctrine/dbal:3 (#41450)
 */

namespace OCA\FederatedFileSharing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/** Updates some fields to bigint if required */
class Version20170804201253 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];

		if ($schema->hasTable("{$prefix}federated_reshares")) {
			$table = $schema->getTable("{$prefix}federated_reshares");

			$shareIdColumn = $table->getColumn('share_id');
			if ($shareIdColumn && $shareIdColumn->getType()->getName() !== Types::BIGINT) {
				$shareIdColumn->setType(Type::getType(Types::BIGINT));
				$shareIdColumn->setOptions(['length' => 20]);
			}

			$remoteIdColumn = $table->getColumn('remote_id');
			if ($remoteIdColumn && $remoteIdColumn->getType()->getName() !== Types::BIGINT) {
				$remoteIdColumn->setType(Type::getType(Types::BIGINT));
				$remoteIdColumn->setOptions(['length' => 20]);
			}
		}
	}
}
