<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-02-26.
 * Changes:
 *   - doctrine/dbal:3 (#41450)
 */
namespace OC\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/**
 * Updates column type in the share table from integer to bigint
 */
class Version20170711191432 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];

		if ($schema->hasTable("{$prefix}share")) {
			$table = $schema->getTable("{$prefix}share");

			$fileSourceColumn = $table->getColumn('file_source');
			if ($fileSourceColumn) {
				$fileSourceColumn->setType(Type::getType(Types::BIGINT));
				$fileSourceColumn->setOptions(['length' => 20]);
			}
		}
	}
}
