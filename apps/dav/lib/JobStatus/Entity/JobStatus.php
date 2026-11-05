<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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
 */

namespace OCA\DAV\JobStatus\Entity;

use OCP\AppFramework\Db\Entity;

/**
 * Class JobStatus
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getStatusInfo()
 * @method void setStatusInfo(string $statusInfo)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @package OCA\DAV\JobStatus\Entity
 */
class JobStatus extends Entity {
	protected $uuid;
	protected $userId;
	protected $statusInfo;
	/** @var int Unix-Zeit der letzten Änderung, danach räumt CleanJobStatus auf */
	protected $updatedAt;

	public function __construct() {
		$this->addType('updatedAt', 'integer');
	}
}
