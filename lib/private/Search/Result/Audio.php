<?php
/**
 * @author Andrew Brown <andrew@casabrown.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Morris Jobke <hey@morrisjobke.de>
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
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-03-16.
 * Changes:
 *   - PHP 8.4 compatibility and owncloud.online design integration
 */

namespace OC\Search\Result;

/**
 * A found audio file
 */
class Audio extends File {
	/**
	 * Type name; translated in templates
	 * @var string
	 */
	public $type = 'audio';

	/**
	 * @TODO add ID3 information
	 */
}
