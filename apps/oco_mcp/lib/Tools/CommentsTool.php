<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Tools;

use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Files\IRootFolder;

/**
 * Comment tools: read and (when enabled) post comments on files.
 */
class CommentsTool {
	use WriteGuard;

	private ICommentsManager $commentsManager;
	private IRootFolder $rootFolder;
	private string $userId;

	public function __construct(
		ICommentsManager $commentsManager,
		IRootFolder $rootFolder,
		string $userId,
		bool $writeEnabled
	) {
		$this->commentsManager = $commentsManager;
		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
		$this->writeEnabled = $writeEnabled;
	}

	/**
	 * List comments on a file.
	 *
	 * @param string $path File path relative to the user's root.
	 * @param int $limit Maximum number of comments (default 50).
	 * @return array The comments, newest first.
	 */
	public function list(string $path, int $limit = 50): array {
		$fileId = (string)$this->fileId($path);
		$limit = \max(1, \min($limit, 200));
		$out = [];
		foreach ($this->commentsManager->getForObject('files', $fileId, $limit, 0) as $comment) {
			$out[] = $this->describe($comment);
		}
		return ['path' => $path, 'count' => \count($out), 'comments' => $out];
	}

	/**
	 * Post a comment on a file as the acting user. Requires write access.
	 *
	 * @param string $path File path relative to the user's root.
	 * @param string $message The comment text.
	 * @return array The created comment.
	 */
	public function add(string $path, string $message): array {
		$this->assertWrite();
		$fileId = (string)$this->fileId($path);
		$comment = $this->commentsManager->create('users', $this->userId, 'files', $fileId);
		$comment->setMessage($message);
		$comment->setVerb('comment');
		$this->commentsManager->save($comment);
		return $this->describe($comment);
	}

	private function fileId(string $path): int {
		return $this->rootFolder->getUserFolder($this->userId)->get('/' . \ltrim($path, '/'))->getId();
	}

	private function describe(IComment $comment): array {
		return [
			'id' => $comment->getId(),
			'message' => $comment->getMessage(),
			'actor' => $comment->getActorId(),
			'actor_type' => $comment->getActorType(),
			'verb' => $comment->getVerb(),
			'created' => $comment->getCreationDateTime()->format(\DATE_ATOM),
		];
	}
}
