<?php
/**
 * OPcache preload script for owncloud.online (opt-in).
 *
 * Enable it in php.ini / the FPM pool of the instance:
 *
 *   opcache.preload=/var/www/owncloud.online/build/preload.php
 *   opcache.preload_user=www-data
 *
 * At FPM start this compiles the stable server code (lib/private, lib/public,
 * core/ and the composer vendor tree under lib/composer) into OPcache, so
 * workers skip per-request class linking and the warm-up phase after a
 * reload. Combine with opcache.validate_timestamps=0 (see
 * docs/administration/performance.md) and reload FPM on every deploy.
 *
 * App directories are deliberately NOT preloaded: apps are enabled, disabled
 * and updated at runtime, so their code must stay resolvable per request.
 *
 * Preloading is best-effort by design: a failure to compile a single file
 * must never prevent FPM from starting, so every compile is wrapped.
 * "Can't preload unlinked class" warnings at startup are harmless - those
 * classes are cached unlinked and get linked on first use.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 */

if (!\function_exists('opcache_compile_file')) {
	return;
}

$serverRoot = \dirname(__DIR__);
$classmapFile = $serverRoot . '/lib/composer/composer/autoload_classmap.php';
if (!\is_file($classmapFile)) {
	// composer install has not run (or a packaged build relocated the vendor
	// dir) - silently skip, preloading is an optimisation only.
	return;
}

/** @var array<string,string> $classmap class => absolute file path */
$classmap = require $classmapFile;

$files = [];
foreach ($classmap as $file) {
	$real = \realpath($file);
	if ($real === false) {
		continue;
	}
	// Only code shipped with the server itself; never apps/, config/ or data/.
	if (\strpos($real, $serverRoot . '/lib/') !== 0
		&& \strpos($real, $serverRoot . '/core/') !== 0
	) {
		continue;
	}
	$files[$real] = true;
}

$compiled = 0;
foreach (\array_keys($files) as $file) {
	try {
		if (!\opcache_is_script_cached($file) && @\opcache_compile_file($file)) {
			$compiled++;
		}
	} catch (\Throwable $e) {
		// best-effort: skip files that cannot be preloaded
	}
}

\error_log('owncloud.online preload: compiled ' . $compiled . ' of ' . \count($files) . ' files');
