<?php
/**
 * @author Lukas Reschke <lukas@statuscode.ch>
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
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-08.
 * Changes:
 *   - resolve CI failures from upload hardening
 *   - perf+fix(upload/ui): stabilise uploads, harden chunk assembly, UI + brand...
 */
namespace OCA\DAV\Upload;

use OCA\DAV\Connector\Sabre\Directory;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\ICollection;

class UploadFolder implements ICollection {
	private $node;

	public function __construct(Directory $node) {
		$this->node = $node;
	}

	public function createFile($name, $data = null) {
		// need to bypass hooks for individual chunks
		$this->node->createFileDirectly($name, $data);

		// Geschriebene Bytes gegen Content-Length verifizieren (analog File::put):
		// ein abgerissener Request-Body darf nicht als vollständiger Chunk
		// gespeichert bleiben — ohne OC-Total-Length-Header würde der Fehler
		// sonst nicht einmal beim finalen MOVE auffallen
		if (isset($_SERVER['CONTENT_LENGTH'], $_SERVER['REQUEST_METHOD'])
			&& $_SERVER['REQUEST_METHOD'] === 'PUT'
		) {
			$expected = $_SERVER['CONTENT_LENGTH'];
			// wirft NotFound, falls der Chunk gar nicht geschrieben wurde
			$chunk = $this->node->getChild($name);
			// getSize() ist auf \Sabre\DAV\INode nicht deklariert — nur auf dem
			// konkreten OCA-Node; sonst überspringen wir die Größenprüfung.
			if ($chunk instanceof \OCA\DAV\Connector\Sabre\Node) {
				$written = $chunk->getSize();
				if ($written !== null && $written != $expected) {
					$chunk->delete();
					throw new BadRequest('expected filesize ' . $expected . ' got ' . $written);
				}
			}
		}
	}

	public function createDirectory($name) {
		throw new Forbidden('Permission denied to create file (filename ' . $name . ')');
	}

	public function getChild($name) {
		if ($name === FutureFile::getFutureFileName()) {
			return new FutureFile($this->node, FutureFile::getFutureFileName());
		}
		return $this->node->getChild($name);
	}

	public function getChildren() {
		$children = $this->node->getChildren();
		$children[] = new FutureFile($this->node, FutureFile::getFutureFileName());
		return $children;
	}

	public function childExists($name) {
		if ($name === FutureFile::getFutureFileName()) {
			return true;
		}
		return $this->node->childExists($name);
	}

	public function delete() {
		$this->node->delete();
	}

	public function getName() {
		return $this->node->getName();
	}

	public function setName($name) {
		throw new Forbidden('Permission denied to rename this folder');
	}

	public function getLastModified() {
		return $this->node->getLastModified();
	}
}
