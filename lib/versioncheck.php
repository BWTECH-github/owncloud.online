<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-24.
 * Changes:
 *   - restore lib/base.php as the boot file, add lib/versioncheck.php
 */

// This file must stay parseable by ancient PHP versions: it is loaded before
// anything else so users on an unsupported PHP get a readable message instead
// of a parse error. No modern syntax here.

# check PHP version
$eol = '<br>';
if (\defined('OC_CONSOLE')) {
	$eol = PHP_EOL;
}
if (PHP_VERSION_ID < 80400) {
	echo 'This version of owncloud.online requires at least PHP 8.4.0'.$eol;
	echo 'You are currently running PHP ' . PHP_VERSION . '. Please update your PHP version.'.$eol;
	exit(1);
}

// running oC on Windows is unsupported since 8.1, this has to happen here because
// it seems that the autoloader on Windows fails later and just throws an exception.
if (PHP_OS_FAMILY === 'Windows') {
	echo 'owncloud.online does not support Microsoft Windows.';
	exit(1);
}
