<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Controller;

use OCA\OcoMcp\Mcp\ServerFactory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Bridges an ownCloud request to the MCP SDK's Streamable HTTP transport.
 *
 * The bundled MCP SDK (and its PSR-7 glue) is loaded lazily here — never in
 * Application.php — so only requests that actually hit /apps/oco_mcp/mcp pull in
 * the app's vendor/ autoloader. That keeps the app from ever shadowing a
 * core-provided library on unrelated requests.
 */
class McpController extends Controller {
	private const MAX_REQUEST_BYTES = 2 * 1048576;

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
	 * an MCP call must carry an Authorization header (ownCloud app/device token
	 * or Basic auth), which a cross-site page cannot forge.
	 */
	public function handle(): DataDisplayResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(Http::STATUS_UNAUTHORIZED, -32001, 'Authentication required.');
		}

		// Reject cookie-only (CSRF-forgeable) sessions: require token/basic auth.
		$authHeader = $this->request->getHeader('Authorization');
		if ($authHeader === '' || $authHeader === null) {
			return $this->error(
				Http::STATUS_UNAUTHORIZED,
				-32001,
				'MCP requires an app token or Basic auth (Authorization header), not a browser session.'
			);
		}

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
		$writeEnabled = $this->config->getAppValue('oco_mcp', 'enable_write', 'no') === 'yes';

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
			(string)$psrResponse->getBody(),
			$psrResponse->getStatusCode(),
			[]
		);
		foreach ($psrResponse->getHeaders() as $name => $values) {
			$response->addHeader($name, \implode(', ', $values));
		}
		return $response;
	}

	/**
	 * Build a PSR-7 ServerRequest (via core's Guzzle psr7) from the ownCloud request.
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
	 * ownCloud validates trusted_domains before this controller runs. Reuse the
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
