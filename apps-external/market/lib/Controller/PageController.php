<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH
 *
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
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

namespace OCA\Market\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;

class PageController extends Controller {
	/**
	 * @NoCSRFRequired
	 *
	 * Required for marketplace login to generate a callbackurl with hash sign,
	 * or else the login token will be attached before it resulting in a broken url.
	 */
	public function indexHash(): TemplateResponse {
		return $this->index();
	}

	/**
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse {
		$templateResponse = new TemplateResponse($this->appName, 'index', []);
		$policy = new ContentSecurityPolicy();
		// remote marketplace storage (only used when an external `appstoreurl` is configured)
		$policy->addAllowedImageDomain('https://marketplace-storage.owncloud.com');
		$policy->addAllowedImageDomain('https://marketplace-storage.staging.owncloud.services');
		// BW-Tech: allow images served alongside a static catalog from owncloud.online or GitHub
		$policy->addAllowedImageDomain('https://owncloud.online');
		// BW-Tech: owncloud.online marketplace serves app icons + screenshots under
		// marketplace.owncloud.online (covers any owncloud.online subdomain)
		$policy->addAllowedImageDomain('https://*.owncloud.online');
		$policy->addAllowedImageDomain('https://*.bw.tech');
		$policy->addAllowedImageDomain('https://raw.githubusercontent.com');
		$policy->addAllowedImageDomain('https://github.com');
		// local dev storage
		$policy->addAllowedImageDomain('http://minio:9000');
		$templateResponse->setContentSecurityPolicy($policy);

		return $templateResponse;
	}
}
