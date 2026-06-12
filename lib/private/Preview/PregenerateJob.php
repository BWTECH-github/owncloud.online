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

namespace OC\Preview;

use OC\BackgroundJob\QueuedJob;

/**
 * Erzeugt die Max-Preview einer Datei im Hintergrund, damit der erste
 * Thumbnail-Request der Web-UI keinen PHP-Worker mit der teuren
 * Bild-Dekodierung blockiert. Eingereiht von \OC\Preview::post_write.
 */
class PregenerateJob extends QueuedJob {
	/**
	 * @param array $argument ['uid' => string, 'path' => string] Pfad relativ zu files/
	 */
	public function run($argument) {
		$uid = $argument['uid'] ?? '';
		$path = $argument['path'] ?? '';
		if ($uid === '' || $path === '') {
			return;
		}

		try {
			\OC_Util::tearDownFS();
			\OC_Util::setupFS($uid);

			$userFolder = \OC::$server->getUserFolder($uid);
			if ($userFolder === null || !$userFolder->nodeExists($path)) {
				return;
			}

			// der Konstruktor erwartet einen Node, keinen Pfad-String
			$node = $userFolder->get($path);
			$preview = new \OC\Preview($uid, 'files', $node);
			$preview->setMaxX(256);
			$preview->setMaxY(256);
			$preview->setScalingUp(false);
			// erzeugt und cached die Max-Preview plus die angeforderte Größe;
			// exakt derselbe Pfad, den sonst der erste UI-Request synchron läuft
			$preview->getPreview();
		} catch (\Throwable $e) {
			// Pregeneration ist Best-Effort: Fehler nur leise protokollieren,
			// der On-Demand-Pfad bleibt unverändert bestehen
			\OC::$server->getLogger()->debug(
				'Preview pregeneration failed for {path}: {message}',
				['app' => 'core', 'path' => $path, 'message' => $e->getMessage()]
			);
		} finally {
			\OC_Util::tearDownFS();
		}
	}
}
