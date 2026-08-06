<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\Share;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Share tools: list the acting user's shares and (when enabled) create public
 * links, share with other users, and remove shares.
 */
class SharesTool {
	use WriteGuard;

	private IShareManager $shareManager;
	private IRootFolder $rootFolder;
	private IURLGenerator $urlGenerator;
	private string $userId;

	private const TYPES = [
		Share::SHARE_TYPE_USER,
		Share::SHARE_TYPE_GROUP,
		Share::SHARE_TYPE_LINK,
	];

	public function __construct(
		IShareManager $shareManager,
		IRootFolder $rootFolder,
		IURLGenerator $urlGenerator,
		string $userId,
		bool $writeEnabled
	) {
		$this->shareManager = $shareManager;
		$this->rootFolder = $rootFolder;
		$this->urlGenerator = $urlGenerator;
		$this->userId = $userId;
		$this->writeEnabled = $writeEnabled;
	}

	/**
	 * List shares created by the acting user.
	 *
	 * @param string $path Optional path relative to the user's root to filter by (empty = all).
	 * @param int $limit Maximum number of shares to return (default 100).
	 * @return array The shares, each with id, type, target, permissions and (for links) a URL.
	 */
	public function list(string $path = '', int $limit = 100): array {
		$node = null;
		if ($path !== '') {
			$node = $this->node($path);
		}
		$limit = \max(1, \min($limit, 500));
		$shares = [];
		foreach (self::TYPES as $type) {
			$remaining = $limit - \count($shares);
			if ($remaining <= 0) {
				break;
			}
			foreach ($this->shareManager->getSharesBy($this->userId, $type, $node, false, $remaining, 0) as $share) {
				if (\count($shares) >= $limit) {
					break;
				}
				$shares[] = $this->describe($share);
			}
		}
		return ['count' => \count($shares), 'shares' => $shares];
	}

	/**
	 * Create a public link share for a file or folder. Requires write access.
	 *
	 * @param string $path Path relative to the user's root to share.
	 * @param string $password Optional password to protect the link (empty = none).
	 * @param int $permissions Permission bitmask (1=read, 15=read+write, 31=all). Default 1.
	 * @return array The created share including its public URL.
	 */
	public function createLink(string $path, string $password = '', int $permissions = Constants::PERMISSION_READ): array {
		$this->assertWrite();
		$node = $this->node($path);
		$share = $this->shareManager->newShare();
		$share->setNode($node)
			->setShareType(Share::SHARE_TYPE_LINK)
			->setSharedBy($this->userId)
			->setPermissions($permissions);
		if ($password !== '') {
			$share->setPassword($password);
		}
		return $this->describe($this->shareManager->createShare($share));
	}

	/**
	 * Share a file or folder with another ownCloud user. Requires write access.
	 *
	 * @param string $path Path relative to the user's root to share.
	 * @param string $share_with The user id of the recipient.
	 * @param int $permissions Permission bitmask (1=read, 15=read+write, 31=all). Default 31.
	 * @return array The created share.
	 */
	public function createUser(string $path, string $share_with, int $permissions = Constants::PERMISSION_ALL): array {
		$this->assertWrite();
		$node = $this->node($path);
		$share = $this->shareManager->newShare();
		$share->setNode($node)
			->setShareType(Share::SHARE_TYPE_USER)
			->setSharedBy($this->userId)
			->setSharedWith($share_with)
			->setPermissions($permissions);
		return $this->describe($this->shareManager->createShare($share));
	}

	/**
	 * Delete a share by its id. Requires write access.
	 *
	 * @param string $share_id The share id (as returned by shares_list).
	 * @return array A confirmation.
	 */
	public function delete(string $share_id): array {
		$this->assertWrite();
		$share = $this->shareManager->getShareById($share_id);
		if ($share->getSharedBy() !== $this->userId) {
			throw new ToolCallException('You can only delete shares you created.');
		}
		$this->shareManager->deleteShare($share);
		return ['deleted' => $share_id];
	}

	/**
	 * Resolve a client path through the shared strict gate (see PathHelper).
	 */
	private function node(string $path): Node {
		return PathHelper::node($this->rootFolder->getUserFolder($this->userId), $path);
	}

	private function describe(IShare $share): array {
		$out = [
			'id' => $share->getFullId(),
			'type' => $this->typeName($share->getShareType()),
			'path' => $share->getTarget(),
			'permissions' => $share->getPermissions(),
			'shared_with' => $share->getSharedWith(),
			'owner' => $share->getShareOwner(),
		];
		$token = $share->getToken();
		if ($token) {
			$out['url'] = $this->urlGenerator->linkToRouteAbsolute(
				'files_sharing.sharecontroller.showShare',
				['token' => $token]
			);
			$out['token'] = $token;
		}
		return $out;
	}

	private function typeName(int $type): string {
		switch ($type) {
			case Share::SHARE_TYPE_USER: return 'user';
			case Share::SHARE_TYPE_GROUP: return 'group';
			case Share::SHARE_TYPE_LINK: return 'link';
			default: return 'type-' . $type;
		}
	}
}
