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
 */

namespace OCA\DAV\Connector\Sabre\Exception;

use Sabre\DAV\Exception\BadRequest;

/**
 * Ungültiger Name eines Legacy-Chunks (kein Chunk-Muster, chunkcount über der
 * Obergrenze, index nicht kleiner als chunkcount). Ein Fehler des Aufrufers, der
 * deshalb nur als Debug protokolliert wird – sonst ließe sich das Protokoll ohne
 * Anmeldung mit FATAL-Einträgen füllen.
 */
class InvalidChunkName extends BadRequest {
	public function __construct() {
		parent::__construct('Invalid chunk name');
	}
}
