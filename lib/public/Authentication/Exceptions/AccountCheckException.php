<?php
/**
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
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
 *   - PHP 8.4 compatibility and owncloud.online design integration
 */

namespace OCP\Authentication\Exceptions;

use Exception;
use OCP\AppFramework\Http\RedirectResponse;

/**
 * Class AccountCheckException
 *
 * @package OCP\Authentication\Exceptions
 * @since 10.0.9
 */
class AccountCheckException extends Exception {
	/**
	 * @var RedirectResponse
	 */
	private $redirectResponse;

	/**
	 * AccountCheckException constructor.
	 *
	 * @param RedirectResponse $redirectResponse
	 * @param string $message
	 * @param int $code
	 * @param \Throwable|null $previous
	 * @since 10.0.9
	 */
	public function __construct(
		RedirectResponse $redirectResponse,
		$message = '',
		$code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct($message, $code, $previous);
		$this->redirectResponse = $redirectResponse;
	}

	/**
	 * @return RedirectResponse
	 * @since 10.0.9
	 */
	public function getRedirectResponse() {
		return $this->redirectResponse;
	}
}
