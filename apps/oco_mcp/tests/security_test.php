<?php

declare(strict_types=1);

/**
 * MCP Connector path validation regression test.
 * Modified by BW-Tech GmbH.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Tools/PathHelper.php';
require_once __DIR__ . '/../lib/Tools/WriteGuard.php';
require_once __DIR__ . '/../lib/Tools/FilesTool.php';

$failures = [];

function check(string $label, bool $ok): void {
	global $failures;
	if (!$ok) {
		$failures[] = $label;
	}
}

/**
 * Run a callable and report whether it rejected the input with a ToolCallException.
 */
function rejects(callable $fn): bool {
	try {
		$fn();
	} catch (\Mcp\Exception\ToolCallException $e) {
		return true;
	}
	return false;
}

// --- 1. The shared gate itself -------------------------------------------

check(
	'PathHelper normalises a plain relative path',
	\OCA\OcoMcp\Tools\PathHelper::clean('Documents/report.txt') === '/Documents/report.txt'
);
check(
	'PathHelper strips a trailing slash',
	\OCA\OcoMcp\Tools\PathHelper::clean('/Documents/') === '/Documents'
);
check(
	'PathHelper maps the empty path to the user root',
	\OCA\OcoMcp\Tools\PathHelper::clean('') === '/'
);

$unsafe = [
	'../config/config.php',
	'Documents/./report.txt',
	'Documents\\..\\config.php',
	'..',
	'/a/../../b',
	'  ../secret  ',
];
foreach ($unsafe as $path) {
	check(
		"PathHelper rejects unsafe path: {$path}",
		rejects(static fn () => \OCA\OcoMcp\Tools\PathHelper::clean($path))
	);
}

// --- 2. FilesTool still routes through the gate ---------------------------

$filesTool = (new ReflectionClass(\OCA\OcoMcp\Tools\FilesTool::class))->newInstanceWithoutConstructor();
$clean = new ReflectionMethod($filesTool, 'clean');
$clean->setAccessible(true);

check(
	'FilesTool::clean normalises like PathHelper',
	$clean->invoke($filesTool, 'Documents/report.txt') === '/Documents/report.txt'
);
foreach ($unsafe as $path) {
	check(
		"FilesTool::clean rejects unsafe path: {$path}",
		rejects(static fn () => $clean->invoke($filesTool, $path))
	);
}

// --- 3. No tool may resolve a client path on its own -----------------------
//
// SEC-14: SharesTool/TagsTool/CommentsTool/AiDocumentsTool used to call
// getUserFolder($uid)->get('/' . ltrim($path, '/')) directly, bypassing the
// strict gate. Core's Root::get() does reject '..', but that made the
// connector's safety a property of core internals. This static check keeps the
// raw pattern from coming back.

$pathConsumers = [
	'lib/Tools/FilesTool.php',
	'lib/Tools/SharesTool.php',
	'lib/Tools/TagsTool.php',
	'lib/Tools/CommentsTool.php',
	'lib/Tools/AiDocumentsTool.php',
	'lib/Mcp/FileResourceProvider.php',
];
foreach ($pathConsumers as $rel) {
	$source = (string)\file_get_contents(__DIR__ . '/../' . $rel);
	check(
		"{$rel} does not resolve paths without PathHelper",
		!\preg_match('/->get\(\s*[\'"]\/[\'"]\s*\.\s*\\\\?ltrim\(/', $source)
	);
	check(
		"{$rel} references PathHelper",
		\str_contains($source, 'PathHelper')
	);
}

// --- 4. No backend exception text may reach the MCP client -----------------
//
// SEC-15: the SDK hands a ToolCallException's message straight to the client
// (CallToolHandler), so raw Throwable messages from ai_documents would expose
// the gateway host, DB errors or internal paths.

$aiSource = (string)\file_get_contents(__DIR__ . '/../lib/Tools/AiDocumentsTool.php');
check(
	'AiDocumentsTool does not forward raw exception messages',
	!\str_contains($aiSource, '$e->getMessage()')
);
check(
	'AiDocumentsTool logs the exception server-side instead',
	\str_contains($aiSource, 'logException(')
);

// --- Result ----------------------------------------------------------------

if ($failures !== []) {
	foreach ($failures as $failure) {
		\fwrite(\STDERR, "FAIL: {$failure}\n");
	}
	exit(1);
}

\fwrite(\STDOUT, "PASS: MCP path validation and error redaction\n");
