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

use OC\Preview\Bitmap;
use OC\Preview\Font;
use OC\Preview\Heic;
use OC\Preview\Illustrator;
use OC\Preview\PDF;
use OC\Preview\Photoshop;
use OC\Preview\Postscript;
use OC\Preview\SGI;
use OC\Preview\TIFF;
use OCP\Files\File;
use OCP\Util;
use Test\TestCase;

/**
 * Die von Ghostscript gelesenen Anbieter wählen den Decoder nach dem Inhalt.
 *
 * Ein fester Decoder je Anbieter hätte verschoben, welche ImageMagick-Policy gefragt
 * wird: PostScript hinter einer .pdf-Datei liefe unter der Policy PDF, jede .ai-Datei
 * unter der Policy AI, die in der üblichen policy.xml nicht gesperrt ist. Diese Tests
 * brauchen kein ImageMagick; sie prüfen nur die Zuordnung und die Ablehnung.
 */
class GhostscriptCoderTest extends TestCase {
	private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n";
	private const PS = "%!PS-Adobe-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n";
	private const PS_CTRL_D = "\x04%!PS-Adobe-3.0\nshowpage\n";
	private const EPS = "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\n";
	private const DOS_EPS = "\xC5\xD0\xD3\xC6\x1E\x00\x00\x00rest";
	private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR";

	private function format(Bitmap $provider, string $content, string $mimeType = 'application/octet-stream'): string {
		$method = new \ReflectionMethod($provider, 'getImagickFormat');
		$method->setAccessible(true);
		return $method->invoke($provider, $mimeType, $content);
	}

	public function providesAcceptedContent(): array {
		return [
			'PDF: PDF-Inhalt' => [new PDF(), self::PDF, 'PDF'],
			'Postscript: PostScript' => [new Postscript(), self::PS, 'PS'],
			'Postscript: EPS mit %!' => [new Postscript(), self::EPS, 'PS'],
			'Postscript: führendes Strg-D' => [new Postscript(), self::PS_CTRL_D, 'PS'],
			'Postscript: DOS-EPS' => [new Postscript(), self::DOS_EPS, 'EPS'],
			'Illustrator: PDF-basiert' => [new Illustrator(), self::PDF, 'PDF'],
			'Illustrator: PostScript-basiert' => [new Illustrator(), self::PS, 'PS'],
		];
	}

	/**
	 * Für jeden Inhalt gilt der Decoder und damit die Policy, die zu ihm gehört. Der
	 * gespeicherte Dateityp spielt keine Rolle: Im Papierkorb ist er
	 * application/octet-stream, und die Vorschau muss trotzdem gehen.
	 *
	 * @dataProvider providesAcceptedContent
	 */
	public function testPicksTheCoderOfTheContent(Bitmap $provider, string $content, string $expected): void {
		$this->assertSame($expected, $this->format($provider, $content));
		$this->assertSame($expected, $this->format($provider, $content, 'application/pdf'));
	}

	public function providesRefusedContent(): array {
		return [
			// der PDF-Decoder gäbe PostScript unter der Policy PDF an Ghostscript weiter
			'PDF: PostScript-Inhalt' => [new PDF(), self::PS],
			'PDF: PostScript mit Strg-D' => [new PDF(), self::PS_CTRL_D],
			'PDF: DOS-EPS' => [new PDF(), self::DOS_EPS],
			'PDF: PNG' => [new PDF(), self::PNG],
			'PDF: Vorspann vor %PDF-' => [new PDF(), "\n" . self::PDF],
			'PDF: leer' => [new PDF(), ''],
			'Postscript: PDF-Inhalt' => [new Postscript(), self::PDF],
			'Postscript: PNG' => [new Postscript(), self::PNG],
			'Postscript: Vorspann vor %!' => [new Postscript(), ' ' . self::PS],
			'Illustrator: DOS-EPS' => [new Illustrator(), self::DOS_EPS],
			'Illustrator: PNG' => [new Illustrator(), self::PNG],
			'Illustrator: leer' => [new Illustrator(), ''],
		];
	}

	/**
	 * @dataProvider providesRefusedContent
	 */
	public function testRefusesContentThatIsNotTheProvidersOwn(Bitmap $provider, string $content): void {
		$this->expectException(\RuntimeException::class);
		$this->format($provider, $content);
	}

	/**
	 * Der Decoder AI ist in der üblichen policy.xml nicht gesperrt und darf deshalb
	 * für keinen Inhalt mehr herauskommen.
	 */
	public function testIllustratorNeverPicksTheAiCoder(): void {
		foreach ([self::PDF, self::PS, self::PS_CTRL_D] as $content) {
			$this->assertNotSame('AI', $this->format(new Illustrator(), $content));
		}
	}

	public function providesConstantProviders(): array {
		return [
			'TIFF' => [new TIFF(), 'image/tiff', 'TIFF'],
			'Photoshop' => [new Photoshop(), 'application/x-photoshop', 'PSD'],
			'SGI' => [new SGI(), 'image/sgi', 'SGI'],
			'Heic' => [new Heic(), 'image/heic', 'HEIC'],
			'Font sfnt' => [new Font(), 'application/font-sfnt', 'TTF'],
			'Font Type 1' => [new Font(), 'application/x-font', 'PFB'],
		];
	}

	/**
	 * Alle übrigen Anbieter bleiben bei ihrem festen Decoder, egal was im Inhalt steht:
	 * PostScript oder PDF hinter einer .tiff-Datei erreicht Ghostscript weiterhin nicht.
	 *
	 * @dataProvider providesConstantProviders
	 */
	public function testOtherProvidersKeepTheirFixedCoder(Bitmap $provider, string $mimeType, string $expected): void {
		foreach ([self::PDF, self::PS, self::DOS_EPS, self::PNG, ''] as $content) {
			$this->assertSame($expected, $this->format($provider, $content, $mimeType));
		}
	}

	public function providesFailures(): array {
		return [
			'Policy sperrt den Decoder' => [new \Exception("attempt to perform an operation not allowed by the security policy `PDF' @ error/constitute.c/IsCoderAuthorized/421"), Util::ERROR],
			'Delegate fehlt' => [new \Exception("no decode delegate for this image format `HEIC' @ error/blob.c/BlobToImage/358"), Util::ERROR],
			'Ghostscript scheitert' => [new \Exception("PDFDelegateFailed `The system cannot find the file specified.' @ error/pdf.c/ReadPDFImage/801"), Util::ERROR],
			'eigene Ablehnung PDF' => [new \RuntimeException('Refusing to decode content that is not PDF for a PDF preview'), Util::DEBUG],
			'eigene Ablehnung Text' => [new \RuntimeException('Refusing to decode text-based content for a bitmap preview'), Util::DEBUG],
			'Inhalt passt nicht zum Decoder' => [new \Exception("Not a TIFF or MDI file, bad magic number 20617 (0x5089). `MemoryTIFF' @ error/tiff.c/TIFFErrors/600"), Util::DEBUG],
			'Kopf unbrauchbar' => [new \Exception("improper image header `/tmp/magick-x' @ error/psd.c/ReadPSDImage/2146"), Util::DEBUG],
		];
	}

	/**
	 * Betriebsfehler muss der Admin im Log sehen, eine Datei mit falscher Endung nicht.
	 *
	 * @dataProvider providesFailures
	 */
	public function testLogLevelSeparatesOperationalFailuresFromMismatchingContent(\Throwable $failure, int $expected): void {
		$method = new \ReflectionMethod(Bitmap::class, 'logLevelForFailure');
		$method->setAccessible(true);
		$this->assertSame($expected, $method->invoke(null, $failure));
	}

	/**
	 * Die Ablehnung geschieht, bevor ImageMagick den Inhalt sieht, und endet für den
	 * Nutzer schlicht ohne Vorschau.
	 *
	 * @requires extension imagick
	 */
	public function testPdfProviderGivesNoPreviewForPostScriptContent(): void {
		$stream = \fopen('php://memory', 'rb+');
		\fwrite($stream, self::PS);
		\rewind($stream);
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn($stream);
		$file->method('getMimeType')->willReturn('application/pdf');

		$this->assertFalse((new PDF())->getThumbnail($file, 32, 32, false));
	}
}
