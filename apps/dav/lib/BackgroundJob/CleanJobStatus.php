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
 */

namespace OCA\DAV\BackgroundJob;

use OC\BackgroundJob\TimedJob;
use OCA\DAV\JobStatus\Entity\JobStatusMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Räumt den Auftragsstatus asynchroner MOVE-Anfragen (Kopf OC-LazyOps) auf.
 *
 * Gebraucht wird eine Zeile nur, solange jemand nach ihr fragt: Die
 * Oberfläche fragt bis zum Ende des Auftrags nach, und ein laufender
 * Zusammenbau schreibt alle zehn Sekunden seinen Fortschritt. Was einen Tag
 * lang unverändert blieb, fragt niemand mehr ab.
 */
class CleanJobStatus extends TimedJob {
	/** Sekunden ohne Änderung, nach denen ein Auftrag gelöscht wird */
	public const MAX_AGE = 86400;

	/** @var JobStatusMapper */
	private $mapper;
	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(JobStatusMapper $mapper, ITimeFactory $timeFactory) {
		$this->mapper = $mapper;
		$this->timeFactory = $timeFactory;
		// Stündlich: So liegen höchstens gut 25 Stunden in der Tabelle.
		$this->setInterval(3600);
	}

	protected function run($argument) {
		$this->mapper->deleteUnchangedSince($this->timeFactory->getTime() - self::MAX_AGE);
	}
}
