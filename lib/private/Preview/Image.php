<?php
/**
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author josh4trunks <joshruehlig@gmail.com>
 * @author Olivier Paroz <github@oparoz.com>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Thomas Tanghus <thomas@tanghus.net>
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

use OC\Preview;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Preview\IProvider2;

abstract class Image implements IProvider2 {
	/**
	 * {@inheritDoc}
	 */
	public function getThumbnail(File $file, $maxX, $maxY, $scalingUp) {
		if (Preview::isImageFileSizeTooBig($file)) {
			return false;
		}
		// Datei genau einmal lesen; frueher las der Imagick-Fallback sie ein
		// zweites Mal komplett ein.
		$handle = $file->fopen('r');
		if (!\is_resource($handle)) {
			return false;
		}
		try {
			$content = \stream_get_contents($handle);
		} finally {
			\fclose($handle);
		}
		if ($content === false || $content === '') {
			return false;
		}

		// Dimensionen vorab per Header-Parse pruefen: ein Bild ueber dem
		// GD-Dimensionslimit (grosses Kamera-/Handyfoto, z. B. 48 MP) geht direkt
		// in den speicherschonenden imagick-Pfad (jpeg:size-Hint laesst libjpeg
		// beim Dekodieren herunterskalieren), ohne dass GD es erst vollstaendig
		// dekodiert und das Ergebnis wieder verwirft.
		$rawSize = \getimagesizefromstring($content);
		if (\is_array($rawSize) && !$this->validateRawDimensions((int)$rawSize[0], (int)$rawSize[1])) {
			// Pixel-Flood-Schutz (Dekompressionsbombe): der imagick-Fallback ist
			// fuer echte Kamerafotos gedacht. Alles jenseits der 4-fachen
			// konfigurierten Flaeche (~145 MP bei Default 6016x6016; reale
			// Mittelformat-Kameras liegen bei 100-150 MP, Bomben im
			// Gigapixel-Bereich) wird ohne Decoder-Versuch abgelehnt — dasselbe
			// Ergebnis, das frueher der fehlschlagende GD-Voll-Decode lieferte.
			if (!$this->withinImagickBounds((int)$rawSize[0], (int)$rawSize[1])) {
				return false;
			}
			if (\extension_loaded('imagick')) {
				return $this->getThumbnailViaImagick($content, (int)$maxX, (int)$maxY);
			}
			return false;
		}

		$image = new \OC_Image();
		// php://temp statt Datei-Handle: identischer Lade- und EXIF-Pfad wie
		// zuvor (loadFromFileHandle), nur ohne erneutes Einlesen der Datei.
		$tmp = \fopen('php://temp', 'r+b');
		if (!\is_resource($tmp)) {
			return false;
		}
		try {
			\fwrite($tmp, $content);
			\rewind($tmp);
			$image->load($tmp);
		} finally {
			\fclose($tmp);
		}
		$image->fixOrientation();
		if (!$this->validateImageDimensions($image)) {
			// Header-Parse konnte die Groesse nicht bestimmen, GD hat das Bild
			// trotzdem dekodiert und es liegt ueber dem Limit -> wie bisher als
			// letzte Chance imagick versuchen.
			if (\extension_loaded('imagick')) {
				return $this->getThumbnailViaImagick($content, (int)$maxX, (int)$maxY);
			}
			return false;
		}

		if ($image->valid()) {
			$image->scaleDownToFit($maxX, $maxY);

			return $image;
		}
		return false;
	}

	/**
	 * Erzeugt die Vorschau eines grossen Bildes speicherschonend via imagick.
	 * Fuer JPEG skaliert libjpeg dank jpeg:size bereits beim Dekodieren herunter,
	 * sodass auch 48-MP-Fotos eine Vorschau bekommen. Hier laeuft nur der Fall
	 * "Bild ueber dem GD-Dimensionslimit" durch; scheitert imagick, gibt es wie
	 * bisher keine Vorschau (false).
	 *
	 * @return \OC_Image|false
	 */
	private function getThumbnailViaImagick(string $content, int $maxX, int $maxY) {
		if ($maxX < 1 || $maxY < 1) {
			return false;
		}

		$imagick = null;
		try {
			$imagick = new \Imagick();
			// DCT-Downscale-Hint: libjpeg dekodiert JPEGs direkt in reduzierter
			// Groesse (1/2, 1/4, 1/8). Andere Formate ignorieren die Option.
			$imagick->setOption('jpeg:size', $maxX . 'x' . $maxY);
			$imagick->readImageBlob($content);
			$imagick->setIteratorIndex(0);
			// EXIF-Orientierung anwenden (autoOrientate liest das Orientation-Tag
			// und dreht/spiegelt entsprechend). method_exists deckt aeltere imagick
			// ohne diese Methode ab.
			if (\method_exists($imagick, 'autoOrientate')) {
				$imagick->autoOrientate();
			}
			$imagick->setImageFormat('png');
			$imagick->thumbnailImage($maxX, $maxY, true);
			$blob = $imagick->getImageBlob();
		} catch (\Throwable $e) {
			\OC::$server->getLogger()->logException(
				$e,
				['app' => 'core', 'message' => 'imagick large-image preview failed']
			);
			return false;
		} finally {
			if ($imagick instanceof \Imagick) {
				$imagick->clear();
				$imagick->destroy();
			}
		}

		$image = new \OC_Image();
		$image->loadFromData($blob);
		return $image->valid() ? $image : false;
	}

	/**
	 * @inheritdoc
	 */
	public function isAvailable(FileInfo $file) {
		return true;
	}

	private function validateImageDimensions(\OC_Image $image): bool {
		return $this->validateRawDimensions($image->width(), $image->height());
	}

	private function validateRawDimensions(int $imageWidth, int $imageHeight): bool {
		[$width, $height] = $this->getMaxDimensions();
		return !($imageWidth > $width || $imageHeight > $height);
	}

	private function withinImagickBounds(int $imageWidth, int $imageHeight): bool {
		[$width, $height] = $this->getMaxDimensions();
		return ($imageWidth * $imageHeight) <= (4 * $width * $height);
	}

	private function getMaxDimensions(): array {
		// 24 MP - 6016 x 6016
		$maxDimension = \OC::$server->getConfig()->getSystemValue('preview_max_dimensions', '6016x6016');
		$exploded = explode('x', strtolower($maxDimension));
		if ($exploded === false || \count($exploded) !== 2) {
			return [6016, 6016];
		}
		[$w, $h] = $exploded;
		if (is_numeric($w) && is_numeric($h)) {
			return [(int)$w, (int)$h];
		}

		return [6016, 6016];
	}
}
