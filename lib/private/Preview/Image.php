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
		$image = new \OC_Image();
		$handle = $file->fopen('r');
		$image->load($handle);
		$image->fixOrientation();
		if (!$this->validateImageDimensions($image)) {
			// Bild ueber dem GD-Dimensionslimit (grosses Kamera-/Handyfoto, z. B.
			// 48 MP): GD hat es zwar dekodiert, verwirft es hier aber -> keine
			// Vorschau (graue Kachel). Ist imagick vorhanden, erzeugen wir die
			// Vorschau stattdessen speicherschonend (der jpeg:size-Hint laesst
			// libjpeg beim Dekodieren herunterskalieren). Nur dieser bisher leere
			// Fall aendert sich; normale Bilder behalten exakt den GD-Weg darunter.
			if (\is_resource($handle)) {
				\fclose($handle);
			}
			if (\extension_loaded('imagick')) {
				return $this->getThumbnailViaImagick($file, (int)$maxX, (int)$maxY);
			}
			return false;
		}

		if (\is_resource($handle)) {
			\fclose($handle);
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
	 * sodass auch 48-MP-Fotos eine Vorschau bekommen. Nur der Fall "GD verwirft das
	 * Bild wegen Ueberdimension" laeuft hier durch; scheitert imagick, gibt es wie
	 * bisher keine Vorschau (false).
	 *
	 * @return \OC_Image|false
	 */
	private function getThumbnailViaImagick(File $file, int $maxX, int $maxY) {
		if ($maxX < 1 || $maxY < 1) {
			return false;
		}
		$handle = $file->fopen('r');
		if (!\is_resource($handle)) {
			return false;
		}
		try {
			$content = \stream_get_contents($handle);
		} finally {
			if (\is_resource($handle)) {
				\fclose($handle);
			}
		}
		if ($content === false || $content === '') {
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
		[$width, $height] = $this->getMaxDimensions();
		return !($image->width() > $width || $image->height() > $height);
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
