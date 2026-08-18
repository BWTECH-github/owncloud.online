<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 *
 * Modified by BW-Tech GmbH on 2026-08-06.
 * Changes:
 *   - write access needs an explicit write_groups list (empty = nobody)
 *   - close the open items from the v11.0.0 security delta
 *   - throttle password spraying per-IP, reset delay on successful auth
 *   - throttle failed MCP logins + optional write_groups allowlist
 */
namespace OCA\OcoMcp\Controller;

use OCA\OcoMcp\Mcp\ServerFactory;
use OCA\OcoMcp\Security\BasicAuthCredentials;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Bridges an owncloud.online request to the MCP SDK's Streamable HTTP transport.
 *
 * The bundled MCP SDK (and its PSR-7 glue) is loaded lazily here — never in
 * Application.php — so only requests that actually hit /apps/oco_mcp/mcp pull in
 * the app's vendor/ autoloader. That keeps the app from ever shadowing a
 * core-provided library on unrelated requests.
 */
class McpController extends Controller {
	private const MAX_REQUEST_BYTES = 2 * 1048576;

	/** Mindestabstand zwischen zwei Hinweisen auf fehlende write_groups */
	private const WRITE_WARNING_INTERVAL = 86400;

	private IUserSession $userSession;
	private IGroupManager $groupManager;
	private ServerFactory $serverFactory;
	private IConfig $config;
	private ILogger $logger;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		IGroupManager $groupManager,
		ServerFactory $serverFactory,
		IConfig $config,
		ILogger $logger
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->serverFactory = $serverFactory;
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * The single MCP endpoint (POST = JSON-RPC, GET = SSE, DELETE = end session).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * CSRF exemption is safe because we refuse plain browser-cookie sessions:
	 * an MCP call must carry valid HTTP Basic credentials whose password is an
	 * owncloud.online app/device token, which a cross-site page cannot forge.
	 */
	public function handle(): DataDisplayResponse {
		// Do not trust a pre-existing browser session. Validate the credentials from
		// this request again, otherwise a cookie plus any made-up Authorization
		// header would satisfy the old presence-only check.
		$authHeader = (string)$this->request->getHeader('Authorization');
		$credentials = BasicAuthCredentials::parse($authHeader);
		if ($credentials === null) {
			return $this->error(
				Http::STATUS_UNAUTHORIZED,
				-32001,
				'MCP requires HTTP Basic authentication with an owncloud.online app token.'
			);
		}

		[$login, $secret] = $credentials;

		// Token logins bypass User\Manager::checkPassword, so this endpoint must
		// hook into the DB-backed throttle itself: sleep on the accumulated failed
		// attempts BEFORE validating the secret, and register every failed guess
		// below. Requests without any Basic credentials (first 401 above) are the
		// normal HTTP auth handshake and are deliberately not counted.
		$ip = $this->request->getRemoteAddress();
		/** @var \OCO\Security\Bruteforce\Throttler $throttler */
		$throttler = \OC::$server->query(\OCO\Security\Bruteforce\Throttler::class);
		$throttler->sleepDelay('oco_mcp', $ip, $login);

		// MCP is a non-interactive client. Require a revocable app/device token so
		// the account password and two-factor policy can never be bypassed here.
		$isTokenPassword = \is_callable([$this->userSession, 'isTokenPassword'])
			&& (bool)\call_user_func([$this->userSession, 'isTokenPassword'], $secret);
		if (!$isTokenPassword) {
			$this->registerFailedLogin($throttler, $ip, $login);
			return $this->error(Http::STATUS_UNAUTHORIZED, -32001, 'Invalid MCP credentials.');
		}

		try {
			$authenticated = $this->userSession->login($login, $secret);
		} catch (\Throwable $e) {
			$authenticated = false;
		}
		$user = $authenticated ? $this->userSession->getUser() : null;
		if ($user === null) {
			$this->registerFailedLogin($throttler, $ip, $login);
			return $this->error(Http::STATUS_UNAUTHORIZED, -32001, 'Invalid MCP credentials.');
		}
		$throttler->resetDelay('oco_mcp', $ip, $login);

		// Lazy-load the bundled MCP SDK only for this endpoint.
		require_once __DIR__ . '/../../vendor/autoload.php';
		$contentLength = (int)($this->request->getHeader('Content-Length') ?? 0);
		if ($contentLength > self::MAX_REQUEST_BYTES) {
			return $this->error(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, -32600, 'MCP request body is too large.');
		}

		// Gebounded lesen: hoechstens MAX+1 Bytes in den Speicher holen. So kann ein
		// Client ohne (oder mit gefaelschtem) Content-Length — etwa via chunked
		// transfer-encoding — den Speicher nicht mit einem riesigen Body erschoepfen.
		$stream = \fopen('php://input', 'rb');
		$rawBody = $stream !== false ? (string)\stream_get_contents($stream, self::MAX_REQUEST_BYTES + 1) : '';
		if ($stream !== false) {
			\fclose($stream);
		}
		if (\strlen($rawBody) > self::MAX_REQUEST_BYTES) {
			return $this->error(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, -32600, 'MCP request body is too large.');
		}

		$isAdmin = $this->groupManager->isAdmin($user->getUID());
		$writeEnabled = $this->config->getAppValue('oco_mcp', 'enable_write', 'no') === 'yes'
			&& $this->userHasWriteAccess($user->getUID());

		try {
			$server = $this->serverFactory->build($user, $isAdmin, $writeEnabled);

			$factory = new \GuzzleHttp\Psr7\HttpFactory();
			$psrRequest = $this->buildPsrRequest($rawBody);

			$middleware = [
				new \Mcp\Server\Transport\Http\Middleware\CorsMiddleware(),
				new \Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware(
					$this->allowedHosts(),
					$factory,
					$factory
				),
				new \Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware(null, $factory, $factory),
			];
			$transport = new \Mcp\Server\Transport\StreamableHttpTransport(
				$psrRequest,
				$factory,
				$factory,
				new \Psr\Log\NullLogger(),
				$middleware
			);

			/** @var \Psr\Http\Message\ResponseInterface $psrResponse */
			$psrResponse = $server->run($transport);
		} catch (\Throwable $e) {
			$this->logger->logException($e, ['app' => 'oco_mcp']);
			return $this->error(Http::STATUS_INTERNAL_SERVER_ERROR, -32603, 'Internal MCP error.');
		}

		$response = new DataDisplayResponse(
			$this->negotiateProtocolVersion((string)$psrResponse->getBody(), $rawBody),
			$psrResponse->getStatusCode(),
			[]
		);
		foreach ($psrResponse->getHeaders() as $name => $values) {
			$response->addHeader($name, \implode(', ', $values));
		}
		return $response;
	}

	/**
	 * Build a PSR-7 ServerRequest (via core's Guzzle psr7) from the owncloud.online request.
	 */
	private function buildPsrRequest(string $rawBody): \Psr\Http\Message\ServerRequestInterface {
		$headers = [];
		foreach (['Content-Type', 'Accept', 'Mcp-Session-Id', 'Mcp-Protocol-Version', 'Origin', 'Host'] as $h) {
			$v = $this->request->getHeader($h);
			if ($v !== '' && $v !== null) {
				$headers[$h] = $v;
			}
		}
		if (!isset($headers['Content-Type'])) {
			$headers['Content-Type'] = 'application/json';
		}
		if (!isset($headers['Accept'])) {
			$headers['Accept'] = 'application/json, text/event-stream';
		}

		return new \GuzzleHttp\Psr7\ServerRequest(
			$this->request->getMethod(),
			$this->request->getRequestUri() ?: '/apps/oco_mcp/mcp',
			$headers,
			$rawBody
		);
	}

	/**
	 * owncloud.online validates trusted_domains before this controller runs. Reuse the
	 * same hosts for the SDK's DNS-rebinding middleware.
	 *
	 * @return string[]
	 */
	private function allowedHosts(): array {
		$hosts = ['localhost', '127.0.0.1', '[::1]'];
		$trusted = $this->config->getSystemValue('trusted_domains', []);
		if (!\is_array($trusted)) {
			$trusted = [];
		}
		// NUR die vom Administrator konfigurierten Hosts erlauben. Der rohe
		// Host-Header darf hier NICHT einfliessen — sonst wuerde sich jeder
		// Angreifer-Host selbst freischalten und der DNS-Rebinding-Schutz waere
		// wirkungslos.
		$trusted[] = (string)$this->config->getSystemValue('overwritehost', '');

		foreach ($trusted as $candidate) {
			$candidate = \trim((string)$candidate);
			if ($candidate === '' || \str_contains($candidate, '*')) {
				continue;
			}
			$host = \parse_url('http://' . $candidate, PHP_URL_HOST);
			if (\is_string($host) && $host !== '') {
				$hosts[] = \str_contains($host, ':') ? '[' . \trim($host, '[]') . ']' : $host;
			}
		}

		return \array_values(\array_unique(\array_map('strtolower', $hosts)));
	}

	/**
	 * Write access requires BOTH "enable_write" and membership in one of the
	 * groups listed in "write_groups" (comma-separated group IDs). An empty
	 * or unset "write_groups" grants write access to nobody.
	 */
	private function userHasWriteAccess(string $uid): bool {
		$raw = \trim($this->config->getAppValue('oco_mcp', 'write_groups', ''));
		if ($raw === '') {
			// Frueher hiess "leer" hier: instanzweit erlaubt. Damit bekam jedes
			// App- oder Geraete-Token eines beliebigen Kontos die Schreib-Tools,
			// jedes Admin-Token zusaetzlich die Benutzer- und Gruppenverwaltung
			// - ein einziges abhanden gekommenes Token genuegte. Eine
			// Konfiguration, die niemanden nennt, darf niemandem etwas
			// erlauben.
			//
			// Das kann eine bestehende Instanz treffen, die mit enable_write
			// und ohne write_groups laeuft: dort sind die Schreib-Tools ab
			// sofort aus, bis eine Gruppe eingetragen ist. Genau darauf weist
			// die Meldung hin.
			$this->warnAboutMissingWriteGroups();
			return false;
		}
		foreach (\explode(',', $raw) as $gid) {
			$gid = \trim($gid);
			if ($gid !== '' && $this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hinweis auf fehlende write_groups, hoechstens einmal am Tag.
	 *
	 * userHasWriteAccess() laeuft in jedem MCP-Request. Ungebremst schriebe die
	 * Meldung eine Zeile pro Anfrage ins Log und verdeckte echte Meldungen. Der
	 * Zeitstempel liegt im AppConfig, damit die Drosselung auch ueber mehrere
	 * Web-Prozesse hinweg gilt; geschrieben wird nur, wenn wirklich geloggt wird.
	 */
	private function warnAboutMissingWriteGroups(): void {
		$now = \time();
		$last = (int)$this->config->getAppValue('oco_mcp', 'write_warning_logged_at', '0');
		// Bei zurueckgedrehter Uhr (Zeitumstellung, NTP-Korrektur) sofort wieder
		// warnen, statt bis zum Einholen des Zukunftswerts zu schweigen.
		if ($last <= $now && ($now - $last) < self::WRITE_WARNING_INTERVAL) {
			return;
		}

		$this->config->setAppValue('oco_mcp', 'write_warning_logged_at', (string)$now);
		$this->logger->warning(
			'MCP write tools are unavailable: "enable_write" is set but "write_groups" is empty, '
			. 'and an empty group list grants write access to nobody. '
			. 'Name the groups that may write: occ config:app:set oco_mcp write_groups --value=<group>',
			['app' => 'oco_mcp']
		);
	}

	/**
	 * Same wording as User\Manager ("Login failed: ...") so existing fail2ban
	 * filters match MCP credential guessing too.
	 */
	private function registerFailedLogin(\OCO\Security\Bruteforce\Throttler $throttler, string $ip, string $login): void {
		$throttler->registerAttempt('oco_mcp', $ip, $login);
		$this->logger->warning('MCP login failed: \'' . $login . '\' (Remote IP: \'' . $ip . '\')', ['app' => 'oco_mcp']);
	}

	/**
	 * Antwortet auf 'initialize' mit der Protokollversion, die der Client
	 * angefragt hat, sofern wir sie sprechen.
	 *
	 * Das SDK handelt nicht aus: Mcp\Server\Handler\Request\InitializeHandler
	 * gibt unveraendert die eigene konfigurierte Version zurueck und ignoriert
	 * $request->protocolVersion. Seit dem SDK-Sprung auf '2025-11-25' bekam damit
	 * jeder Client diese Zahl - auch einer, der '2024-11-05' angefragt hatte.
	 * Uebliche Clients verwerfen eine Antwort mit einer Version, die sie nicht
	 * kennen; mcp-remote und darueber Claude Desktop liefen deshalb in ihr
	 * 60-Sekunden-Zeitlimit statt sich zu verbinden.
	 *
	 * Korrigiert wird hier statt im Handler, weil dessen ServerCapabilities erst
	 * in Builder::build() entstehen und von aussen nicht erreichbar sind - ein
	 * eigener Handler muesste sie nachbauen und liefe bei jedem SDK-Update
	 * auseinander. Unterstuetzt wird, was der Aufzaehlungstyp des SDK kennt; eine
	 * unbekannte Wunschversion bleibt unangetastet, dann entscheidet der Client.
	 *
	 * @param string $body Antwort des SDK
	 * @param string $rawBody Angefragter Rumpf, um Methode und Wunschversion zu lesen
	 * @return string
	 */
	private function negotiateProtocolVersion(string $body, string $rawBody): string {
		try {
			$anfrage = \json_decode($rawBody, true);
			// Stapelanfragen (JSON-Array) lassen wir unberuehrt - dort steckt
			// initialize ohnehin nicht drin.
			if (!\is_array($anfrage) || ($anfrage['method'] ?? null) !== 'initialize') {
				return $body;
			}
			$wunsch = $anfrage['params']['protocolVersion'] ?? null;
			if (!\is_string($wunsch) || $wunsch === '') {
				return $body;
			}
			if (\Mcp\Schema\Enum\ProtocolVersion::tryFrom($wunsch) === null) {
				return $body;
			}

			$antwort = \json_decode($body, true);
			if (!\is_array($antwort)
				|| !isset($antwort['result']['protocolVersion'])
				|| $antwort['result']['protocolVersion'] === $wunsch) {
				return $body;
			}
			$antwort['result']['protocolVersion'] = $wunsch;

			$neu = \json_encode($antwort);
			return $neu === false ? $body : $neu;
		} catch (\Throwable $e) {
			// Eine Anmeldung darf nicht daran scheitern, dass sich hier etwas
			// nicht lesen laesst - dann bleibt die Antwort des SDK stehen.
			return $body;
		}
	}

	private function error(int $httpStatus, int $rpcCode, string $message): DataDisplayResponse {
		$body = \json_encode([
			'jsonrpc' => '2.0',
			'error' => ['code' => $rpcCode, 'message' => $message],
			'id' => null,
		]);
		$response = new DataDisplayResponse($body, $httpStatus, []);
		$response->addHeader('Content-Type', 'application/json');
		return $response;
	}
}
