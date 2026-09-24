<?php
/**
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Morris Jobke <hey@morrisjobke.de>
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

namespace OC\Log;

/**
 * This rotates the current logfile to a new name, this way the total log usage
 * will stay limited and older entries are available for a while longer.
 * For more professional log management set the 'logfile' config to a different
 * location and manage that with your own tools.
 */
class Rotate extends \OC\BackgroundJob\Job {
	/**
	 * 100 MiB. Ohne einen Vorgabewert waechst owncloud.log unbegrenzt: auf
	 * Instanzen, die nie eine Rotation eingerichtet bekommen haben, sind so
	 * schon Logdateien jenseits von 40 GB entstanden und haben die Platte
	 * gefuellt. Wer die Dateien mit logrotate o.ae. selbst verwaltet, setzt
	 * 'log_rotate_size' ausdruecklich auf false und schaltet sie damit ab.
	 */
	public const DEFAULT_MAX_SIZE = 104857600;

	private $max_log_size;

	/**
	 * Die wirksame Obergrenze in Byte. 0 heisst: keine Rotation.
	 *
	 * Entscheidend ist, dass ein ausdrueckliches Abschalten weiterhin
	 * abschaltet - nur das Fehlen des Eintrags bekommt den Vorgabewert.
	 *
	 * @param mixed $configured Wert aus der config.php
	 */
	public static function maxSize($configured): int {
		// 'log_rotate_size' => true hiess frueher "rotiere bei jedem Lauf",
		// gemeint war aber immer "schalte die Rotation ein".
		if ($configured === true) {
			return self::DEFAULT_MAX_SIZE;
		}

		// Eine handgeschriebene config.php darf '100 MB' enthalten.
		if (\is_string($configured) && $configured !== '' && !\is_numeric($configured)) {
			$parsed = \OCP\Util::computerFileSize($configured);
			$configured = $parsed === false ? 0 : $parsed;
		}

		if (!\is_numeric($configured)) {
			return 0;
		}

		$size = (int)$configured;

		return $size > 0 ? $size : 0;
	}

	public function run($logFile) {
		$this->max_log_size = self::maxSize(
			\OC::$server->getConfig()->getSystemValue('log_rotate_size', self::DEFAULT_MAX_SIZE)
		);
		if ($this->max_log_size > 0) {
			$filesize = @\filesize($logFile);
			if ($filesize >= $this->max_log_size) {
				$this->rotate($logFile);
			}
		}
	}

	protected function rotate($logfile) {
		$rotatedLogfile = $logfile.'.1';
		\rename($logfile, $rotatedLogfile);
		$msg = 'Log file "'.$logfile.'" was over '.$this->max_log_size.' bytes, moved to "'.$rotatedLogfile.'"';
		\OCP\Util::writeLog('OC\Log\Rotate', $msg, \OCP\Util::WARN);
	}
}
