<?php
/**
 * @author Brice Maron <brice@bmaron.net>
 * @author Christopher Schäpers <kondou@ts.unde.re>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Philipp Schaffrath <github@philippschaffrath.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-08-05.
 * Changes: fix(security): Information disclosure via the WebDAV error path
 * Changes: refactor(security): Null check does not match the actual return value (minor robustness issue)
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
 */

use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\DAV\Server;

/**
 * Class RemoteException
 * Dummy exception class to be use locally to identify certain conditions
 * Will not be logged to avoid DoS
 */
class RemoteException extends Exception {
}

/**
 * @param Exception | Error $e
 */
function handleException($e) {
	$request = \OC::$server->getRequest();
	// in case the request content type is text/xml - we assume it's a WebDAV request
	$isXmlContentType = \strpos($request->getHeader('Content-Type') ?? '', 'text/xml');
	if ($isXmlContentType === 0) {
		// fire up a simple server to properly process the exception
		$server = new Server();
		if (!($e instanceof RemoteException)) {
			// Die eigentliche Ausnahme protokollieren. Der Client bekommt unten nur eine
			// allgemeine 503-Antwort; bisher landete auch im Protokoll nur diese Hülle
			// („Internal Server Error“), die Ursache – etwa ein Datenbank- oder Sperrfehler
			// unter Last – blieb unsichtbar. RemoteException wird weiter nicht geloggt.
			\OC::$server->getLogger()->logException($e, ['app' => 'remote']);
		}
		$server->on('beforeMethod:*', function () use ($e) {
			if ($e instanceof RemoteException) {
				switch ($e->getCode()) {
					case OC_Response::STATUS_SERVICE_UNAVAILABLE:
						throw new ServiceUnavailable($e->getMessage());
					case OC_Response::STATUS_NOT_FOUND:
						throw new \Sabre\DAV\Exception\NotFound($e->getMessage());
				}
			}
			if (\OC::$server->getSystemConfig()->getValue('debug', false)) {
				$class = \get_class($e);
				$msg = \OC\Log::replaceSensitiveData($e->getMessage());
				throw new ServiceUnavailable("$class: $msg");
			}
			throw new ServiceUnavailable('Internal Server Error');
		});
		$server->exec();
	} else {
		$statusCode = OC_Response::STATUS_INTERNAL_SERVER_ERROR;
		if ($e instanceof \OC\ServiceUnavailableException) {
			$statusCode = OC_Response::STATUS_SERVICE_UNAVAILABLE;
		}
		if ($e instanceof RemoteException) {
			// we shall not log on RemoteException
			OC_Response::setStatus($e->getCode());
			OC_Template::printErrorPage($e->getMessage());
		} else {
			\OC::$server->getLogger()->logException($e, ['app' => 'remote']);
			OC_Response::setStatus($statusCode);
			OC_Template::printExceptionErrorPage($e);
		}
	}
}

/**
 * @return array
 */
function getHardcodedServices() {
	return [
		'webdav' => 'dav/appinfo/v1/webdav.php',
		'dav' => 'dav/appinfo/v2/remote.php',
		'caldav' => 'dav/appinfo/v1/caldav.php',
		'calendar' => 'dav/appinfo/v1/caldav.php',
		'carddav' => 'dav/appinfo/v1/carddav.php',
		'contacts' => 'dav/appinfo/v1/carddav.php',
		'files' => 'dav/appinfo/v1/webdav.php',
	];
}

/**
 * @param $service
 * @return string
 */
function resolveService($service) {
	$services = getHardcodedServices();
	if (isset($services[$service])) {
		return $services[$service];
	}

	return \OC::$server->getConfig()->getAppValue('core', 'remote_' . $service);
}

try {
	require_once __DIR__ . '/lib/base.php';

	stream_wrapper_unregister('phar'); // disable phar wrapper
	// All resources served via the DAV endpoint should have the strictest possible
	// policy. Exempted from this is the SabreDAV browser plugin which overwrites
	// this policy with a softer one if debug mode is enabled.
	\header("Content-Security-Policy: default-src 'none';");

	if (\OCP\Util::needUpgrade()) {
		// since the behavior of apps or remotes are unpredictable during
		// an upgrade, return a 503 directly
		throw new RemoteException('Service unavailable', OC_Response::STATUS_SERVICE_UNAVAILABLE);
	}

	$request = \OC::$server->getRequest();
	$pathInfo = $request->getPathInfo();
	if ($pathInfo === false || $pathInfo === '' || $pathInfo === null) {
		throw new RemoteException('Path not found', OC_Response::STATUS_NOT_FOUND);
	}
	if (!$pos = \strpos($pathInfo, '/', 1)) {
		$pos = \strlen($pathInfo);
	}
	$service=\substr($pathInfo, 1, $pos-1);

	$file = resolveService($service);

	// Fuer einen unbekannten Dienst liefert resolveService() den Standardwert von
	// getAppValue(), also '' - nicht null. Die frueher hier stehende Pruefung auf
	// null konnte deshalb nie greifen; ohne sie liefe der Request bis
	// isInstalled('') weiter und endete in einer RemoteException ohne Code, was
	// OC_Response::setStatus(0) und damit ein ungueltiges 'HTTP/1.1 0' erzeugt.
	if ($file === '') {
		throw new RemoteException('Path not found', OC_Response::STATUS_NOT_FOUND);
	}

	// every RemoteException needs an explicit status: getCode() is 0 by default, and
	// OC_Response::setStatus(0) emits a bogus "HTTP/1.1 0" that caches, monitoring
	// and sync clients read as a success. 503 rather than 404 for the refusals
	// below, because that is what handleException() already answered on a WebDAV
	// request (code 0 matches no case in its switch and falls through to
	// ServiceUnavailable) - a sync client must not be told the root is gone.
	if (\strpos($file, '../') !== false || \strpos($file, '/..') !== false) {
		throw new RemoteException('Path not allowed', OC_Response::STATUS_SERVICE_UNAVAILABLE);
	}

	// force language as given in the http request
	\OC::$server->getL10NFactory()->setLanguageFromRequest();

	$file=\ltrim($file, '/');

	$parts=\explode('/', $file, 2);
	$app=$parts[0];

	// Load all required applications
	\OC::$REQUESTEDAPP = $app;
	OC_App::loadApps(['authentication']);
	OC_Util::tearDownFS();  // FS might have been prematurely initialized
	OC_App::loadApps(['filesystem', 'logging']);

	switch ($app) {
		// this branch is the one require_once site without a containment check, and
		// is unreachable by contradiction: getting here needs $file to start with
		// "core/", which only a stored remote_<service> value does, which in turn
		// means $service was not hardcoded - and that is exactly what the in_array()
		// below rejects. Should the hardcoded list ever gain a "core/…" entry, the
		// path would become attacker-influenced and needs getServiceHandlerPath()
		// (or an equivalent containment check against OC::$SERVERROOT) here.
		case 'core':
			if (!\in_array($service, \array_keys(getHardcodedServices()), true)) {
				throw new RemoteException('Service not allowed', OC_Response::STATUS_SERVICE_UNAVAILABLE);
			}
			$file =  OC::$SERVERROOT .'/'. $file;
			break;
		default:
			if (!\OC::$server->getAppManager()->isInstalled($app)) {
				throw new RemoteException('App not installed: ' . $app, OC_Response::STATUS_SERVICE_UNAVAILABLE);
			}
			OC_App::loadApp($app);
			// Only ever include a file which actually resolves inside the app's own
			// directory. Concatenating getAppPath() unchecked would include an
			// absolute path whenever the app has no directory on disk, because
			// getAppPath() returns false there and false . '/' is '/'.
			$file = OC_App::getServiceHandlerPath($app, $parts[1] ?? '');
			if ($file === false) {
				throw new RemoteException('Path not allowed', OC_Response::STATUS_SERVICE_UNAVAILABLE);
			}
			break;
	}
	$baseuri = OC::$WEBROOT . '/remote.php/'.$service.'/';
	require_once $file;
} catch (\Throwable $ex) {
	try {
		handleException($ex);
	} catch (\Throwable $ex2) {
		// log through the crashLog
		\header("{$_SERVER['SERVER_PROTOCOL']} 599 Broken");
		\OC::crashLog($ex);
		\OC::crashLog($ex2);
	}
}
