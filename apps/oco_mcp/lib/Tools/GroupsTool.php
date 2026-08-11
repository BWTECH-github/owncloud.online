<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 *
 * Modified by BW-Tech GmbH on 2026-07-06.
 * Changes:
 *   - add oco_mcp — Model Context Protocol server for AI assistants
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Group-management tools. Every tool requires the acting user to be an ownCloud
 * administrator; membership changes additionally require write access.
 */
class GroupsTool {
	use WriteGuard;

	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private bool $isAdmin;

	public function __construct(
		IGroupManager $groupManager,
		IUserManager $userManager,
		bool $isAdmin,
		bool $writeEnabled
	) {
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->isAdmin = $isAdmin;
		$this->writeEnabled = $writeEnabled;
	}

	/**
	 * List ownCloud groups (admin only).
	 *
	 * @param string $search Optional search term.
	 * @param int $limit Maximum number of groups to return (default 200).
	 * @return array The groups with id, display name and member count.
	 */
	public function list(string $search = '', int $limit = 200): array {
		$this->assertAdmin($this->isAdmin);
		$limit = \max(1, \min($limit, 1000));
		$out = [];
		foreach ($this->groupManager->search($search, $limit, 0) as $group) {
			// Hard cap: some group backends ignore the search limit, so enforce it here.
			if (\count($out) >= $limit) {
				break;
			}
			$out[] = [
				'id' => $group->getGID(),
				'display_name' => $group->getDisplayName(),
				'members' => $group->count(),
			];
		}
		return ['count' => \count($out), 'groups' => $out];
	}

	/**
	 * List the members of a group (admin only).
	 *
	 * @param string $group_id The group id.
	 * @return array The member user ids.
	 */
	public function members(string $group_id): array {
		$this->assertAdmin($this->isAdmin);
		$group = $this->requireGroup($group_id);
		$members = [];
		foreach ($group->getUsers() as $user) {
			$members[] = $user->getUID();
		}
		return ['group' => $group_id, 'count' => \count($members), 'members' => $members];
	}

	/**
	 * Add a user to a group (admin only, requires write access).
	 *
	 * @param string $group_id The group id.
	 * @param string $user_id The user id to add.
	 * @return array A confirmation.
	 */
	public function addMember(string $group_id, string $user_id): array {
		$this->assertAdmin($this->isAdmin);
		$this->assertWrite();
		$group = $this->requireGroup($group_id);
		$group->addUser($this->requireUser($user_id));
		return ['group' => $group_id, 'added' => $user_id];
	}

	/**
	 * Remove a user from a group (admin only, requires write access).
	 *
	 * @param string $group_id The group id.
	 * @param string $user_id The user id to remove.
	 * @return array A confirmation.
	 */
	public function removeMember(string $group_id, string $user_id): array {
		$this->assertAdmin($this->isAdmin);
		$this->assertWrite();
		$group = $this->requireGroup($group_id);
		$group->removeUser($this->requireUser($user_id));
		return ['group' => $group_id, 'removed' => $user_id];
	}

	private function requireGroup(string $gid): IGroup {
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			throw new ToolCallException('Group not found: ' . $gid);
		}
		return $group;
	}

	private function requireUser(string $uid): IUser {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			throw new ToolCallException('User not found: ' . $uid);
		}
		return $user;
	}
}
