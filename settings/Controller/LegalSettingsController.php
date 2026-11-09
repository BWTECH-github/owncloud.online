<?php
/**
 * @author Viktar Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
 * Modified by BW-Tech GmbH on 2026-11-09.
 * Changes:
 *   - only store http/https imprint and privacy URLs
 */

namespace OC\Settings\Controller;

use \OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IConfig;

/**
 * @package OC\Settings\Controller
 */
class LegalSettingsController extends Controller {
	/**
	 * @var \OCP\IL10N
	 */
	private $l10n;

	/**
	 * @var \OCP\IConfig
	 */
	private $config;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IL10N $l10n
	 * @param IConfig $config
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IL10N $l10n,
		IConfig $config
	) {
		parent::__construct($appName, $request);
		$this->l10n = $l10n;
		$this->config = $config;
	}

	/**
	 * Store imprint URL
	 *
	 * @param string $imprintUrl
	 *
	 * @return array
	 */
	public function setImprintUrl($imprintUrl) {
		return $this->storeLegalUrl('legal.imprint_url', $imprintUrl);
	}

	/**
	 * Store privacy policy URL
	 *
	 * @param string $privacyPolicy
	 *
	 * @return array
	 */
	public function setPrivacyPolicyUrl($privacyPolicy) {
		return $this->storeLegalUrl('legal.privacy_policy_url', $privacyPolicy);
	}

	/**
	 * Speichert eine Impressums- oder Datenschutzadresse. Leer heißt „kein
	 * Link“; alles andere muss eine http- oder https-Adresse sein. Die Adresse
	 * landet als Link in der Fußzeile der Anmeldeseite - „javascript:“ oder ein
	 * Anführungszeichen dort wäre Skript im Namen der Plattform.
	 *
	 * @param string $key
	 * @param mixed $url
	 * @return array
	 */
	private function storeLegalUrl($key, $url) {
		$url = \is_string($url) ? \trim($url) : '';
		if ($url !== '' && \OC_Defaults::sanitizeLegalUrl($url) === '') {
			return [
				'data' =>
					[
						// Vorhandener, schon übersetzter Text (wie CorsController).
						'message' => (string) $this->l10n->t("Invalid url '%s'. Urls should be set up like 'http://www.example.com' or 'https://www.example.com'", [\strip_tags($url)])
					],
				'status' => 'error'
			];
		}

		$this->config->setAppValue('core', $key, $url);

		return [
			'data' =>
				[
					'message' => (string) $this->l10n->t('Saved')
				],
			'status' => 'success'
		];
	}
}
