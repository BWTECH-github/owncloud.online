<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
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
 */

namespace OC\Preview;

//.ai
class Illustrator extends Bitmap {
	/**
	 * {@inheritDoc}
	 */
	public function getMimeType() {
		return '/application\/illustrator/';
	}

	protected function getImagickFormat(string $mimeType, string $content): string {
		// Illustrator-Dateien sind PDF (ab Version 9) oder PostScript (bis Version 8).
		// Der Decoder richtet sich nach dem Inhalt, nicht mehr fest nach "AI": AI ist
		// in der üblichen policy.xml nicht gesperrt und würde eine Sperre für PDF und
		// PS umgehen. PostScript-Dateien werden so auch wieder auf die Zeichnung
		// zugeschnitten statt auf eine feste Seite gesetzt.
		$kind = self::detectGhostscriptContent($content);
		if ($kind === 'PDF' || $kind === 'PS') {
			return $kind;
		}
		throw new \RuntimeException('Refusing to decode content that is neither PDF nor PostScript for an Illustrator preview');
	}
}
