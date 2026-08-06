<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;
use OCP\ILogger;

/**
 * Bridges the optional `ai_documents` app (RAG / semantic document search) into
 * MCP as a single "ask" tool.
 *
 * This tool is only registered when the ai_documents app is enabled, and it
 * references that app **only by string** (class_exists-guarded, resolved through
 * the app's own DI container) so oco_mcp never hard-depends on it — on an
 * instance without ai_documents the tool simply does not exist.
 *
 * The query runs as the current session user (the authenticated MCP user): the
 * ai_documents PromptService reads identity from IUserSession itself, which is
 * already this request's user.
 */
class AiDocumentsTool {
	private const APP_CLASS = '\\OCA\\AiDocuments\\AppInfo\\Application';
	private const SERVICE_CLASS = '\\OCA\\AiDocuments\\Service\\PromptService';
	private const MODES = ['qa', 'summary', 'extract', 'report'];
	private const SCOPES = ['all', 'folder', 'selection'];

	private ILogger $logger;

	public function __construct(ILogger $logger) {
		$this->logger = $logger;
	}

	/**
	 * Ask an AI question over the user's documents (retrieval-augmented generation).
	 *
	 * Uses the ai_documents app to embed the question, retrieve the most relevant
	 * indexed document chunks the user may read, and answer with citations.
	 *
	 * @param string $question The natural-language question to answer.
	 * @param string $scope What to search: "all" (whole account), "folder" (needs path), or "selection" (needs file_ids). Default "all".
	 * @param string $path Folder path relative to the user's root, required when scope is "folder".
	 * @param string $file_ids Comma-separated file IDs, required when scope is "selection".
	 * @param string $mode Answer style: "qa" (default), "summary", "extract" or "report".
	 * @return array The answer, its cited sources, and analysis coverage info.
	 */
	public function ask(string $question, string $scope = 'all', string $path = '', string $file_ids = '', string $mode = 'qa'): array {
		$question = \trim($question);
		if ($question === '') {
			throw new ToolCallException('The question must not be empty.');
		}
		$mode = \in_array($mode, self::MODES, true) ? $mode : 'qa';
		$scope = \in_array($scope, self::SCOPES, true) ? $scope : 'all';

		$scopePath = null;
		$resourceIds = null;
		if ($scope === 'folder') {
			if (\trim($path) === '') {
				throw new ToolCallException('scope "folder" requires a "path".');
			}
			$scopePath = PathHelper::clean($path);
		} elseif ($scope === 'selection') {
			$resourceIds = \array_values(\array_filter(\array_map(
				static fn ($id) => (int)\trim($id),
				\explode(',', $file_ids)
			), static fn ($id) => $id > 0));
			if ($resourceIds === []) {
				throw new ToolCallException('scope "selection" requires "file_ids" (comma-separated).');
			}
		}

		$prompt = $this->resolveService();
		try {
			$result = $prompt->query($question, $mode, $scope, $scopePath, $resourceIds);
		} catch (\Throwable $e) {
			// Rohe Backend-Meldungen koennen Gateway-URL/Host, DB-Fehler, interne
			// Dateipfade oder Provider-API-Texte enthalten. Der MCP-Client
			// bekommt deshalb nur eine generische Meldung; die vollstaendige
			// Exception steht im Serverprotokoll.
			$this->logger->logException($e, [
				'app' => 'oco_mcp',
				'message' => 'ai_documents query failed',
			]);
			throw new ToolCallException('AI query failed. Ask an administrator to check the server log.');
		}

		$analysis = $result['analysis'] ?? [];
		return [
			'answer' => (string)($result['response'] ?? ''),
			'sources' => $result['sources'] ?? [],
			'needs_analysis' => (bool)($analysis['needsAnalysis'] ?? false),
			'pending_files' => (int)($analysis['pendingCount'] ?? 0),
			'duration_ms' => $result['durationMs'] ?? null,
		];
	}

	/**
	 * Resolve ai_documents' PromptService through its own app container.
	 */
	private function resolveService(): object {
		if (!\class_exists(self::APP_CLASS) || !\class_exists(self::SERVICE_CLASS)) {
			throw new ToolCallException('The ai_documents app is not available on this server.');
		}
		$appClass = self::APP_CLASS;
		try {
			$app = new $appClass();
			$service = $app->getContainer()->query(self::SERVICE_CLASS);
		} catch (\Throwable $e) {
			// Siehe oben: Container-/Konfigurationsfehler nennen typischerweise
			// interne Pfade und Klassennamen — nur ins Log, nicht an den Client.
			$this->logger->logException($e, [
				'app' => 'oco_mcp',
				'message' => 'Could not initialise ai_documents',
			]);
			throw new ToolCallException('Could not initialise ai_documents. Ask an administrator to check the server log.');
		}
		if (!\is_object($service)) {
			throw new ToolCallException('ai_documents PromptService unavailable.');
		}
		return $service;
	}
}
