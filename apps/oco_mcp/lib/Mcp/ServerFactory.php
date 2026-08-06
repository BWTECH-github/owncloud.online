<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 */
namespace OCA\OcoMcp\Mcp;

use OCA\OcoMcp\Tools\AiDocumentsTool;
use OCA\OcoMcp\Tools\CommentsTool;
use OCA\OcoMcp\Tools\FilesTool;
use OCA\OcoMcp\Tools\GroupsTool;
use OCA\OcoMcp\Tools\MetaTool;
use OCA\OcoMcp\Tools\SharesTool;
use OCA\OcoMcp\Tools\TagsTool;
use OCA\OcoMcp\Tools\UsersTool;
use OCP\App\IAppManager;
use OCP\Comments\ICommentsManager;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Assembles a fully wired MCP {@see \Mcp\Server} for one authenticated request.
 *
 * Tool objects are instantiated here with the acting user already baked in and
 * registered as bound instance callables, so no PSR-11 container is needed and
 * every tool runs strictly as that user with that user's ownCloud permissions.
 */
class ServerFactory {
	private IRootFolder $rootFolder;
	private IShareManager $shareManager;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private ISystemTagManager $tagManager;
	private ISystemTagObjectMapper $tagMapper;
	private ICommentsManager $commentsManager;
	private IConfig $config;
	private IURLGenerator $urlGenerator;
	private IAppManager $appManager;
	private ILogger $logger;

	public function __construct(
		IRootFolder $rootFolder,
		IShareManager $shareManager,
		IUserManager $userManager,
		IGroupManager $groupManager,
		ISystemTagManager $tagManager,
		ISystemTagObjectMapper $tagMapper,
		ICommentsManager $commentsManager,
		IConfig $config,
		IURLGenerator $urlGenerator,
		IAppManager $appManager,
		ILogger $logger
	) {
		$this->rootFolder = $rootFolder;
		$this->shareManager = $shareManager;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->tagManager = $tagManager;
		$this->tagMapper = $tagMapper;
		$this->commentsManager = $commentsManager;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->appManager = $appManager;
		$this->logger = $logger;
	}

	public function build(IUser $user, bool $isAdmin, bool $writeEnabled): \Mcp\Server {
		$uid = $user->getUID();

		// Pre-build every tool with the acting user baked in, then hand them to
		// the SDK through a PSR-11 container. The SDK resolves an array handler
		// [Class::class, 'method'] via container->get(Class), so these exact
		// instances are used and every tool runs strictly as this user.
		$map = [
			FilesTool::class => new FilesTool($this->rootFolder, $uid, $writeEnabled),
			SharesTool::class => new SharesTool($this->shareManager, $this->rootFolder, $this->urlGenerator, $uid, $writeEnabled),
			TagsTool::class => new TagsTool($this->tagManager, $this->tagMapper, $this->rootFolder, $user, $isAdmin, $writeEnabled),
			CommentsTool::class => new CommentsTool($this->commentsManager, $this->rootFolder, $uid, $writeEnabled),
			UsersTool::class => new UsersTool($this->userManager, $isAdmin, $writeEnabled),
			GroupsTool::class => new GroupsTool($this->groupManager, $this->userManager, $isAdmin, $writeEnabled),
			MetaTool::class => new MetaTool($this->rootFolder, $uid, $isAdmin, $writeEnabled),
			FileResourceProvider::class => new FileResourceProvider($this->rootFolder, $uid),
		];

		// The ai_documents RAG tool is optional: only wire it when that app is
		// actually enabled for this user, so instances without it never see a
		// broken tool and oco_mcp keeps no hard dependency on it.
		$aiDocsEnabled = $this->appManager->isEnabledForUser('ai_documents', $user);
		if ($aiDocsEnabled) {
			$map[AiDocumentsTool::class] = new AiDocumentsTool($this->logger);
		}

		$container = new InstanceContainer($map);

		$builder = \Mcp\Server::builder()
			->setServerInfo('owncloud.online', '1.0.3', 'MCP access to owncloud.online files, shares, tags, comments and user management.')
			->setInstructions(
				'You are connected to an owncloud.online instance as user "' . $uid . '". '
				. 'Paths are relative to that user\'s file root ("/"). '
				. ($writeEnabled ? 'Write and management tools are ENABLED.' : 'This connection is READ-ONLY; write tools are not exposed.')
			)
			->setContainer($container)
			->setSession(new \Mcp\Server\Session\FileSessionStore($this->sessionDir(), 3600));

		// Files
		$builder
			->addTool([FilesTool::class, 'list'], 'files_list')
			->addTool([FilesTool::class, 'info'], 'files_info')
			->addTool([FilesTool::class, 'read'], 'files_read')
			->addTool([FilesTool::class, 'viewImage'], 'files_view_image')
			->addTool([FilesTool::class, 'search'], 'files_search');
		if ($writeEnabled) {
			$builder
				->addTool([FilesTool::class, 'write'], 'files_write')
				->addTool([FilesTool::class, 'mkdir'], 'files_mkdir')
				->addTool([FilesTool::class, 'move'], 'files_move')
				->addTool([FilesTool::class, 'copy'], 'files_copy')
				->addTool([FilesTool::class, 'delete'], 'files_delete');
		}

		// Shares
		$builder->addTool([SharesTool::class, 'list'], 'shares_list');
		if ($writeEnabled) {
			$builder
				->addTool([SharesTool::class, 'createLink'], 'shares_create_link')
				->addTool([SharesTool::class, 'createUser'], 'shares_create_user')
				->addTool([SharesTool::class, 'delete'], 'shares_delete');
		}

		// Tags
		$builder->addTool([TagsTool::class, 'list'], 'tags_list');
		if ($writeEnabled) {
			$builder
				->addTool([TagsTool::class, 'assign'], 'tags_assign')
				->addTool([TagsTool::class, 'remove'], 'tags_remove');
		}

		// Comments
		$builder->addTool([CommentsTool::class, 'list'], 'comments_list');
		if ($writeEnabled) {
			$builder->addTool([CommentsTool::class, 'add'], 'comments_add');
		}

		// Users (admin-gated inside the tool)
		if ($isAdmin) {
			$builder
				->addTool([UsersTool::class, 'list'], 'users_list')
				->addTool([UsersTool::class, 'get'], 'users_get');
			if ($writeEnabled) {
				$builder
					->addTool([UsersTool::class, 'create'], 'users_create')
					->addTool([UsersTool::class, 'disable'], 'users_disable')
					->addTool([UsersTool::class, 'enable'], 'users_enable')
					->addTool([UsersTool::class, 'setQuota'], 'users_set_quota');
			}
		}

		// Groups (admin-gated inside the tool)
		if ($isAdmin) {
			$builder
				->addTool([GroupsTool::class, 'list'], 'groups_list')
				->addTool([GroupsTool::class, 'members'], 'groups_members');
			if ($writeEnabled) {
				$builder
					->addTool([GroupsTool::class, 'addMember'], 'groups_add_member')
					->addTool([GroupsTool::class, 'removeMember'], 'groups_remove_member');
			}
		}

		// Meta
		$builder
			->addTool([MetaTool::class, 'whoami'], 'whoami')
			->addTool([MetaTool::class, 'quota'], 'quota')
			->addTool([MetaTool::class, 'capabilities'], 'capabilities');

		// AI document search (only when ai_documents is enabled for this user).
		if ($aiDocsEnabled) {
			$builder->addTool([AiDocumentsTool::class, 'ask'], 'ai_ask');
		}

		// Resources: expose the user's files so MCP clients can browse and attach
		// them to context natively (not only via tool calls).
		$builder
			->addResource(
				[FileResourceProvider::class, 'root'],
				'owncloud:///',
				'owncloud-root',
				'ownCloud root folder',
				'JSON listing of the user\'s root folder. Each entry carries a "uri" you can read.',
				'application/json'
			)
			->addResourceTemplate(
				[FileResourceProvider::class, 'read'],
				'owncloud:///{path}',
				'owncloud-file',
				'ownCloud file or folder',
				'Read a file (text or binary) or a folder listing by path. Percent-encode "/" as %2F for nested paths.'
			);

		return $builder->build();
	}

	private function sessionDir(): string {
		$base = \rtrim((string)$this->config->getSystemValue('datadirectory', \sys_get_temp_dir()), '/');
		$dir = $base . '/oco_mcp-sessions';
		if (!\is_dir($dir)) {
			@\mkdir($dir, 0770, true);
		}
		return $dir;
	}
}
