<?php
/**
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
 * Modified by BW-Tech GmbH on 2026-07-20.
 * Changes:
 *   - pre-signed URL hardening + per-message salt in Crypto
 *   - PHP 8.4 compatibility and owncloud.online design integration
 */

namespace OC\Security\SignedUrl;

use OCP\IConfig;
use Sabre\HTTP\RequestInterface;

class Verifier {
	/**
	 * @var RequestInterface
	 */
	private $request;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var \DateTime|null
	 */
	private $now;

	public function __construct(RequestInterface $request, IConfig $config, ?\DateTime $now = null) {
		$this->request = $request;
		$this->config = $config;
		$this->now = $now ?? new \DateTime();
	}

	public function isSignedRequest(): bool {
		$params = $this->getQueryParameters();
		return isset($params['OC-Signature']);
	}

	public function signedRequestIsValid(): bool {
		$params = $this->getQueryParameters();
		if (!isset($params['OC-Signature'], $params['OC-Credential'], $params['OC-Date'], $params['OC-Expires'], $params['OC-Verb'])) {
			$q = \json_encode($params);
			\OC::$server->getLogger()->debug("Query parameters are missing: $q", ['app' => 'signed-url']);
			return false;
		}
		$urlSignature = $params['OC-Signature'];
		$urlCredential = $params['OC-Credential'];
		$urlDate = $params['OC-Date'];
		$urlExpires = $params['OC-Expires'];
		$urlVerb = \strtoupper($params['OC-Verb']);
		$algo = $params['OC-Algo'] ?? 'PBKDF2/10000-SHA512';

		unset($params['OC-Signature'], $params['OC-Algo']);

		$valid = $this->verifySignature($params, $urlCredential, $algo, $urlSignature);
		if (!$valid) {
			return false;
		}
		$verb = \strtoupper($this->getMethod());
		if ($verb !== $urlVerb) {
			\OC::$server->getLogger()->debug("OC-Verb does not match: $verb !== $urlVerb", ['app' => 'signed-url']);
			return false;
		}
		$date = new \DateTime($urlDate);
		$date->add(new \DateInterval("PT{$urlExpires}S"));
		if (!($date < $this->now)) {
			return true;
		}
		\OC::$server->getLogger()->debug("Signature expired: {$date->format(\DateTime::ATOM)} < {$this->now->format(\DateTime::ATOM)}", ['app' => 'signed-url']);
		return false;
	}

	private function getQueryParameters(): array {
		return $this->request->getQueryParameters();
	}

	public function getUrlCredential(): string {
		$params = $this->getQueryParameters();
		if (!isset($params['OC-Credential'])) {
			throw new \LogicException('OC-Credential not set');
		}

		return $params['OC-Credential'];
	}

	private function getAbsoluteUrl(): string {
		return $this->request->getAbsoluteUrl();
	}

	private function getMethod(): string {
		return $this->request->getMethod();
	}

	/**
	 * @param string $algo
	 * @param string $url
	 * @param $signingKey
	 * @return false|mixed|string
	 */
	protected function computeHash(string $algo, string $url, $signingKey) {
		if (\preg_match('/^(.*)\/(.*)-(.*)$/', $algo, $output)) {
			if ($output[1] !== 'PBKDF2') {
				return false;
			}
			if ($output[3] !== 'SHA512') {
				return false;
			}
			$iterations = (int)$output[2];
			if ($iterations <= 0) {
				return false;
			}
			return \hash_pbkdf2("sha512", $url, $signingKey, $iterations, 64, false);
		}
		return false;
	}

	/**
	 * @param array $params
	 * @param $urlCredential
	 * @param $algo
	 * @param $urlSignature
	 * @return bool
	 * @throws \Sabre\Uri\InvalidUriException
	 */
	private function verifySignature(array $params, $urlCredential, $algo, $urlSignature): bool {
		$trustedList = $this->config->getSystemValue('trusted_domains', []);
		$signingKey = $this->config->getUserValue($urlCredential, 'core', 'signing-key');
		// in case the signing key is not initialized, no signature can ever be verified
		if ($signingKey === '') {
			\OC::$server->getLogger()->error("No signing key available for the user $urlCredential. Access via pre-signed URL denied.", ['app' => 'signed-url']);
			return false;
		}
		$qp = \preg_replace('/%5B\d+%5D/', '%5B%5D', \http_build_query($params));

		foreach ($trustedList as $trustedDomain) {
			foreach (['https', 'http'] as $scheme) {
				$url = \Sabre\Uri\parse($this->getAbsoluteUrl());
				$url['scheme'] = $scheme;
				$url['host'] = $trustedDomain;
				$url['query'] = $qp;
				$url = \Sabre\Uri\build($url);

				$hash = $this->computeHash($algo, $url, $signingKey);
				// Constant-time comparison to avoid a timing side channel (CWE-208)
				// on the very check that is supposed to prevent forgeries.
				if (\is_string($hash) && \hash_equals($hash, $urlSignature)) {
					return true;
				}
				// Never log the raw signing key: it is the only trust anchor of the
				// pre-signed URL feature and would allow anyone with log access to
				// forge valid URLs. Log a non-reversible fingerprint instead.
				$keyFingerprint = \substr(\hash('sha256', (string)$signingKey), 0, 8);
				\OC::$server->getLogger()->debug("Signature does not match for url: $url (provided signature: $urlSignature, key fingerprint: $keyFingerprint)", ['app' => 'signed-url']);
			}
		}

		return false;
	}
}
