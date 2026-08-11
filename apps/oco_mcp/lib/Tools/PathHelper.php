<?php
/**
 * MCP Connector for owncloud.online
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0-only
 *
 * Modified by BW-Tech GmbH on 2026-08-06.
 * Changes:
 *   - route every MCP path through one strict gate and stop leaking backend errors
 */
namespace OCA\OcoMcp\Tools;

use Mcp\Exception\ToolCallException;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * The single place where a client-supplied path becomes an ownCloud node.
 *
 * Every tool MUST resolve paths through here instead of calling
 * `getUserFolder($uid)->get('/' . ltrim($path, '/'))` itself. The core's
 * `Root::get()` does reject '..' on its own (`isValidPath()`), but relying on
 * that alone makes the connector's safety a property of core internals rather
 * than of this app — and it silently drops the stricter contract the file tools
 * always had. Keeping one strict gate means a change in core cannot quietly
 * widen what an MCP client may address.
 *
 * {@see self::node()} additionally translates the core's file exceptions into
 * `ToolCallException`, so the client gets a short, deliberate message about its
 * own path instead of an unhandled internal error.
 */
final class PathHelper {
	/**
	 * Normalise a client path to a canonical absolute path inside the user root.
	 *
	 * Bewusst strikt: '.'- und '..'-Segmente werden abgelehnt statt aufgeloest,
	 * damit der Client nur kanonische Pfade schickt.
	 *
	 * @throws ToolCallException when the path contains relative segments.
	 */
	public static function clean(string $path): string {
		$path = \str_replace('\\', '/', \trim($path));
		foreach (\explode('/', $path) as $segment) {
			if ($segment === '.' || $segment === '..') {
				throw new ToolCallException('Relative path segments (".", "..") are forbidden.');
			}
		}
		$path = '/' . \ltrim($path, '/');
		return $path === '/' ? '/' : \rtrim($path, '/');
	}

	/**
	 * Resolve a client path inside the given user folder.
	 *
	 * @throws ToolCallException for relative segments, missing paths and paths
	 *                           the acting user may not address.
	 */
	public static function node(Folder $userFolder, string $path): Node {
		$clean = self::clean($path);
		try {
			return $userFolder->get($clean);
		} catch (NotFoundException) {
			throw new ToolCallException('Path not found: ' . $clean);
		} catch (NotPermittedException | InvalidPathException) {
			// Absichtlich nur der (vom Client selbst geschickte) Pfad, nie die
			// Exception-Meldung: die kann interne Pfade oder Storage-Details
			// enthalten und wird vom SDK woertlich an den Client gereicht.
			throw new ToolCallException('Path is not accessible: ' . $clean);
		}
	}
}
