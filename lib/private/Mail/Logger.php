<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-03-09.
 * Changes:
 *   - php8.3 (#41449)
 */

namespace OC\Mail;

use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger {
	private array $log;

	public function log($level, string|\Stringable $message, array $context = []): void {
		$this->log[] = [$level, $message, $context];
	}

	/**
	 * @throws \JsonException
	 */
	public function toJSON(): string {
		return json_encode($this->log, JSON_THROW_ON_ERROR);
	}
}
