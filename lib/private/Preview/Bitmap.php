<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Olivier Paroz <github@oparoz.com>
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
 * Modified by BW-Tech GmbH on 2026-03-16.
 * Changes:
 *   - pick the Ghostscript coders by content so each content type hits its own policy
 *   - log policy and delegate failures as errors again, mismatching content at debug
 *   - log a rejected bitmap preview at debug level instead of error
 *   - release the file handle when a bitmap preview cannot be decoded (#41835)
 *   - PHP 8.4 compatibility and owncloud.online design integration
 *   - php8.3 (#41449)
 */

namespace OC\Preview;

use Imagick;
use OC\Image\ImagickFactory;
use OC\Preview;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Preview\IProvider2;
use OCP\Util;

/**
 * Creates a PNG preview using ImageMagick via the PECL extension
 *
 * @package OC\Preview
 */
abstract class Bitmap implements IProvider2 {
	/**
	 * {@inheritDoc}
	 */
	public function getThumbnail(File $file, $maxX, $maxY, $scalingUp) {
		if (Preview::isImageFileSizeTooBig($file)) {
			return false;
		}
		$stream = $file->fopen('r');
		if (!\is_resource($stream)) {
			// stream_get_contents() below would raise a TypeError, which is an \Error and
			// so would escape the handler underneath rather than degrade to no preview.
			// Nicht nur auf false prüfen: View::fopen() liefert null für gesperrte Pfade
			// (isForbiddenFileOrDir) und für Pfade ohne Speicher; fclose(null) im finally
			// wäre dann ein zweiter TypeError.
			Util::writeLog('core', 'Could not open ' . $file->getPath() . ' for a preview', Util::ERROR);
			return false;
		}

		// Creates \Imagick object from bitmap or vector file
		try {
			// cast on purpose: getMimeType() reaches a string-typed parameter, but it comes
			// from FileInfo::getMimetype(), which hands back whatever Cache::get() stored -
			// and that is MimeTypeLoader::getMimetypeById(), null for an id with no row in
			// oc_mimetypes. No foreign key guards that column, so a dangling id is
			// reachable, and an uncast null would raise a TypeError. Being an \Error that
			// escapes the handler below, it would turn a missing preview into a 500.
			$bp = $this->getResizedPreview($stream, $maxX, $maxY, (string)$file->getMimeType());
		} catch (\Exception $e) {
			// Jede hochgeladene Datei, deren Endung nicht zum Inhalt passt (etwa ein PNG
			// namens bild.tiff), landet hier, weil der festgelegte Decoder den Inhalt
			// ablehnt. Das ist erwartetes Verhalten und von jedem Nutzer per Upload
			// auslösbar, darum nur DEBUG. Betriebsfehler bleiben dagegen sichtbar,
			// siehe logLevelForFailure(). Ergebnis ist in beiden Fällen „keine Vorschau“.
			Util::writeLog('core', 'ImageMagick says: ' . $e->getMessage(), self::logLevelForFailure($e));
			return false;
		} finally {
			// also on the failure path: any content ImageMagick has no coder for lands
			// here, so leaking the handle would be routine rather than exceptional
			\fclose($stream);
		}

		//new bitmap image object
		$image = new \OC_Image();
		$image->loadFromData((string)$bp);
		//check if image object is valid
		return $image->valid() ? $image : false;
	}

	/**
	 * @inheritdoc
	 */
	public function isAvailable(FileInfo $file) {
		return true;
	}

	/**
	 * Returns a preview of maxX times maxY dimensions in PNG format
	 *
	 *    * The default resolution is already 72dpi, no need to change it for a bitmap output
	 *    * It's possible to have proper colour conversion using profileimage().
	 *    ICC profiles are here: http://www.color.org/srgbprofiles.xalter
	 *    * It's possible to Gamma-correct an image via gammaImage()
	 *
	 * @param resource $stream the handle of the file to convert
	 * @param int $maxX
	 * @param int $maxY
	 * @param string $mimeType the file's own detected mime type, used to pin the
	 *   Imagick coder so it cannot be redirected by the file's actual content
	 *
	 * @return Imagick
	 */
	private function getResizedPreview($stream, int $maxX, int $maxY, string $mimeType): Imagick {
		$content = \stream_get_contents($stream);

		if ($this->isDangerousToDecode($content)) {
			throw new \RuntimeException('Refusing to decode text-based content for a bitmap preview');
		}

		// Erst den Decoder bestimmen: Passt der Inhalt nicht zum Anbieter, wird hier
		// abgelehnt, bevor ImageMagick überhaupt angefasst wird.
		$format = $this->getImagickFormat($mimeType, $content);

		$bp = ImagickFactory::create();

		# Pin the coder instead of letting Imagick's own content-sniffing pick one:
		# reading with no format set re-derives the format from a ~130-entry magic
		# table independently of isDangerousToDecode()'s check above, so content that
		# looks like PostScript/PDF (which that check must allow through for the
		# Postscript/PDF providers) would otherwise reach the Ghostscript delegate via
		# any Bitmap provider, not just those two.
		#
		# Deliberately not guarded by queryFormats(): if this build does not register
		# the coder, throwing here is correct - the only alternative is falling back to
		# the content-sniffing this pin exists to prevent.
		$bp->setFormat($format);
		$bp->readImageBlob($content);

		# setIteratorIndex(0) will make previews to be generated from the first page
		$bp->setIteratorIndex(0);

		$bp = $this->resize($bp, $maxX, $maxY);

		# setFormat() above pins the wand's *output* format as well as the input coder,
		# so both have to be set here. setImageFormat() alone would leave getThumbnail()'s
		# (string) cast re-encoding back to the pinned input format instead of PNG.
		$bp->setImageFormat('png');
		$bp->setFormat('png');

		return $bp;
	}

	/**
	 * Maps this provider's own detected mime type(s) to the Imagick coder name that
	 * must decode them - the format pinned in getResizedPreview() above.
	 *
	 * $mimeType comes from $file->getMimeType(), deliberately not from the type that
	 * selected this provider (OC\Preview::$mimeType). Those two can differ, because
	 * callers may override the selection type via getThumbnail(['mimeType' => ...]) -
	 * apps/files_trashbin/ajax/preview.php does, and apps/dav passes the request's query
	 * parameters straight through. The file's own type cannot be steered by a request,
	 * which is the property the pin depends on.
	 *
	 * The consequence is that an implementation must cope with a mime type it does not
	 * serve: a trashed file reports application/octet-stream, because the .d<timestamp>
	 * suffix defeats extension-based detection. Returning a constant handles that
	 * correctly. Do NOT "fix" the divergence by rejecting a $mimeType that fails this
	 * provider's own getMimeType() regex - that rejects every trashbin preview.
	 *
	 * $content ist der schon gelesene Dateiinhalt. Die von Ghostscript gelesenen Anbieter
	 * (PDF, Postscript, Illustrator) wählen damit über detectGhostscriptContent() den
	 * Decoder, der zum Inhalt gehört, und lehnen alles andere mit einer \RuntimeException
	 * ab. Alle übrigen Anbieter geben weiter eine Konstante zurück.
	 *
	 * @throws \RuntimeException wenn der Inhalt nicht zum Anbieter passt
	 */
	abstract protected function getImagickFormat(string $mimeType, string $content): string;

	/**
	 * Erkennt an den ersten Bytes, welche von Ghostscript gelesene Inhaltsart vorliegt:
	 * 'PDF', 'PS', 'EPS' (DOS-EPS mit Binärkopf) oder null.
	 *
	 * Bewusst nur an Stelle 0 und ohne Toleranz für einen Vorspann: Genau so hat
	 * ImageMagick entschieden, solange der Decoder nicht festgelegt war. Damit wird
	 * für jeden Inhalt wieder die Policy abgefragt, die zu ihm gehört (PDF bzw. PS).
	 * Ein fester Decoder je Anbieter hätte das verschoben: PostScript hinter einer
	 * .pdf-Datei liefe unter der Policy PDF, jede .ai-Datei unter der Policy AI, die
	 * in der üblichen policy.xml gar nicht gesperrt ist. Eine Sperre des Admins für
	 * PS oder PDF bliebe dann wirkungslos. Für DOS-EPS gilt EPS statt des früher
	 * geratenen EPT; EPS steht in den üblichen Sperrlisten, EPT nicht.
	 */
	protected static function detectGhostscriptContent(string $content): ?string {
		if (\strncmp($content, '%PDF-', 5) === 0) {
			return 'PDF';
		}
		if (\strncmp($content, '%!', 2) === 0 || \strncmp($content, "\x04%!", 3) === 0) {
			return 'PS';
		}
		if (\strncmp($content, "\xC5\xD0\xD3\xC6", 4) === 0) {
			return 'EPS';
		}
		return null;
	}

	/**
	 * Protokollstufe für eine gescheiterte Vorschau.
	 *
	 * Sperrt die ImageMagick-Policy den Decoder oder fehlt bzw. scheitert ein Delegate
	 * (Ghostscript), ist das ein Betriebsfehler, den der Admin sehen muss: ERROR. Alles
	 * andere - unsere eigene Ablehnung und Decoder-Fehler, weil der Inhalt nicht zur
	 * Endung passt - ist erwartbar und bleibt auf DEBUG.
	 */
	protected static function logLevelForFailure(\Throwable $e): int {
		return \preg_match('/security policy|delegate/i', $e->getMessage()) === 1 ? Util::ERROR : Util::DEBUG;
	}

	/**
	 * Bitmap providers must never hand text-based content (SVG, XML, or any other
	 * text/* type, e.g. a raw MVG script) to Imagick::readImageBlob() - ImageMagick's
	 * text/vector coders can be abused to read and write arbitrary files.
	 *
	 * Known limitation, pre-dating this check and deliberately not closed here: the
	 * deny-list can only be as good as the detection behind it. Detection::detectString()
	 * needs either ext-fileinfo or the "file" binary, and ext-fileinfo is not actually
	 * required to run the server - OC_Util::checkServer() does not list it, and
	 * OC_Util::fileInfoLoaded() only raises an admin-panel recommendation. On an install
	 * with neither, every payload is reported as application/octet-stream and this method
	 * returns false for all of them, leaving the per-provider coder pin in
	 * getResizedPreview() as the only remaining layer. Failing closed instead would cost
	 * every bitmap preview on a supported configuration, which is why it is
	 * recorded here rather than rejected.
	 */
	private function isDangerousToDecode(string $content): bool {
		$mimeType = \OC::$server->getMimeTypeDetector()->detectString($content);
		$mimeType = \strtolower(\trim(\explode(';', $mimeType, 2)[0]));

		// libmagic reports "image/svg" without the "+xml" suffix on some PHP/OS builds
		if (\strpos($mimeType, 'text/') === 0 || \strpos($mimeType, 'image/svg') === 0) {
			return true;
		}

		return \in_array($mimeType, ['application/xml', 'image/x-mvg'], true);
	}

	/**
	 * Returns a resized \Imagick object
	 *
	 * If you want to know more on the various methods available to resize an
	 * image, check out this link : @link https://stackoverflow.com/questions/8517304/what-the-difference-of-sample-resample-scale-resize-adaptive-resize-thumbnail-im
	 *
	 * @param \Imagick $bp
	 * @param int $maxX
	 * @param int $maxY
	 *
	 * @return \Imagick
	 */
	private function resize($bp, $maxX, $maxY) {
		list($previewWidth, $previewHeight) = \array_values($bp->getImageGeometry());

		// We only need to resize a preview which doesn't fit in the maximum dimensions
		if ($previewWidth > $maxX || $previewHeight > $maxY) {
			// TODO: LANCZOS is the default filter, CATROM could bring similar results faster
			$bp->resizeImage($maxX, $maxY, imagick::FILTER_LANCZOS, 1, true);
		}

		return $bp;
	}
}
