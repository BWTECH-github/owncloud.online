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

namespace Test\Preview;

use OC\Preview\SVG;
use OC\Preview\TIFF;
use OCP\Files\File;
use Test\TestCase;

/**
 * Vorschau, wenn fopen() kein Handle liefert oder das Lesen wirft.
 *
 * View::fopen() liefert für gesperrte Pfade und Pfade ohne Speicher null statt
 * false. Beides muss zu „keine Vorschau“ führen statt zu einem TypeError (500).
 * Wirft das Lesen selbst (Verschlüsselung bei fehlendem Schlüssel), muss der
 * Handle trotzdem geschlossen werden, sonst bleibt der Shared-Lock hängen.
 */
class PreviewStreamTest extends TestCase {
	public function providesUnusableHandles(): array {
		return ['fopen liefert false' => [false], 'fopen liefert null' => [null]];
	}

	/**
	 * @param false|null $handle
	 */
	private function fileReturning($handle, string $path): File {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn($handle);
		$file->method('getPath')->willReturn($path);
		$file->method('getSize')->willReturn(1234);
		$file->method('getMimeType')->willReturn('image/tiff');
		return $file;
	}

	/**
	 * @dataProvider providesUnusableHandles
	 *
	 * @param false|null $handle
	 */
	public function testBitmapReturnsFalseWhenTheFileCannotBeOpened($handle): void {
		$this->assertFalse((new TIFF())->getThumbnail($this->fileReturning($handle, '/test/kaputt.tiff'), 32, 32, false));
	}

	/**
	 * @requires extension imagick
	 * @dataProvider providesUnusableHandles
	 *
	 * @param false|null $handle
	 */
	public function testSvgReturnsFalseWhenTheFileCannotBeOpened($handle): void {
		$this->assertFalse((new SVG())->getThumbnail($this->fileReturning($handle, '/test/kaputt.svg'), 32, 32, false));
	}

	/**
	 * @requires extension imagick
	 */
	public function testSvgReleasesTheStreamWhenTheReadThrows(): void {
		if (\in_array('previewstreamtest', \stream_get_wrappers(), true)) {
			\stream_wrapper_unregister('previewstreamtest');
		}
		$this->assertTrue(\stream_wrapper_register('previewstreamtest', ThrowingReadStreamWrapper::class));

		try {
			$stream = \fopen('previewstreamtest://wirft-beim-lesen', 'r');
			// Voraussetzung: ohne offenes Handle griffe schon die is_resource()-Prüfung
			$this->assertTrue(\is_resource($stream));

			$file = $this->createMock(File::class);
			$file->method('fopen')->willReturn($stream);
			$file->method('getPath')->willReturn('/test/unlesbar.svg');

			$result = (new SVG())->getThumbnail($file, 32, 32, false);
		} finally {
			\stream_wrapper_unregister('previewstreamtest');
		}

		$this->assertFalse($result);
		$this->assertFalse(\is_resource($stream), 'getThumbnail() muss das übergebene Handle schließen');
	}
}

/**
 * Wrapper, der beim Lesen wirft, wie das Verschlüsselungsmodul bei fehlendem Schlüssel.
 */
class ThrowingReadStreamWrapper {
	/** @var resource */
	public $context;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		return true;
	}

	public function stream_read(int $count): string {
		throw new \RuntimeException('simulierter Lesefehler');
	}

	public function stream_eof(): bool {
		return false;
	}

	public function stream_stat(): array {
		return [];
	}
}
