<?php
/**
 * @author Andreas Fischer <bantu@owncloud.com>
 * @author Christopher Schäpers <kondou@ts.unde.re>
 * @author Frank Karlitschek <frank@karlitschek.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Kristof Provost <github@sigsegv.be>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author martin.mattel@diemattels.at <martin.mattel@diemattels.at>
 * @author Masaki Kawabata Neto <masaki.kawabata@gmail.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Philipp Schaffrath <github@philippschaffrath.de>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
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
 * Modified by BW-Tech GmbH on 2026-07-02.
 * Changes:
 *   - cut per-request hot-path costs across session, cache, storage and status
 */

try {
	/**
	 * Fast path: status.php is polled by every desktop/mobile client on connection
	 * validation, yet the answer only changes on upgrades or maintenance toggles.
	 * Serve the last known-good answer from APCu — keyed on config.php/version.php
	 * mtimes, so occ upgrade (toggles maintenance in config.php) and code deploys
	 * invalidate immediately — and skip the full bootstrap. Only a "green" state
	 * (installed, no maintenance, no pending upgrade) is ever cached; anything else
	 * always takes the full path below so upgrade/maintenance semantics stay exact.
	 */
	if (PHP_SAPI !== 'cli' && \function_exists('apcu_fetch')) {
		$statusConfigFile = __DIR__ . '/config/config.php';
		$statusVersionFile = __DIR__ . '/version.php';
		if (@\is_file($statusConfigFile)) {
			$statusCacheKey = 'oco_status_' . \md5(
				__DIR__ . '|' . @\filemtime($statusConfigFile) . '|' . @\filesize($statusConfigFile) . '|' . @\filemtime($statusVersionFile)
			);
			$statusCached = \apcu_fetch($statusCacheKey, $statusCacheHit);
			if ($statusCacheHit && \is_string($statusCached)) {
				\header('Access-Control-Allow-Origin: *');
				\header('Content-Type: application/json');
				echo $statusCached;
				return;
			}
		}
	}

	require_once __DIR__ . '/lib/base.php';

	# show the version details based on config.php parameter,
	# but do not expose the servername in the public via url
	$values = \OCP\Util::getStatusInfo(
		null,
		\OC::$server->getConfig()->getSystemValue('show_server_hostname', false) !== true,
		\OC::$server->getConfig()->getSystemValue('use_relative_domain_name', false) === true
	);

	if (OC::$CLI) {
		\print_r($values);
	} else {
		\header('Access-Control-Allow-Origin: *');
		\header('Content-Type: application/json');
		$statusBody = \json_encode($values);
		if (isset($statusCacheKey)
			&& $values['installed'] === true
			&& $values['maintenance'] === false
			&& $values['needsDbUpgrade'] === false
		) {
			// short TTL as safety net for app-only upgrades that do not touch config.php
			\apcu_store($statusCacheKey, $statusBody, 90);
		}
		echo $statusBody;
	}
} catch (\Throwable $ex) {
	try {
		OC_Response::setStatus(OC_Response::STATUS_INTERNAL_SERVER_ERROR);
		\OCP\Util::writeLog('remote', $ex->getMessage(), \OCP\Util::FATAL);
	} catch (\Throwable $ex2) {
		// log through the crashLog
		\header("{$_SERVER['SERVER_PROTOCOL']} 599 Broken");
		\OC::crashLog($ex);
		\OC::crashLog($ex2);
	}
}
