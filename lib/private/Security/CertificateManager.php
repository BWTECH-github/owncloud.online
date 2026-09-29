<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Martin Mattel <martin.mattel@diemattels.at>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
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

namespace OC\Security;

use OC\Files\Filesystem;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\ILogger;
use OCP\Util;

/**
 * Manage trusted certificates for users
 */
class CertificateManager implements ICertificateManager {
	/**
	 * @var string
	 */
	protected $uid;

	/**
	 * @var \OC\Files\View
	 */
	protected $view;

	/**
	 * @var IConfig
	 */
	protected $config;

	/**
	 * @var ILogger|null
	 */
	protected $logger;

	/**
	 * @param string $uid
	 * @param \OC\Files\View $view relative to data/
	 * @param IConfig $config
	 * @param ILogger|null $logger
	 */
	public function __construct($uid, \OC\Files\View $view, IConfig $config, ?ILogger $logger = null) {
		$this->uid = $uid;
		$this->view = $view;
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Returns all certificates trusted by the user
	 *
	 * @return \OCP\ICertificate[]
	 */
	public function listCertificates() {
		if (!$this->config->getSystemValue('installed', false)) {
			return [];
		}

		$path = $this->getPathToCertificates() . 'uploads/';
		if (!$this->view->is_dir($path)) {
			return [];
		}
		$result = [];
		$handle = $this->view->opendir($path);
		if (!\is_resource($handle)) {
			return [];
		}
		while (($file = \readdir($handle)) !== false) {
			if ($file != '.' && $file != '..') {
				try {
					$result[] = new Certificate($this->view->file_get_contents($path . $file), $file);
				} catch (\Exception $e) {
				}
			}
		}
		\closedir($handle);
		return $result;
	}

	/**
	 * create the certificate bundle of all trusted certificated
	 *
	 * @throws \RuntimeException if the bundle could not be written
	 */
	public function createCertificateBundle() {
		$path = $this->getPathToCertificates();
		$certs = $this->listCertificates();

		if (!$this->view->file_exists($path)) {
			$this->view->mkdir($path);
		}

		$bundle = '';

		// Write user certificates
		foreach ($certs as $cert) {
			$file = $path . 'uploads/' . $cert->getName();
			$data = $this->view->file_get_contents($file);
			if (\strpos($data, 'BEGIN CERTIFICATE')) {
				$bundle .= $data . "\r\n";
			}
		}

		// Append the default certificates
		$bundle .= \file_get_contents($this->getDefaultCertificatesBundlePath());

		// Append the system certificate bundle
		// Nur in Nutzerbündel: das Systembündel hängte sich sonst selbst an und
		// enthielt die mitgelieferten Zertifikate doppelt
		if ($this->uid !== null) {
			$systemBundle = $this->getCertificateBundle(null);
			if ($this->view->file_exists($systemBundle)) {
				$bundle .= $this->view->file_get_contents($systemBundle);
			}
		}

		$this->writeBundle($this->getCertificateBundle(), $bundle);
	}

	/**
	 * Schreibt das Bündel erst vollständig in eine Nachbardatei und benennt sie
	 * dann um: Scheitert das Schreiben (Nur-Lese-Speicher, voller Datenträger),
	 * bleibt das bisherige Bündel unverändert, und niemand liest ein halbes Bündel.
	 *
	 * @param string $target
	 * @param string $content
	 * @throws \RuntimeException
	 */
	private function writeBundle(string $target, string $content): void {
		$temporary = $target . '.' . \bin2hex(\random_bytes(8)) . '.part';
		$handle = $this->view->fopen($temporary, 'w');
		if (!\is_resource($handle)) {
			throw new \RuntimeException("Could not open $temporary to write the certificate bundle");
		}
		$written = \fwrite($handle, $content);
		$closed = \fclose($handle);
		if ($written !== \strlen($content) || !$closed || !$this->view->rename($temporary, $target)) {
			$this->view->unlink($temporary);
			throw new \RuntimeException("Could not write the certificate bundle $target");
		}
	}

	/**
	 * Save the certificate and re-generate the certificate bundle
	 *
	 * @param string $certificate the certificate data
	 * @param string $name the filename for the certificate
	 * @return \OCP\ICertificate
	 * @throws \Exception If the certificate could not get added
	 */
	public function addCertificate($certificate, $name) {
		if (!Filesystem::isValidPath($name) or Filesystem::isForbiddenFileOrDir($name)) {
			throw new \Exception('Filename is not valid');
		}

		$dir = $this->getPathToCertificates() . 'uploads/';
		if (!$this->view->file_exists($dir)) {
			$this->view->mkdir($dir);
		}

		try {
			$file = $dir . $name;
			$certificateObject = new Certificate($certificate, $name);
			$this->view->file_put_contents($file, $certificate);
			$this->createCertificateBundle();
			return $certificateObject;
		} catch (\Exception $e) {
			throw $e;
		}
	}

	/**
	 * Remove the certificate and re-generate the certificate bundle
	 *
	 * @param string $name
	 * @return bool
	 */
	public function removeCertificate($name) {
		if (!Filesystem::isValidPath($name)) {
			return false;
		}
		$path = $this->getPathToCertificates() . 'uploads/';
		if ($this->view->file_exists($path . $name)) {
			$this->view->unlink($path . $name);
			$this->createCertificateBundle();
		} else {
			return false;
		}
		return true;
	}

	/**
	 * Get the path to the certificate bundle for this user
	 *
	 * @param string $uid (optional) user to get the certificate bundle for, use `null` to get the system bundle
	 * @return string
	 */
	public function getCertificateBundle($uid = '') {
		if ($uid === '') {
			$uid = $this->uid;
		}
		return $this->getPathToCertificates($uid) . 'rootcerts.crt';
	}

	/**
	 * Get the full local path to the certificate bundle for this user
	 *
	 * @param string $uid (optional) user to get the certificate bundle for, use `null` to get the system bundle
	 * @return string
	 */
	public function getAbsoluteBundlePath($uid = '') {
		if ($uid === '') {
			$uid = $this->uid;
		}
		if ($this->needsRebundling($uid)) {
			try {
				if ($uid === null) {
					$manager = new CertificateManager(null, $this->view, $this->config, $this->logger);
					$manager->createCertificateBundle();
				} else {
					$this->createCertificateBundle();
				}
			} catch (\Exception $e) {
				return $this->getFallbackBundlePath($uid, $e);
			}
		}
		return $this->view->getLocalFile($this->getCertificateBundle($uid));
	}

	/**
	 * Bündel, wenn das eigentliche nicht neu gebaut werden konnte. Die TLS-Prüfung
	 * bleibt immer an: zuerst das vorhandene (evtl. veraltete) Bündel, das die
	 * hochgeladenen Zertifikate enthält, bei einem Nutzerbündel dann das
	 * Systembündel, zuletzt das mitgelieferte CA-Bündel.
	 *
	 * @param string|null $uid
	 * @param \Exception $reason
	 * @return string
	 */
	private function getFallbackBundlePath($uid, \Exception $reason) {
		$candidates = [$this->getCertificateBundle($uid)];
		if ($uid !== null) {
			$candidates[] = $this->getCertificateBundle(null);
		}

		$fallback = $this->getDefaultCertificatesBundlePath();
		foreach ($candidates as $candidate) {
			if (!$this->view->file_exists($candidate)) {
				continue;
			}
			$localFile = $this->view->getLocalFile($candidate);
			if (\is_string($localFile) && \is_file($localFile)) {
				$fallback = $localFile;
				break;
			}
		}

		$this->getLogger()->logException($reason, [
			'app' => 'core',
			'level' => Util::WARN,
			'message' => "Could not rebuild the certificate bundle, using $fallback instead",
		]);
		return $fallback;
	}

	/**
	 * @return string
	 */
	private function getDefaultCertificatesBundlePath() {
		return \OC::$SERVERROOT . '/resources/config/ca-bundle.crt';
	}

	/**
	 * @return ILogger
	 */
	private function getLogger() {
		return $this->logger ?? \OC::$server->getLogger();
	}

	/**
	 * @param string $uid (optional) user to get the certificate path for, use `null` to get the system path
	 * @return string
	 */
	private function getPathToCertificates($uid = '') {
		if ($uid === '') {
			$uid = $this->uid;
		}
		$path = $uid === null ? '/files_external/' : '/' . $uid . '/files_external/';

		return $path;
	}

	/**
	 * Check if we need to re-bundle the certificates because one of the sources has updated
	 *
	 * @param string $uid (optional) user to get the certificate path for, use `null` to get the system path
	 * @return bool
	 */
	private function needsRebundling($uid = '') {
		if ($uid === '') {
			$uid = $this->uid;
		}
		$sourceMTimes = [\filemtime($this->getDefaultCertificatesBundlePath())];
		$targetBundle = $this->getCertificateBundle($uid);
		if (!$this->view->file_exists($targetBundle)) {
			return true;
		}
		if ($uid !== null) { // also depend on the system bundle
			$sourceBundles[] = $this->view->filemtime($this->getCertificateBundle(null));
		}

		$sourceMTime = \array_reduce($sourceMTimes, function ($max, $mtime) {
			return \max($max, $mtime);
		}, 0);
		return $sourceMTime > $this->view->filemtime($targetBundle);
	}
}
