<?php

declare(strict_types=1);

/**
 * MCP Connector Basic-auth parser regression test.
 * Modified by BW-Tech GmbH.
 */

require_once __DIR__ . '/../lib/Security/BasicAuthCredentials.php';

use OCA\OcoMcp\Security\BasicAuthCredentials;

$valid = 'Basic ' . \base64_encode('alice:token:with:colons');
if (BasicAuthCredentials::parse($valid) !== ['alice', 'token:with:colons']) {
	fwrite(STDERR, "FAIL: valid Basic credentials were not parsed\n");
	exit(1);
}

foreach ([
	'',
	'Bogus attacker-controlled',
	'Bearer token',
	'Basic !!!',
	'Basic ' . \base64_encode('missing-colon'),
	'Basic ' . \base64_encode(':secret'),
	'Basic ' . \base64_encode('alice:'),
] as $invalid) {
	if (BasicAuthCredentials::parse($invalid) !== null) {
		fwrite(STDERR, "FAIL: invalid Authorization header accepted\n");
		exit(1);
	}
}

fwrite(STDOUT, "PASS: MCP Basic-auth parser\n");
