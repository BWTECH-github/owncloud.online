<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-02-26.
 * Changes:
 *   - doctrine/dbal:3 (#41450)
 */
namespace OCA\DAV\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/**
 * Updates column type from integer to bigint
 */
class Version20170711193427 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];

		if ($schema->hasTable("{$prefix}properties")) {
			$table = $schema->getTable("{$prefix}properties");

			$idColumn = $table->getColumn('id');
			if ($idColumn) {
				$idColumn->setType(Type::getType(Types::BIGINT));
				$idColumn->setOptions(['length' => 20]);
			}

			$fileidColumn = $table->getColumn('fileid');
			if ($fileidColumn) {
				$fileidColumn->setType(Type::getType(Types::BIGINT));
				$fileidColumn->setOptions(['length' => 20]);
			}
		}
	}
}
