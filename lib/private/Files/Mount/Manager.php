<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
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

namespace OC\Files\Mount;

use \OC\Files\Filesystem;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Mount\IMountPoint;

class Manager implements IMountManager {
	/**
	 * @var MountPoint[]
	 */
	private $mounts = [];

	/**
	 * @param IMountPoint $mount
	 */
	public function addMount(IMountPoint $mount) {
		$this->mounts[$mount->getMountPoint()] = $mount;
	}

	/**
	 * @param string $mountPoint
	 */
	public function removeMount($mountPoint) {
		$mountPoint = $this->formatPath($mountPoint);
		unset($this->mounts[$mountPoint]);
	}

	/**
	 * @param string $mountPoint
	 * @param string $target
	 */
	public function moveMount($mountPoint, $target) {
		// Normalize like addMount() does: keys are stored slash-terminated so the
		// hierarchy walk in find() can resolve them. Callers such as
		// MountProviderCollection::registerMount() pass a target built via
		// Filesystem::normalizePath(), which strips the trailing slash.
		$target = $this->formatPath($target);
		$this->mounts[$target] = $this->mounts[$mountPoint];
		unset($this->mounts[$mountPoint]);
	}

	/**
	 * Find the mount for $path
	 *
	 * @param string $path
	 * @return MountPoint | null
	 */
	public function find($path) {
		\OC_Util::setupFS();
		$path = $this->formatPath($path);
		if (isset($this->mounts[$path])) {
			return $this->mounts[$path];
		}

		\OC_Hook::emit('OC_Filesystem', 'get_mountpoint', ['path' => $path]);
		// The longest matching mount point wins. Mount points are stored with a
		// trailing slash, so prefix matches are always segment-aligned — instead of
		// scanning every mount (O(mounts) per lookup, and this runs for every path
		// touched in a request) walk the path hierarchy deepest-first with hash
		// lookups: O(path depth).
		$current = $path;
		while (true) {
			$pos = \strrpos(\rtrim($current, '/'), '/');
			if ($pos === false) {
				break;
			}
			$current = \substr($current, 0, $pos + 1);
			if (isset($this->mounts[$current])) {
				return $this->mounts[$current];
			}
			if ($current === '/') {
				break;
			}
		}
		return null;
	}

	/**
	 * Find all mounts in $path
	 *
	 * @param string $path
	 * @return MountPoint[]
	 */
	public function findIn($path) {
		\OC_Util::setupFS();
		$path = $this->formatPath($path);
		$result = [];
		$pathLength = \strlen($path);
		$mountPoints = \array_keys($this->mounts);
		foreach ($mountPoints as $mountPoint) {
			if (\substr($mountPoint, 0, $pathLength) === $path and \strlen($mountPoint) > $pathLength) {
				$result[] = $this->mounts[$mountPoint];
			}
		}
		return $result;
	}

	public function clear() {
		$this->mounts = [];
	}

	/**
	 * Find mounts by storage id
	 *
	 * @param string $id
	 * @return MountPoint[]
	 */
	public function findByStorageId($id) {
		\OC_Util::setupFS();
		if (\strlen($id) > 64) {
			$id = \md5($id);
		}
		$result = [];
		foreach ($this->mounts as $mount) {
			if ($mount->getStorageId() === $id) {
				$result[] = $mount;
			}
		}
		return $result;
	}

	/**
	 * @return MountPoint[]
	 */
	public function getAll() {
		return $this->mounts;
	}

	/**
	 * Find mounts by numeric storage id
	 *
	 * @param int $id
	 * @return MountPoint[]
	 */
	public function findByNumericId($id) {
		$storageId = \OC\Files\Cache\Storage::getStorageId($id);
		return $this->findByStorageId($storageId);
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private function formatPath($path) {
		$path = Filesystem::normalizePath($path);
		if (\strlen($path) > 1) {
			$path .= '/';
		}
		return $path;
	}
}
