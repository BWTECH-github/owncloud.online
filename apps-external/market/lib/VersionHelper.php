<?php
/**
 * @author Viktar Dubiniuk <dubinuk@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 *
 *
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
 * Modified by BW-Tech GmbH on 2026-06-16.
 * Changes:
 *   - bundle the market app (neutral, local catalog default)
 */

namespace OCA\Market;

use OCP\Util;

class VersionHelper {
	/**
	 * Get the current ownCloud version
	 */
	public function getPlatformVersion(?int $cutTo = null): string {
		$v = Util::getVersion();
		if ($cutTo !== null) {
			$v = \array_slice($v, 0, $cutTo);
		}
		return \join('.', $v);
	}

	/**
	 * Check if both versions has the same major part
	 */
	public function isSameMajorVersion(?string $first, ?string $second): bool {
		if ($first === null || $second === null) {
			return false;
		}
		return $this->getMajorVersion($first) === $this->getMajorVersion($second);
	}

	public function lessThanOrEqualTo(string $first, string $second): bool {
		return \version_compare($first, $second, '<=');
	}

	/**
	 * Parameters will be normalized and then passed into version_compare
	 * in the same order they are specified in the method header.
	 */
	public function compare(?string $first, ?string $second, string $operator): bool {
		// we can't normalize versions if one of the given parameters is not a
		// version string but null. In case one parameter is null normalization
		// will therefore be skipped
		if ($first !== null && $second !== null) {
			[$first, $second] = $this->normalizeVersions($first, $second);
		}
		return \version_compare((string) $first, (string) $second, $operator);
	}

	/**
	 * Truncates both versions to the lowest common version, e.g.
	 * 5.1.2.3 and 5.1 will be turned into 5.1 and 5.1,
	 * 5.2.6.5 and 5.1 will be turned into 5.2 and 5.1.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function normalizeVersions(string $first, string $second): array {
		$firstParts = \explode('.', $first);
		$secondParts = \explode('.', $second);

		// get both arrays to the same minimum size
		$length = \min(\count($secondParts), \count($firstParts));
		$firstParts = \array_slice($firstParts, 0, $length);
		$secondParts = \array_slice($secondParts, 0, $length);

		return [\implode('.', $firstParts), \implode('.', $secondParts)];
	}

	private function getMajorVersion(string $version): ?int {
		$versionArray = \explode('.', $version);
		return isset($versionArray[0]) ? (int) $versionArray[0] : null;
	}
}
