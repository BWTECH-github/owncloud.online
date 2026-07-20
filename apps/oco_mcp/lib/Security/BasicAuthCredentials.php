<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Security;

/**
 * Strict parser for the HTTP Basic credentials required by the MCP endpoint.
 */
final class BasicAuthCredentials {
	/**
	 * @return array{0:string,1:string}|null Login name and password/app token.
	 */
	public static function parse(string $header): ?array {
		if (!\preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', \trim($header), $matches)) {
			return null;
		}

		$decoded = \base64_decode($matches[1], true);
		if ($decoded === false || !\str_contains($decoded, ':')) {
			return null;
		}

		[$login, $secret] = \explode(':', $decoded, 2);
		if (\trim($login) === '' || $secret === '') {
			return null;
		}

		return [$login, $secret];
	}
}
