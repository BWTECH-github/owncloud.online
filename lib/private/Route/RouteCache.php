<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-07-02.
 * Changes:
 *   - compiled route cache — stop rebuilding 400+ routes on every request
 */

namespace OC\Route;

use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RouteCollection;

/**
 * Persistenter Cache für die komplette Routen-Tabelle.
 *
 * Ohne Cache inkludiert der Router bei jedem Request außerhalb von /apps/
 * (also /, /login, /ocs/..., Public-Links, Avatare, cron) sämtliche
 * appinfo/routes.php und baut ~400 Symfony-Routen samt DIContainer-Instanzen
 * neu auf — gemessen 4-24 ms pro Request. Hier werden stattdessen die
 * kompilierten Matcher-/Generator-Tabellen (reine Arrays, opcache-freundlich)
 * zusammen mit einer Route→Besitzer-App-Karte nach
 * <datadir>/route-cache/routes-<sig>.php gedumpt. Closures und
 * RouteActionHandler-Objekte sind nicht serialisierbar; sie werden beim
 * Cache-Hit rekonstruiert, indem nur die Routen der Besitzer-App nachgeladen
 * werden.
 *
 * Fail-open by design: jede Unstimmigkeit (Signatur-Miss, fehlende Route,
 * nicht ladbare App) führt zurück auf den klassischen Voll-Load-Pfad.
 */
class RouteCache {
	/** Besitzer-Marker: Route gehört core/settings — kein App-Nachladen nötig. */
	public const OWNER_CORE = '';

	/** @var string|null */
	private $dir;
	/** @var string|null */
	private $signature;

	public function __construct() {
		$dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory', null);
		$this->dir = \is_string($dataDir) && $dataDir !== '' ? $dataDir . '/route-cache' : null;
	}

	/**
	 * Cache nur nutzen, wenn die Umgebung stabil ist: nicht im Debug-Modus,
	 * nicht in Maintenance/Upgrade (dort ändert sich der Routenbestand gerade),
	 * abschaltbar per config 'route.cache' => false.
	 */
	public function isUsable() {
		if ($this->dir === null) {
			return false;
		}
		if (!\class_exists(CompiledUrlMatcherDumper::class)) {
			return false;
		}
		$config = \OC::$server->getConfig();
		if ($config->getSystemValue('route.cache', true) === false) {
			return false;
		}
		if ($config->getSystemValue('debug', false)) {
			return false;
		}
		if ($config->getSystemValue('maintenance', false) || \OCP\Util::needUpgrade()) {
			return false;
		}
		return true;
	}

	/**
	 * Signatur über alles, was den Routenbestand ändern kann. filemtime aller
	 * routes.php deckt insbesondere git-pull-Deployments OHNE Versionsbump ab
	 * (das produktive Deployment-Modell dieser Instanzen).
	 *
	 * @param string[] $routingFiles app id => routes.php path
	 * @return string
	 */
	public function getSignature(array $routingFiles) {
		if ($this->signature !== null) {
			return $this->signature;
		}
		$parts = [\OC_Util::getVersionString(), \OC::$WEBROOT];
		$apps = \array_keys($routingFiles);
		\sort($apps);
		foreach ($apps as $app) {
			$file = $routingFiles[$app];
			$parts[] = $app . '|' . $file . '|' . (int)@\filemtime($file);
		}
		foreach ([__DIR__ . '/../../../settings/routes.php', __DIR__ . '/../../../core/routes.php'] as $file) {
			$parts[] = $file . '|' . (int)@\filemtime($file);
		}
		$this->signature = \md5(\implode("\n", $parts));
		return $this->signature;
	}

	private function cacheFile($signature) {
		return $this->dir . '/routes-' . $signature . '.php';
	}

	/**
	 * @param string $signature
	 * @return array|null ['matcher' => array, 'generator' => array, 'routeApps' => array]
	 */
	public function load($signature) {
		$file = $this->cacheFile($signature);
		if (!@\is_file($file)) {
			return null;
		}
		$data = @include $file;
		if (!\is_array($data)
			|| !isset($data['matcher'], $data['generator'], $data['routeApps'])
			|| !\is_array($data['matcher']) || !\is_array($data['generator']) || !\is_array($data['routeApps'])
		) {
			return null;
		}
		return $data;
	}

	/**
	 * Dump nach vollständigem Voll-Load. Der Aufrufer garantiert, dass ALLE
	 * Apps geladen waren (Router::$loaded === true nach dem Load) — sonst
	 * würde ein unvollständiger Routenbestand persistiert.
	 *
	 * @param string $signature
	 * @param RouteCollection $root fully merged root collection
	 * @param RouteCollection[] $collections per-name collections of the router
	 */
	public function store($signature, RouteCollection $root, array $collections) {
		if (!@\is_dir($this->dir) && !@\mkdir($this->dir, 0770, true)) {
			return;
		}

		// Route -> Besitzer ermitteln. App-Collections tragen den App-Namen,
		// '<app>.ocs' gehört derselben App, 'root'/'root.ocs' sind core/settings.
		// Legacy-OCS-Routen ('ocs'-Collection) können MEHRERE Besitzer-Apps haben
		// (OC_API::register merged sie unter einem Namen) — die Liste kommt aus
		// OC_API::getRouteApps().
		$routeApps = [];
		foreach ($collections as $name => $collection) {
			if ($name === 'root' || $name === 'root.ocs') {
				$owner = self::OWNER_CORE;
			} elseif ($name === 'ocs') {
				$owner = null; // je Route unten aufgelöst
			} elseif (\substr($name, -4) === '.ocs') {
				$owner = \substr($name, 0, -4);
			} else {
				$owner = $name;
			}
			foreach ($collection->all() as $routeName => $route) {
				if ($name === 'ocs') {
					$apps = \OC_API::getRouteApps($routeName);
					$routeApps[$routeName] = $apps !== [] ? $apps : null;
				} else {
					$routeApps[$routeName] = $owner;
				}
			}
		}

		// Klon deep-copied die Routen; Objekt-Defaults (Closures, ActionHandler)
		// müssen raus, sie sind nicht var_export-bar und werden beim Hit lazy
		// über das App-Nachladen rekonstruiert.
		$dumpCollection = clone $root;
		foreach ($dumpCollection->all() as $route) {
			$defaults = $route->getDefaults();
			$changed = false;
			foreach ($defaults as $key => $value) {
				if (\is_object($value) || $value instanceof \Closure || \is_array($value) && \is_callable($value, true) && \is_object($value[0] ?? null)) {
					unset($defaults[$key]);
					$changed = true;
				}
			}
			if ($changed) {
				$route->setDefaults($defaults);
			}
		}

		try {
			$matcherDump = (new CompiledUrlMatcherDumper($dumpCollection))->getCompiledRoutes();
			$generatorDump = (new CompiledUrlGeneratorDumper($dumpCollection))->getCompiledRoutes();
		} catch (\Throwable $e) {
			\OC::$server->getLogger()->warning(
				'route cache dump failed: ' . $e->getMessage(),
				['app' => 'core']
			);
			return;
		}

		$payload = '<?php return ' . \var_export([
			'matcher' => $matcherDump,
			'generator' => $generatorDump,
			'routeApps' => $routeApps,
		], true) . ';';

		$file = $this->cacheFile($signature);
		$tmp = @\tempnam($this->dir, 'routes-');
		if ($tmp === false) {
			return;
		}
		if (@\file_put_contents($tmp, $payload) === false) {
			@\unlink($tmp);
			return;
		}
		@\rename($tmp, $file);
		if (\function_exists('opcache_invalidate')) {
			@\opcache_invalidate($file, true);
		}

		// Mehrere Signaturen sind hier NORMAL und muessen koexistieren: die
		// Routen-Tabelle ist pro App-Listen-Kombination korrekt (anonyme Besucher,
		// eingeloggte Nutzer, Gaeste mit App-Whitelist sehen unterschiedliche
		// Routen-Bestaende — nicht-freigegebene Apps muessen 404 bleiben).
		// Wuerde jede Schreiboperation die anderen Dateien loeschen, wuerden sich
		// die Principal-Klassen den Cache gegenseitig wegraeumen und jeder Miss
		// zahlte den vollen Build. Deshalb nur ein bounded Cleanup der aeltesten
		// Dateien; veraltete Signaturen verschwinden darueber automatisch.
		$all = (array)@\glob($this->dir . '/routes-*.php');
		if (\count($all) > 8) {
			\usort($all, function ($a, $b) {
				return (int)@\filemtime($a) <=> (int)@\filemtime($b);
			});
			foreach (\array_slice($all, 0, \count($all) - 8) as $old) {
				if ($old !== $file) {
					@\unlink($old);
				}
			}
		}
	}
}
