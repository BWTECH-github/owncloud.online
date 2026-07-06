<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * System-tag tools: list tags and (when enabled) assign/remove them on files.
 *
 * Every operation enforces the same tag permission model as the WebDAV/UI layer:
 * only admins may create new system tags, a user only sees tags they are allowed
 * to see, and a user may only assign/remove tags they are allowed to assign
 * (`canUserSeeTag` / `canUserAssignTag`). The object mapper itself performs no
 * permission checks, so these guards must live here.
 */
class TagsTool {
	use WriteGuard;

	private ISystemTagManager $tagManager;
	private ISystemTagObjectMapper $tagMapper;
	private IRootFolder $rootFolder;
	private IUser $user;
	private bool $isAdmin;

	public function __construct(
		ISystemTagManager $tagManager,
		ISystemTagObjectMapper $tagMapper,
		IRootFolder $rootFolder,
		IUser $user,
		bool $isAdmin,
		bool $writeEnabled
	) {
		$this->tagManager = $tagManager;
		$this->tagMapper = $tagMapper;
		$this->rootFolder = $rootFolder;
		$this->user = $user;
		$this->isAdmin = $isAdmin;
		$this->writeEnabled = $writeEnabled;
	}

	/**
	 * List all system tags visible to the user, or the tags on a specific file.
	 *
	 * @param string $path Optional file path relative to the user's root; empty lists all tags.
	 * @return array The tags with id, name and assignability.
	 */
	public function list(string $path = ''): array {
		if ($path !== '') {
			$fileId = (string)$this->fileId($path);
			$ids = $this->tagMapper->getTagIdsForObjects([$fileId], 'files')[$fileId] ?? [];
			$tags = $ids ? $this->tagManager->getTagsByIds($ids) : [];
		} else {
			$tags = $this->tagManager->getAllTags(true);
		}
		$out = [];
		foreach ($tags as $tag) {
			// Hide tags the user is not allowed to see (e.g. static/restricted tags).
			if (!$this->tagManager->canUserSeeTag($tag, $this->user)) {
				continue;
			}
			$out[] = $this->describe($tag);
		}
		return ['count' => \count($out), 'tags' => $out];
	}

	/**
	 * Assign a system tag to a file. The tag must already exist unless the acting
	 * user is an administrator (only admins may create new system tags). Requires
	 * write access, and the user must be allowed to assign the tag.
	 *
	 * @param string $path File path relative to the user's root.
	 * @param string $tag The tag name.
	 * @return array The assigned tag.
	 */
	public function assign(string $path, string $tag): array {
		$this->assertWrite();
		$fileId = (string)$this->fileId($path);
		$tagObj = $this->resolveOrCreate($tag);
		$this->assertCanAssign($tagObj);
		$this->tagMapper->assignTags($fileId, 'files', $tagObj->getId());
		return $this->describe($tagObj);
	}

	/**
	 * Remove a system tag from a file. Requires write access, and the user must be
	 * allowed to assign (and therefore unassign) the tag.
	 *
	 * @param string $path File path relative to the user's root.
	 * @param string $tag The tag name.
	 * @return array A confirmation.
	 */
	public function remove(string $path, string $tag): array {
		$this->assertWrite();
		$fileId = (string)$this->fileId($path);
		$tagObj = $this->resolveExisting($tag);
		$this->assertCanAssign($tagObj);
		$this->tagMapper->unassignTags($fileId, 'files', $tagObj->getId());
		return ['removed' => $tag, 'path' => $path];
	}

	private function fileId(string $path): int {
		return $this->rootFolder->getUserFolder($this->user->getUID())->get('/' . \ltrim($path, '/'))->getId();
	}

	private function assertCanAssign(ISystemTag $tag): void {
		if (!$this->tagManager->canUserAssignTag($tag, $this->user)) {
			throw new ToolCallException('You are not allowed to assign or remove the tag "' . $tag->getName() . '".');
		}
	}

	private function resolveExisting(string $name): ISystemTag {
		foreach ($this->tagManager->getAllTags(null, $name) as $tag) {
			if ($tag->getName() === $name && $this->tagManager->canUserSeeTag($tag, $this->user)) {
				return $tag;
			}
		}
		throw new ToolCallException('Tag not found: ' . $name);
	}

	private function resolveOrCreate(string $name): ISystemTag {
		try {
			return $this->resolveExisting($name);
		} catch (ToolCallException $e) {
			// Creating a brand-new system tag is an administrator-only action,
			// matching apps/dav SystemTagPlugin.
			if (!$this->isAdmin) {
				throw new ToolCallException(
					'Tag "' . $name . '" does not exist. Only administrators can create new system tags.'
				);
			}
			return $this->tagManager->createTag($name, true, true);
		}
	}

	private function describe(ISystemTag $tag): array {
		return [
			'id' => $tag->getId(),
			'name' => $tag->getName(),
			'user_visible' => $tag->isUserVisible(),
			'user_assignable' => $tag->isUserAssignable(),
		];
	}
}
