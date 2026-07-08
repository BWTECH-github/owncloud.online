<?php
/**
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Felix Moeller <mail@felixmoeller.de>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Thomas Tanghus <thomas@tanghus.net>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * Modified by BW-Tech GmbH
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

class OC_FileChunking {
	protected $info;
	protected $cache;

	/**
	 * TTL of chunks
	 *
	 * @var int
	 */
	protected $ttl;

	public static function isWebdavChunk() {
		if (isset($_SERVER['HTTP_OC_CHUNKED'])) {
			return true;
		}
		return false;
	}

	public static function decodeName($name) {
		\preg_match('/(?P<name>.*)-chunking-(?P<transferid>\d+)-(?P<chunkcount>\d+)-(?P<index>\d+)/', $name, $matches);
		return $matches;
	}

	/**
	 * @param string[] $info
	 */
	public function __construct($info) {
		$this->info = $info;
		$this->ttl = \OC::$server->getConfig()->getSystemValue('cache_chunk_gc_ttl', 86400);
	}

	public function getPrefix() {
		$name = $this->info['name'];
		$transferid = $this->info['transferid'];

		return $name.'-chunking-'.$transferid.'-';
	}

	protected function getCache() {
		if (!isset($this->cache)) {
			$this->cache = new \OC\Cache\File();
		}
		return $this->cache;
	}

	/**
	 * Stores the given $data under the given $key - the number of stored bytes is returned
	 *
	 * @param string $index
	 * @param resource $data
	 * @return int
	 */
	public function store($index, $data) {
		$cache = $this->getCache();
		$name = $this->getPrefix().$index;
		// bei Retries desselben Chunks die alte Größe aus der Zwischensumme herausrechnen
		$oldSize = $cache->size($name);
		$cache->set($name, $data, $this->ttl);
		$size = $cache->size($name);
		$this->updateCachedTotalSize($size - $oldSize);

		return $size;
	}

	/**
	 * Cache-Key für die kumulierte Größe aller gespeicherten Chunks.
	 * 'size' ist nie numerisch und kollidiert daher nicht mit Chunk-Indizes.
	 *
	 * @return string
	 */
	protected function getSizeKey() {
		return $this->getPrefix().'size';
	}

	/**
	 * Passt die gecachte Größen-Zwischensumme an — nur wenn sie bereits
	 * existiert, sonst rechnet getCurrentSize() beim nächsten Aufruf neu.
	 * Fehler (z.B. Lock-Kollisionen paralleler Chunk-Uploads) invalidieren
	 * die Zwischensumme statt sie falsch werden zu lassen.
	 *
	 * @param int|float $delta
	 */
	protected function updateCachedTotalSize($delta) {
		$cache = $this->getCache();
		$sizeKey = $this->getSizeKey();
		try {
			$total = $cache->get($sizeKey);
			if (\is_numeric($total)) {
				if (!$cache->set($sizeKey, (string)\max(0, (0 + $total) + $delta), $this->ttl)) {
					$cache->remove($sizeKey);
				}
			}
		} catch (\Exception $e) {
			try {
				$cache->remove($sizeKey);
			} catch (\Exception $e2) {
				// ignorieren — Zwischensumme wird beim nächsten getCurrentSize neu aufgebaut
			}
		}
	}

	/**
	 * Größe eines einzelnen, bereits gespeicherten Chunks (0 wenn nicht vorhanden)
	 *
	 * @param string $index
	 * @return int
	 */
	public function getChunkSize($index) {
		return $this->getCache()->size($this->getPrefix().$index);
	}

	public function isComplete() {
		$prefix = $this->getPrefix();
		$cache = $this->getCache();
		$chunkcount = (int)$this->info['chunkcount'];

		for ($i=($chunkcount-1); $i >= 0; $i--) {
			if (!$cache->hasKey($prefix.$i)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Assembles the chunks into the file specified by the path.
	 * Chunks are deleted afterwards.
	 *
	 * @param resource $f target path
	 *
	 * @return integer assembled file size
	 *
	 * @throws \OC\ForbiddenException when a chunk is missing or could not be
	 * written completely (e.g. lack of free space or permissions)
	 */
	public function assemble($f) {
		$cache = $this->getCache();
		$prefix = $this->getPrefix();
		// Zwischensumme invalidieren — die Chunks werden hier konsumiert
		$cache->remove($this->getSizeKey());
		$count = 0;
		for ($i = 0; $i < $this->info['chunkcount']; $i++) {
			$chunk = $cache->get($prefix.$i);
			if ($chunk === null || $chunk === false) {
				// Chunk fehlt (GC/TTL) oder ist nicht lesbar — ein fwrite(null)
				// würde die Datei stillschweigend verkürzen
				throw new \OC\ForbiddenException(
					'Chunk ' . $i . ' of chunked upload "' . $this->info['name'] . '" is missing, cannot assemble'
				);
			}
			$written = \fwrite($f, $chunk);
			if ($written !== \strlen($chunk)) {
				// false oder Short-Write (Platte voll/Quota/IO-Fehler): abbrechen,
				// die verbliebenen Chunks bleiben für einen Retry im Cache
				throw new \OC\ForbiddenException(
					'Could not write chunk ' . $i . ' of chunked upload "' . $this->info['name']
					. '" (wrote ' . (int)$written . ' of ' . \strlen($chunk) . ' bytes)'
				);
			}
			// Chunk erst nach erfolgreichem Schreiben entfernen
			$cache->remove($prefix.$i);
			$count += $written;
			// let php release the memory to work around memory exhausted error with php 5.6
			$chunk = null;
		}

		return $count;
	}

	/**
	 * Returns the size of the chunks already present
	 * @return integer size in bytes
	 */
	public function getCurrentSize() {
		$cache = $this->getCache();
		// Zwischensumme bevorzugen: der Stat-Loop über alle Chunk-Indizes kostet
		// 2-3 Storage-Operationen pro Index und wird bei jedem Chunk-PUT vom
		// QuotaPlugin aufgerufen — ohne Cache also O(chunkcount²) pro Upload
		$cachedTotal = $cache->get($this->getSizeKey());
		if (\is_numeric($cachedTotal)) {
			return 0 + $cachedTotal;
		}
		$prefix = $this->getPrefix();
		$total = 0;
		for ($i = 0; $i < $this->info['chunkcount']; $i++) {
			$total += $cache->size($prefix.$i);
		}
		try {
			$cache->set($this->getSizeKey(), (string)$total, $this->ttl);
		} catch (\Exception $e) {
			// ignorieren — dann wird beim nächsten Aufruf erneut gezählt
		}
		return $total;
	}

	/**
	 * Removes all chunks which belong to this transmission
	 */
	public function cleanup() {
		$cache = $this->getCache();
		$prefix = $this->getPrefix();
		$cache->remove($this->getSizeKey());
		for ($i=0; $i < $this->info['chunkcount']; $i++) {
			$cache->remove($prefix.$i);
		}
	}

	/**
	 * Removes one specific chunk
	 * @param string $index
	 */
	public function remove($index) {
		$cache = $this->getCache();
		$prefix = $this->getPrefix();
		$this->updateCachedTotalSize(-$cache->size($prefix.$index));
		$cache->remove($prefix.$index);
	}

	/**
	 * Assembles the chunks into the file specified by the path.
	 * Also triggers the relevant hooks and proxies.
	 *
	 * @param \OC\Files\Storage\Storage $storage storage
	 * @param string $path target path relative to the storage
	 * @return bool true on success or false if file could not be created
	 *
	 * @throws \OC\ServerNotAvailableException
	 */
	public function file_assemble($storage, $path) {
		// use file_put_contents as method because that best matches what this function does
		if (\OC\Files\Filesystem::isValidPath($path)) {
			$target = $storage->fopen($path, 'w');
			if ($target) {
				try {
					$this->assemble($target);
				} finally {
					\fclose($target);
				}
				// assemble() wirft bei jedem Fehler (fehlender Chunk, Short-Write) —
				// hier angekommen ist die Datei vollständig geschrieben
				return true;
			} else {
				return false;
			}
		}
		return false;
	}
}
