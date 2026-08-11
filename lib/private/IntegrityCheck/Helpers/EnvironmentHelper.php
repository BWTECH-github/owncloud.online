<?php
/**
 * @author Lukas Reschke <lukas@statuscode.ch>
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

namespace OC\IntegrityCheck\Helpers;

/**
 * Class EnvironmentHelper provides a non-static helper for access to static
 * variables such as \OC::$SERVERROOT.
 *
 * @package OC\IntegrityCheck\Helpers
 */
class EnvironmentHelper {
	/**
	 * Provides \OC::$SERVERROOT
	 *
	 * @return string
	 */
	public function getServerRoot() {
		return \rtrim(\OC::$SERVERROOT, '/');
	}

	/**
	 * Provides \OC_Util::getChannel()
	 *
	 * @return string
	 */
	public function getChannel() {
		return \OC_Util::getChannel();
	}

	/**
	 * The channel of the deployed package, straight from version.php.
	 *
	 * OC_Util::getChannel() lets the database override this (core/OC_Channel).
	 * That override is a preference for update notifications and says nothing
	 * about whether THIS package carries signatures - an installation migrated
	 * from ownCloud brings the old value along and would otherwise have the
	 * signature check enforced against a package that never had any.
	 *
	 * @return string
	 */
	public function getShippedChannel() {
		$OC_Channel = '';
		require $this->getServerRoot() . '/version.php';
		return (string)$OC_Channel;
	}
}
