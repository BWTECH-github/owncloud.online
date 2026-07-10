<?php

declare(strict_types=1);

/**
 * MCP Connector path validation regression test.
 * Modified by BW-Tech GmbH.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Tools/WriteGuard.php';
require_once __DIR__ . '/../lib/Tools/FilesTool.php';

$service = (new ReflectionClass(\OCA\OcoMcp\Tools\FilesTool::class))
	->newInstanceWithoutConstructor();
$clean = new ReflectionMethod($service, 'clean');
$clean->setAccessible(true);

if ($clean->invoke($service, 'Documents/report.txt') !== '/Documents/report.txt') {
	fwrite(STDERR, "FAIL: valid path normalization\n");
	exit(1);
}

foreach (['../config/config.php', 'Documents/./report.txt', 'Documents\\..\\config.php'] as $path) {
	try {
		$clean->invoke($service, $path);
		fwrite(STDERR, "FAIL: unsafe path accepted: {$path}\n");
		exit(1);
	} catch (ReflectionException $e) {
		throw $e;
	} catch (Throwable $e) {
		if (!$e instanceof \Mcp\Exception\ToolCallException) {
			throw $e;
		}
	}
}

fwrite(STDOUT, "PASS: MCP path validation\n");
