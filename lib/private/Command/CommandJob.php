<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
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
 * Modified by BW-Tech GmbH on 2026-08-10.
 * Changes:
 *   - restrict allowed_classes when a queued command is deserialized
 */

namespace OC\Command;

use OC\BackgroundJob\QueuedJob;
use OCP\Command\ICommand;

/**
 * Wrap a command in the background job interface
 */
class CommandJob extends QueuedJob {
	protected function run($serializedCommand) {
		// Erster Durchgang ohne Objekte: so laeuft auf unvertrauten Daten kein
		// __wakeup() und kein __destruct(), und der gespeicherte Klassenname
		// laesst sich trotzdem auslesen.
		$incomplete = \unserialize($serializedCommand, ['allowed_classes' => false]);
		if (!($incomplete instanceof \__PHP_Incomplete_Class)) {
			throw new \InvalidArgumentException('Invalid serialized command: expected a serialized object');
		}
		$className = ((array)$incomplete)['__PHP_Incomplete_Class_Name'] ?? null;
		if (!\is_string($className) || $className === '') {
			throw new \InvalidArgumentException('Invalid serialized command: could not determine class name');
		}

		// Nur eine geladene Klasse, die ICommand wirklich implementiert, darf
		// ueberhaupt instanziiert werden - damit sind Gadget-Ketten aus
		// beliebigen anderen Klassen ausgeschlossen.
		if (!\class_exists($className) || !\is_a($className, ICommand::class, true)) {
			throw new \InvalidArgumentException(
				'Invalid serialized command: class "' . $className . '" does not implement ICommand'
			);
		}

		// Zweiter Durchgang, jetzt gefahrlos: nur die gepruefte Klasse ist erlaubt.
		$command = \unserialize($serializedCommand, ['allowed_classes' => [$className]]);
		if ($command instanceof ICommand) {
			$command->handle();
		} else {
			throw new \InvalidArgumentException('Invalid serialized command');
		}
	}
}
