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

namespace OC\Log;

/**
 * Liest owncloud.log (log_type owncloud) rückwärts – neueste Einträge zuerst.
 *
 * Die Datei kann viele Gigabyte groß sein. Gelesen wird deshalb blockweise vom
 * Ende her und je Aufruf höchstens ein festes Budget an Bytes. Wo ein Aufruf
 * aufhört, steht immer ein Zeilenanfang; diese Byte-Position gibt read() als
 * next_offset zurück, und der nächste Aufruf liest von dort aus weiter nach
 * vorn („Mehr laden“). Weil die Datei nur wächst, bleiben solche Positionen
 * gültig, bis sie rotiert wird – das erkennt read() und beginnt dann wieder
 * am Ende (restarted).
 *
 * Log::logException() schreibt die Previous-Kette einer Ausnahme als eigene
 * Zeilen „Caused by: {…}“ direkt hinter die Ausnahme. Solche Zeilen hängt der
 * Leser als previous an den Eintrag derselben Anfrage, statt sie einzeln
 * auszugeben.
 */
class LogFileReader {
	/** Höchstzahl Einträge je Aufruf */
	public const MAX_COUNT = 200;
	/** So viele Bytes liest ein Aufruf höchstens, auch wenn nichts passt */
	public const SCAN_BUDGET = 33554432;
	/** Grobe Obergrenze für die Textmenge einer Antwort */
	public const RESULT_BUDGET = 2097152;
	/** Längere Zeilen werden nur mit ihrem Anfang gelesen */
	public const MAX_LINE_BYTES = 1048576;
	/** Textfelder der Antwort werden auf so viele Bytes gekürzt */
	public const MAX_FIELD_BYTES = 65536;

	private const CHUNK_BYTES = 65536;
	private const MAX_CAUSES = 20;
	private const CAUSE_PREFIX = 'Caused by: {';
	private const EXCEPTION_MARKER = ': {"Exception":';

	/** @var string */
	private $path;
	/** @var int */
	private $scanBudget;
	/** @var int */
	private $resultBudget;

	public function __construct(string $path, int $scanBudget = self::SCAN_BUDGET, int $resultBudget = self::RESULT_BUDGET) {
		$this->path = $path;
		$this->scanBudget = \max(1, $scanBudget);
		$this->resultBudget = \max(1, $resultBudget);
	}

	public function isReadable(): bool {
		return $this->path !== '' && \is_file($this->path) && \is_readable($this->path);
	}

	/**
	 * @param int $offset Byte-Position, ab der rückwärts gelesen wird; 0 = Dateiende
	 * @param int $count höchstens so viele Einträge (1 bis MAX_COUNT)
	 * @param int|null $minLevel nur Einträge ab dieser Stufe (0 bis 4), null = alle
	 * @param string $app nur Einträge dieser App (ohne Rücksicht auf Groß- und
	 *                    Kleinschreibung), '' = alle
	 * @param string $search Teilzeichenkette in Meldung, Ausnahme, Trace, App,
	 *                       Benutzer, URL, Anfrage-ID oder Adresse, '' = keine Suche
	 * @return array{entries: array[], next_offset: int|null, restarted: bool, size: int, scanned: int}
	 * @throws \RuntimeException wenn die Datei sich nicht öffnen lässt
	 */
	public function read(int $offset, int $count, ?int $minLevel = null, string $app = '', string $search = ''): array {
		$count = \max(1, \min(self::MAX_COUNT, $count));
		$result = [
			'entries' => [],
			'next_offset' => null,
			'restarted' => false,
			'size' => 0,
			'scanned' => 0,
		];

		$handle = @\fopen($this->path, 'rb');
		if ($handle === false) {
			throw new \RuntimeException('The log file cannot be opened');
		}
		try {
			$stat = \fstat($handle);
			$size = $stat === false ? 0 : (int)$stat['size'];
			$result['size'] = $size;

			if ($offset > 0 && !$this->isLineStart($handle, $offset, $size)) {
				// Die Datei wurde seit dem letzten Abruf rotiert oder gekürzt.
				$result['restarted'] = true;
				$offset = 0;
			}
			if ($offset <= 0) {
				// Eine Zeile, die gerade geschrieben wird, hat noch keinen Zeilenumbruch.
				$offset = $this->endOfLastCompleteLine($handle, $size);
			}

			$this->collect($handle, $offset, $count, $minLevel, $app, $search, $result);
		} finally {
			\fclose($handle);
		}
		return $result;
	}

	/**
	 * @param resource $handle
	 */
	private function isLineStart($handle, int $offset, int $size): bool {
		if ($offset > $size) {
			return false;
		}
		\fseek($handle, $offset - 1);
		return \fread($handle, 1) === "\n";
	}

	/**
	 * @param resource $handle
	 */
	private function endOfLastCompleteLine($handle, int $size): int {
		$position = $size;
		while ($position > 0) {
			$length = (int)\min(self::CHUNK_BYTES, $position);
			\fseek($handle, $position - $length);
			$chunk = (string)\fread($handle, $length);
			$newline = \strrpos($chunk, "\n");
			if ($newline !== false) {
				return $position - $length + $newline + 1;
			}
			$position -= $length;
			if ($size - $position > self::MAX_LINE_BYTES) {
				// Keine abgeschlossene Zeile in Sicht – dann eben alles lesen.
				return $size;
			}
		}
		return $size;
	}

	/**
	 * Liest Zeilen rückwärts, bis $count Einträge passen oder ein Budget
	 * aufgebraucht ist.
	 *
	 * $resume ist die Position, ab der ein späterer Abruf weiterlesen muss: der
	 * Anfang der ältesten Zeile, die vollständig erledigt ist (ausgegeben,
	 * weggefiltert oder zugeordnet). Zurückgehaltene „Caused by“-Zeilen gelten
	 * als nicht erledigt – endet der Abruf mit ihnen, liest der nächste sie noch
	 * einmal und ordnet sie dann zu.
	 *
	 * @param resource $handle
	 */
	private function collect($handle, int $offset, int $count, ?int $minLevel, string $app, string $search, array &$result): void {
		$causes = [];
		$resultBytes = 0;
		$resume = $offset;
		$previousStart = $offset;
		$lastStart = $offset;
		$stopped = false;

		$emit = function (array $entry) use (&$result, &$resultBytes, $minLevel, $app, $search): void {
			if ($this->matches($entry, $minLevel, $app, $search)) {
				$result['entries'][] = $this->stripInternal($entry);
				$resultBytes += $entry['_bytes'];
			}
		};
		$flush = function () use (&$causes, $emit): void {
			foreach ($causes as $cause) {
				$emit($cause);
			}
			$causes = [];
		};

		foreach ($this->linesBackwards($handle, $offset) as [$start, $line, $truncated]) {
			$lastStart = $start;
			$result['scanned'] = $offset - $start;

			// Billiger Vorfilter ohne json_decode. Eine „Caused by“-Zeile hat die
			// Stufe ihrer Ausnahme und fällt mit ihr heraus.
			if ($minLevel === null || !$this->levelBelow($line, $minLevel)) {
				$entry = $this->parseLine($line, $start, $truncated);
				if ($entry['_cause']) {
					if ($causes !== [] && ($causes[0]['reqId'] !== $entry['reqId'] || \count($causes) >= self::MAX_CAUSES)) {
						// Die ältere Gruppe ist damit erledigt, die neue beginnt mit dieser Zeile.
						$flush();
						$resume = $previousStart;
					}
					$causes[] = $entry;
				} else {
					if ($causes !== []) {
						if ($entry['exception'] !== null && $entry['reqId'] === $causes[0]['reqId']) {
							$entry = $this->attachCauses($entry, $causes);
							$causes = [];
						} else {
							$flush();
						}
					}
					$emit($entry);
				}
			}

			if ($causes === []) {
				$resume = $start;
			}
			$previousStart = $start;
			if (\count($result['entries']) >= $count
				|| $resultBytes >= $this->resultBudget
				|| $result['scanned'] >= $this->scanBudget) {
				$stopped = true;
				break;
			}
		}

		if ($causes !== [] && !($stopped && $resume < $offset)) {
			// Am Dateianfang, oder der Abruf käme sonst nicht von der Stelle: Die
			// Ursachen ohne ihre Ausnahme einzeln ausgeben.
			$flush();
			$resume = $lastStart;
		}

		$result['next_offset'] = ($stopped && $resume > 0) ? $resume : null;
	}

	/**
	 * @param array $causes rückwärts gelesen, die tiefste Ursache zuerst
	 */
	private function attachCauses(array $entry, array $causes): array {
		foreach (\array_reverse($causes) as $cause) {
			if ($cause['exception'] !== null) {
				$entry['previous'][] = $cause['exception'];
			}
			$entry['previous'] = \array_merge($entry['previous'], $cause['previous']);
			$entry['_haystack'] .= "\n" . $cause['_haystack'];
			$entry['_bytes'] += $cause['_bytes'];
			$entry['truncated'] = $entry['truncated'] || $cause['truncated'];
		}
		return $entry;
	}

	/**
	 * Zeilen von $end aus rückwärts, als [Zeilenanfang, Text, gekürzt].
	 *
	 * @param resource $handle
	 * @return \Generator
	 */
	private function linesBackwards($handle, int $end): \Generator {
		$position = $end;
		$buffer = '';
		$truncated = false;
		while ($position > 0) {
			$length = (int)\min(self::CHUNK_BYTES, $position);
			$position -= $length;
			\fseek($handle, $position);
			$buffer = (string)\fread($handle, $length) . $buffer;

			$lineEnd = \strlen($buffer);
			while ($lineEnd > 0) {
				$newline = \strrpos($buffer, "\n", $lineEnd - \strlen($buffer) - 1);
				if ($newline === false) {
					break;
				}
				$line = (string)\substr($buffer, $newline + 1, $lineEnd - $newline - 1);
				if (\trim($line) !== '') {
					yield [$position + $newline + 1, $line, $truncated];
				}
				$truncated = false;
				$lineEnd = $newline;
			}
			$buffer = (string)\substr($buffer, 0, $lineEnd);
			if (\strlen($buffer) > self::MAX_LINE_BYTES) {
				// Riesige Zeile: nur ihren Anfang behalten, dort stehen Stufe, Zeit
				// und App. Das hintere Ende wird verworfen.
				$buffer = (string)\substr($buffer, 0, self::MAX_LINE_BYTES);
				$truncated = true;
			}
		}
		if (\trim($buffer) !== '') {
			yield [0, $buffer, $truncated];
		}
	}

	/**
	 * Erkennt "level":N am Zeilenanfang, ohne die Zeile zu dekodieren.
	 */
	private function levelBelow(string $line, int $minLevel): bool {
		if (\preg_match('/^\{"reqId":"[^"]*","level":(\d)/', $line, $match) !== 1) {
			return false;
		}
		return (int)$match[1] < $minLevel;
	}

	private function parseLine(string $line, int $start, bool $truncated): array {
		$data = \json_decode($line, true);
		if (!\is_array($data)) {
			$data = $this->parseBrokenLine($line);
			$truncated = true;
		}

		$message = $this->text($data['message'] ?? '');
		$isCause = \strpos($message, self::CAUSE_PREFIX) === 0;

		$exception = null;
		$previous = [];
		$marker = \strpos($message, self::EXCEPTION_MARKER);
		if ($marker !== false) {
			$decoded = \json_decode((string)\substr($message, $marker + 2), true);
			if (\is_array($decoded) && isset($decoded['Exception'])) {
				$exception = $this->exceptionFields($decoded, $truncated);
				// Andere Logger hängen die Kette verschachtelt als Previous an.
				$next = $decoded['Previous'] ?? null;
				while (\is_array($next) && \count($previous) < self::MAX_CAUSES) {
					$previous[] = $this->exceptionFields($next, $truncated);
					$next = $next['Previous'] ?? null;
				}
				$message = (string)\substr($message, 0, $marker);
			}
		}

		$entry = [
			'offset' => $start,
			'reqId' => $this->field($data, 'reqId', $truncated),
			'level' => (isset($data['level']) && \is_numeric($data['level'])) ? (int)$data['level'] : null,
			'time' => $this->field($data, 'time', $truncated),
			'remoteAddr' => $this->field($data, 'remoteAddr', $truncated),
			'user' => $this->field($data, 'user', $truncated),
			'app' => $this->field($data, 'app', $truncated),
			'method' => $this->field($data, 'method', $truncated),
			'url' => $this->field($data, 'url', $truncated),
			'message' => $this->limit($message, $truncated),
			'exception' => $exception,
			'previous' => $previous,
		];
		$entry['truncated'] = $truncated;

		$haystack = [$entry['message'], $entry['app'], $entry['user'], $entry['url'], $entry['method'], $entry['reqId'], $entry['remoteAddr'], $entry['time']];
		foreach (\array_merge($exception === null ? [] : [$exception], $previous) as $item) {
			$haystack[] = $item['class'];
			$haystack[] = $item['message'];
			$haystack[] = $item['file'];
			$haystack[] = $item['trace'];
		}
		$entry['_haystack'] = \implode("\n", $haystack);
		$entry['_bytes'] = \strlen($entry['_haystack']);
		$entry['_cause'] = $isCause;
		return $entry;
	}

	/**
	 * Zeile, die kein vollständiges JSON ist (gekürzt oder fremd geschrieben):
	 * Kopffelder per Muster lesen, den Rest als Meldung zeigen.
	 */
	private function parseBrokenLine(string $line): array {
		$data = [];
		foreach (['reqId', 'time', 'remoteAddr', 'user', 'app', 'method', 'url', 'message'] as $key) {
			// Bei der Meldung fehlt in einer gekürzten Zeile das schließende Anführungszeichen.
			$closing = $key === 'message' ? '' : '"';
			if (\preg_match('/"' . $key . '":"((?:[^"\\\\]|\\\\.)*)' . $closing . '/', $line, $match) === 1) {
				$value = \json_decode('"' . $match[1] . '"');
				$data[$key] = \is_string($value) ? $value : \stripcslashes($match[1]);
			}
		}
		if (\preg_match('/"level":(\d)/', $line, $match) === 1) {
			$data['level'] = (int)$match[1];
		}
		if (!isset($data['message'])) {
			$data['message'] = $line;
		}
		return $data;
	}

	/**
	 * @param array $data Ausnahme, wie Log::logException() sie als JSON schreibt
	 */
	private function exceptionFields(array $data, bool &$truncated): array {
		return [
			'class' => $this->limit($this->text($data['Exception'] ?? ''), $truncated),
			'message' => $this->limit($this->text($data['Message'] ?? ''), $truncated),
			'code' => $this->limit($this->text($data['Code'] ?? ''), $truncated),
			'file' => $this->limit($this->text($data['File'] ?? ''), $truncated),
			'line' => (isset($data['Line']) && \is_numeric($data['Line'])) ? (int)$data['Line'] : null,
			'trace' => $this->limit($this->text($data['Trace'] ?? ''), $truncated),
		];
	}

	/**
	 * @param mixed $value
	 */
	private function text($value): string {
		if (\is_string($value)) {
			return $value;
		}
		if ($value === null) {
			return '';
		}
		if (\is_scalar($value)) {
			return (string)$value;
		}
		return (string)\json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	private function field(array $data, string $key, bool &$truncated): string {
		return $this->limit($this->text($data[$key] ?? ''), $truncated);
	}

	/**
	 * Kürzt auf MAX_FIELD_BYTES an einer Zeichengrenze und ersetzt ungültiges
	 * UTF-8, damit die Antwort immer als JSON kodierbar bleibt.
	 */
	private function limit(string $value, bool &$truncated): string {
		if (!\mb_check_encoding($value, 'UTF-8')) {
			$value = \mb_scrub($value, 'UTF-8');
		}
		if (\strlen($value) > self::MAX_FIELD_BYTES) {
			$value = \mb_strcut($value, 0, self::MAX_FIELD_BYTES, 'UTF-8');
			$truncated = true;
		}
		return $value;
	}

	private function matches(array $entry, ?int $minLevel, string $app, string $search): bool {
		if ($minLevel !== null && ($entry['level'] === null || $entry['level'] < $minLevel)) {
			return false;
		}
		if ($app !== '' && \mb_strtolower($entry['app'], 'UTF-8') !== \mb_strtolower($app, 'UTF-8')) {
			return false;
		}
		if ($search !== '' && \mb_stripos($entry['_haystack'], $search, 0, 'UTF-8') === false) {
			return false;
		}
		return true;
	}

	private function stripInternal(array $entry): array {
		unset($entry['_haystack'], $entry['_bytes'], $entry['_cause']);
		return $entry;
	}
}
