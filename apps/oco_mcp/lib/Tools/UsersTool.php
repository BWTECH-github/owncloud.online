<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;
use OCP\IUser;
use OCP\IUserManager;

/**
 * User-management tools. Every tool requires the acting user to be an ownCloud
 * administrator; write tools additionally require write access to be enabled.
 */
class UsersTool {
	use WriteGuard;

	private IUserManager $userManager;
	private bool $isAdmin;

	public function __construct(IUserManager $userManager, bool $isAdmin, bool $writeEnabled) {
		$this->userManager = $userManager;
		$this->isAdmin = $isAdmin;
		$this->writeEnabled = $writeEnabled;
	}

	/**
	 * List ownCloud users (admin only).
	 *
	 * @param int $limit Maximum number of users (default 100).
	 * @param string $search Optional search term for the user id / display name.
	 * @return array The users.
	 */
	public function list(int $limit = 100, string $search = ''): array {
		$this->assertAdmin($this->isAdmin);
		$limit = \max(1, \min($limit, 500));
		$out = [];
		foreach ($this->userManager->search($search, $limit, 0) as $user) {
			// Hard cap: some user backends ignore the search limit, so enforce it here.
			if (\count($out) >= $limit) {
				break;
			}
			$out[] = $this->describe($user);
		}
		return ['count' => \count($out), 'users' => $out];
	}

	/**
	 * Get details about a single user (admin only).
	 *
	 * @param string $user_id The user id.
	 * @return array The user details.
	 */
	public function get(string $user_id): array {
		$this->assertAdmin($this->isAdmin);
		return $this->describe($this->requireUser($user_id));
	}

	/**
	 * Create a new user (admin only, requires write access).
	 *
	 * @param string $user_id The new user id.
	 * @param string $password The initial password.
	 * @return array The created user.
	 */
	public function create(string $user_id, string $password): array {
		$this->assertAdmin($this->isAdmin);
		$this->assertWrite();
		if ($this->userManager->userExists($user_id)) {
			throw new ToolCallException('User already exists: ' . $user_id);
		}
		$user = $this->userManager->createUser($user_id, $password);
		if ($user === false) {
			throw new ToolCallException('Failed to create user (check password policy / backend).');
		}
		return $this->describe($user);
	}

	/**
	 * Disable a user, blocking login (admin only, requires write access).
	 *
	 * @param string $user_id The user id.
	 * @return array The updated user.
	 */
	public function disable(string $user_id): array {
		$this->assertAdmin($this->isAdmin);
		$this->assertWrite();
		$user = $this->requireUser($user_id);
		$user->setEnabled(false);
		return $this->describe($user);
	}

	/**
	 * Enable a previously disabled user (admin only, requires write access).
	 *
	 * @param string $user_id The user id.
	 * @return array The updated user.
	 */
	public function enable(string $user_id): array {
		$this->assertAdmin($this->isAdmin);
		$this->assertWrite();
		$user = $this->requireUser($user_id);
		$user->setEnabled(true);
		return $this->describe($user);
	}

	/**
	 * Set a user's storage quota (admin only, requires write access).
	 *
	 * @param string $user_id The user id.
	 * @param string $quota Quota string, e.g. "5 GB", "none" for unlimited, or "default".
	 * @return array The updated user.
	 */
	public function setQuota(string $user_id, string $quota): array {
		$this->assertAdmin($this->isAdmin);
		$this->assertWrite();
		$user = $this->requireUser($user_id);
		$user->setQuota($quota);
		return $this->describe($user);
	}

	private function requireUser(string $uid): IUser {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new ToolCallException('User not found: ' . $uid);
		}
		return $user;
	}

	private function describe(IUser $user): array {
		return [
			'id' => $user->getUID(),
			'display_name' => $user->getDisplayName(),
			'email' => $user->getEMailAddress(),
			'enabled' => $user->isEnabled(),
			'quota' => $user->getQuota(),
			'last_login' => $user->getLastLogin(),
			'backend' => $user->getBackendClassName(),
		];
	}
}
