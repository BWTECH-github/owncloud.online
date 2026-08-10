<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-02.
 * Changes:
 *   - index oc_share on uid_owner/uid_initiator (11.0.8.1)
 */

namespace OC\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OCP\Migration\ISchemaMigration;

/**
 * Indizes für DefaultShareProvider::getSharesBy() ohne Node:
 * WHERE share_type=? AND (uid_owner=? OR uid_initiator=?) konnte bisher nur den
 * item_share_type_index nutzen und musste alle Shares des Typs row-filtern —
 * OCS GET /shares und der "Mit anderen geteilt"-Tab skalierten mit der
 * Gesamt-Share-Zahl statt mit den Shares des Nutzers.
 */
class Version20260702090000 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];
		if ($schema->hasTable("{$prefix}share")) {
			$table = $schema->getTable("{$prefix}share");
			if (!$table->hasIndex('owner_index')) {
				$table->addIndex(['uid_owner'], 'owner_index');
			}
			if (!$table->hasIndex('initiator_index')) {
				$table->addIndex(['uid_initiator'], 'initiator_index');
			}
		}
	}
}
