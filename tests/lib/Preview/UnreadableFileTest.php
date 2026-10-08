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

use OC\Preview\MarkDown;
use OC\Preview\Movie;
use OC\Preview\MP3;
use OC\Preview\MSOfficeDoc;
use OC\Preview\SVG;
use OC\Preview\TXT;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Preview\IProvider2;
use Test\TestCase;

/**
 * Vorschau für eine Datei, die sich nicht öffnen lässt.
 *
 * Wird eine Datei gelöscht, während ihre Vorschau noch angefordert wird (Papierkorb,
 * „Endgültig löschen“), liefert File::fopen() false. Die Provider müssen dann ohne
 * Vorschau zurückkehren; vorher warf stream_get_contents()/fclose() einen TypeError,
 * der als \Error an den catch(\Exception)-Blöcken vorbei ins Protokoll lief.
 */
class UnreadableFileTest extends TestCase {
	/**
	 * @param bool $encrypted true erzwingt bei MP3/Movie/Office den Weg über fopen()
	 *                        statt über getLocalFile()
	 */
	private function unreadableFile(bool $encrypted): File {
		$file = $this->createMock(File::class);
		$file->expects($this->once())->method('fopen')->willReturn(false);
		$file->method('isEncrypted')->willReturn($encrypted);
		$file->method('isMounted')->willReturn(false);
		$file->method('getSize')->willReturn(1234);
		$file->method('getPath')->willReturn('/user/files_trashbin/files/weg.txt.d1');
		return $file;
	}

	public function providesStreamProviders(): array {
		return [
			'TXT' => [new TXT()],
			'MarkDown' => [new MarkDown()],
		];
	}

	/**
	 * @dataProvider providesStreamProviders
	 */
	public function testTextProviderReturnsFalse(IProvider2 $provider): void {
		$this->assertFalse($provider->getThumbnail($this->unreadableFile(false), 64, 64, false));
	}

	public function testSvgReturnsFalse(): void {
		if (!\extension_loaded('imagick')) {
			$this->markTestSkipped('imagick fehlt');
		}
		$this->assertFalse((new SVG())->getThumbnail($this->unreadableFile(false), 64, 64, false));
	}

	public function testMp3ReturnsFalse(): void {
		$this->assertFalse((new MP3())->getThumbnail($this->unreadableFile(true), 64, 64, false));
	}

	public function testMovieReturnsFalse(): void {
		$this->assertFalse((new Movie())->getThumbnail($this->unreadableFile(true), 64, 64, false));
	}

	public function testOfficeReturnsFalse(): void {
		// Office bricht ohne LibreOffice vor dem Öffnen ab; ein gesetzter Pfad
		// sorgt dafür, dass wirklich der fopen()-Zweig geprüft wird
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static function ($key, $default = '') {
				return $key === 'preview_libreoffice_path' ? '/bin/true' : $default;
			}
		);
		$this->overwriteService('AllConfig', $config);
		try {
			$this->assertFalse((new MSOfficeDoc())->getThumbnail($this->unreadableFile(true), 64, 64, false));
		} finally {
			$this->restoreService('AllConfig');
		}
	}
}
