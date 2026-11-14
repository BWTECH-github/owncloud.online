<?php
/**
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
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
namespace OCA\DAV\Upload;

use OC\Files\Filesystem;
use OC\Files\View;
use OCA\DAV\Connector\Sabre\Directory;
use OCP\Lock\LockedException;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\ICollection;

class UploadHome implements ICollection {
	/** Wiederholungen, solange der Upload-Ordner beim ersten Zugriff noch nicht lesbar ist */
	private const FIRST_USE_RETRIES = 10;
	/** Wartezeit je Wiederholung: 100 ms, zusammen höchstens eine Sekunde */
	private const FIRST_USE_WAIT_MICROSECONDS = 100000;

	private $principalInfo;

	/**
	 * UploadHome constructor.
	 *
	 * @param array $principalInfo
	 */
	public function __construct($principalInfo) {
		$this->principalInfo = $principalInfo;
	}

	public function createFile($name, $data = null) {
		throw new Forbidden('Permission denied to create file (filename ' . $name . ')');
	}

	public function createDirectory($name) {
		$this->impl()->createDirectory($name);
	}

	public function getChild($name) {
		return new UploadFolder($this->impl()->getChild($name));
	}

	public function getChildren() {
		return \array_map(function ($node) {
			return new UploadFolder($node);
		}, $this->impl()->getChildren());
	}

	public function childExists($name) {
		return $this->getChild($name) !== null;
	}

	public function delete() {
		$this->impl()->delete();
	}

	public function getName() {
		return 'uploads';
	}

	public function setName($name) {
		throw new Forbidden('Permission denied to rename this folder');
	}

	public function getLastModified() {
		return $this->impl()->getLastModified();
	}

	/**
	 * @return Directory
	 */
	private function impl() {
		$rootView = new View();
		if (isset($this->principalInfo['user'])) {
			$user = $this->principalInfo['user'];
		} else {
			$user = \OC::$server->getUserSession()->getUser();
		}
		Filesystem::initMountPoints($user->getUID());
		$uploadsPath = '/' . $user->getUID() . '/uploads';
		if (!$rootView->file_exists($uploadsPath)) {
			try {
				$rootView->mkdir($uploadsPath);
			} catch (LockedException $e) {
				// Vorsorglich: View selbst sperrt diesen Pfad nicht (View::shouldLockFile()),
				// ein Speicher darf die Ausnahme aber werfen. Unten wird dann nur noch gelesen.
			}
		}
		$view = new View($uploadsPath);
		$rootInfo = $view->getFileInfo('');
		// Beim ersten Zugriff eines Kontos liest eine Anfrage den Ordner in den Datei-Cache ein.
		// Eine parallele Anfrage scheitert an der Sperre dieses Scanners; View::getCacheEntry()
		// schluckt die LockedException und liefert false, obwohl der Ordner da ist. Der Scan
		// dauert nur Millisekunden, deshalb kurz warten. Fehlt der Ordner wirklich (Anlegen
		// gescheitert), hilft Warten nicht.
		for ($attempt = 0; $rootInfo === false && $attempt < self::FIRST_USE_RETRIES && $rootView->file_exists($uploadsPath); $attempt++) {
			\usleep(self::FIRST_USE_WAIT_MICROSECONDS);
			$rootInfo = $view->getFileInfo('');
		}
		if ($rootInfo === false) {
			// Der Wortlaut ist Absicht (wie in ObjectTree): Bei Stück-Uploads und beim
			// Zusammenbau werten Desktop-Clients nur diesen 503 als vorübergehenden Fehler
			// der einen Datei, jeden anderen ServiceUnavailable als Wartungsmodus.
			throw new ServiceUnavailable('Storage is temporarily not available');
		}
		return new Directory($view, $rootInfo);
	}
}
