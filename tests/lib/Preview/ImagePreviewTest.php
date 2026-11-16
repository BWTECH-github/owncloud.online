<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2024, ownCloud GmbH
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

namespace Test\Preview;

use OC\Preview\JPEG;
use OC\Preview\PNG;
use OCP\Files\File;
use Test\TestCase;

class ImagePreviewTest extends TestCase {
	public function testImageTooBigToPreview(): void {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(100*1024*1024);
		$file->method('fopen')->willThrowException(new \RuntimeException('How did you get here?'));
		$p = new JPEG();
		$result = $p->getThumbnail($file, 64, 64, true);
		self::assertFalse($result);
	}
	/**
	 * Kaputte Bilddaten (hier Text in einer .png-Datei) sind kein Fehler des
	 * Servers: der Anbieter lehnt sie ab, ohne dass getimagesizefromstring()
	 * eine PHP-Meldung ins Protokoll schreibt („Error reading from …“).
	 */
	public function testBrokenImageDataIsRejectedWithoutPhpNotice(): void {
		$stream = \fopen('php://memory', 'r+b');
		\fwrite($stream, 'Test!');
		\rewind($stream);
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(5);
		$file->method('fopen')->willReturn($stream);
		$p = new PNG();

		$result = $p->getThumbnail($file, 32, 32, false);

		self::assertFalse($result);
	}

	/**
	 * Erzeugt ein Bild, das breiter als das GD-Limit (6016 px) ist, damit der
	 * imagick-Weg greift, und liefert es als Datei-Attrappe.
	 */
	private function wideImage(string $type): File {
		$gd = \imagecreatetruecolor(6100, 40);
		\ob_start();
		if ($type === 'jpeg') {
			\imagefill($gd, 0, 0, \imagecolorallocate($gd, 30, 120, 200));
			\imagejpeg($gd, null, 80);
		} else {
			// PNG mit echter Transparenz
			\imagealphablending($gd, false);
			\imagesavealpha($gd, true);
			\imagefill($gd, 0, 0, \imagecolorallocatealpha($gd, 30, 120, 200, 100));
			\imagepng($gd);
		}
		$data = \ob_get_clean();
		$stream = \fopen('php://memory', 'r+b');
		\fwrite($stream, $data);
		\rewind($stream);
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(\strlen($data));
		$file->method('fopen')->willReturn($stream);
		return $file;
	}

	/**
	 * Große Fotos laufen über imagick. Ihre Vorschau bleibt JPEG, wie die kleinerer
	 * Fotos über GD: PNG kostete je Foto mehrere Sekunden und viel Platz.
	 */
	public function testLargeJpegPreviewStaysJpeg(): void {
		if (!\extension_loaded('imagick')) {
			$this->markTestSkipped('imagick fehlt');
		}
		$result = (new JPEG())->getThumbnail($this->wideImage('jpeg'), 64, 64, true);

		self::assertInstanceOf(\OC_Image::class, $result);
		self::assertTrue($result->valid());
		self::assertSame('image/jpeg', $result->mimeType());
	}

	public function testLargeTransparentPngPreviewStaysPng(): void {
		if (!\extension_loaded('imagick')) {
			$this->markTestSkipped('imagick fehlt');
		}
		$result = (new PNG())->getThumbnail($this->wideImage('png'), 64, 64, true);

		self::assertInstanceOf(\OC_Image::class, $result);
		self::assertTrue($result->valid());
		self::assertSame('image/png', $result->mimeType());
	}

	public function testPixelFlood(): void {
		$filename = __DIR__ . '/../../data/pixel.jpg';
		$stream = fopen($filename, 'rb');
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(filesize($filename));
		$file->method('fopen')->willReturn($stream);
		$p = new JPEG();
		$result = $p->getThumbnail($file, 64, 64, true);
		self::assertFalse($result);
	}
}
