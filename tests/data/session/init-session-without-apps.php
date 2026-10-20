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

/**
 * Kindprozess für Test\Session\InitSessionTest: OC::initSession() für eine
 * WebDAV-Anfrage eines angemeldeten Sync-Clients (Bearer-Token und
 * Sitzungs-Cookie), in einem eigenen Prozess ohne die Apps, die der Test-Bootstrap
 * lädt. Wartungsmodus oder ausstehendes Upgrade setzt der Aufrufer über
 * OC_-Umgebungsvariablen.
 *
 * Argumente: Installation, Name des Sitzungs-Cookies, Name des Passphrase-Cookies,
 * Sitzungsschlüssel der WebDAV-Anmeldung. Ausgabe: Ergebnis als JSON.
 */

if (\PHP_SAPI !== 'cli') {
	exit(1);
}

[, $serverRoot, $sessionName, $passphraseCookie, $davAuthenticatedKey] = $argv;
$sessionId = 'sid0123456789';
$passphrase = 'passphrase-for-tests';

\define('PHPUNIT_RUN', 1);
if (\getenv('CONFIG_DIR')) {
	\define('PHPUNIT_CONFIG_DIR', \getenv('CONFIG_DIR'));
}
$_SERVER['SCRIPT_FILENAME'] = $serverRoot . '/remote.php';
$_SERVER['SCRIPT_NAME'] = '/remote.php';
$_SERVER['REQUEST_METHOD'] = 'PROPFIND';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token-for-tests';
$_COOKIE = [$sessionName => $sessionId, $passphraseCookie => $passphrase];

require $serverRoot . '/lib/base.php';
require_once $serverRoot . '/tests/lib/Session/FakeSessionSaveHandler.php';

$result = ['davAppLoadable' => null, 'error' => null, 'userId' => null, 'lockReleased' => null];
try {
	$result['davAppLoadable'] = \class_exists('OCA\DAV\Connector\Sabre\Auth');
} catch (\Throwable $e) {
	$result['davAppLoadable'] = false;
}

$handler = new \Test\Session\FakeSessionSaveHandler();
$values = ['user_id' => 'alice', $davAuthenticatedKey => 'alice', 'LAST_ACTIVITY' => \time() - 10];
$encrypted = \OC::$server->getCrypto()->encrypt(\json_encode($values), $passphrase);
$handler->store[$sessionId] = 'encrypted_session_data|' . \serialize($encrypted);
\session_set_save_handler($handler, false);

try {
	\OC::initSession();
	$result['userId'] = \OC::$server->getSession()->get('user_id');
	$result['lockReleased'] = \session_status() === PHP_SESSION_NONE;
} catch (\Throwable $e) {
	$result['error'] = \get_class($e) . ': ' . $e->getMessage();
}

echo \json_encode($result);
