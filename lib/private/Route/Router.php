<?php
/**
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@users.noreply.github.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
 */

namespace OC\Route;

use OCP\ILogger;
use OCP\Route\IRouter;
use OCP\AppFramework\App;
use OCP\Util;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class Router implements IRouter {
	/** @var RouteCollection[] */
	protected $collections = [];
	/** @var null|RouteCollection */
	protected $collection = null;
	/** @var null|string */
	protected $collectionName = null;
	/** @var null|RouteCollection */
	protected $root = null;
	/** @var null|UrlGenerator */
	protected $generator = null;
	/** @var string[] */
	protected $routingFiles;
	/** @var bool */
	protected $loaded = false;
	/** @var array */
	protected $loadedApps = [];
	/** @var ILogger */
	protected $logger;
	/** @var RequestContext */
	protected $context;
	/** @var RouteCache|null */
	protected $routeCache;
	/** @var array|null geladener Cache-Inhalt (matcher/generator/routeApps) */
	protected $routeCacheData;
	/** @var bool true sobald ein Cache-Load versucht wurde */
	protected $routeCacheChecked = false;

	/**
	 * @param ILogger $logger
	 */
	public function __construct(ILogger $logger, $baseUrl = null) {
		$this->logger = $logger;
		if ($baseUrl === null) {
			$baseUrl = \OC::$WEBROOT;
		}
		if (!(\getenv('front_controller_active') === 'true')) {
			$baseUrl = \rtrim($baseUrl, '/') . '/index.php';
		}
		if (!\OC::$CLI) {
			$method = $_SERVER['REQUEST_METHOD'];
		} else {
			$method = 'GET';
		}
		$request = \OC::$server->getRequest();
		$host = $request->getServerHost();
		$schema = $request->getServerProtocol();
		$this->context = new RequestContext($baseUrl, $method, $host, $schema);
		// TODO cache
		$this->root = $this->getCollection('root');
	}

	/**
	 * Get the files to load the routes from
	 *
	 * @return string[]
	 */
	public function getRoutingFiles() {
		if (!isset($this->routingFiles)) {
			$this->routingFiles = [];
			foreach (\OC_App::getEnabledApps() as $app) {
				$appPath = \OC_App::getAppPath($app);
				if ($appPath !== false) {
					$file = $appPath . '/appinfo/routes.php';
					if (\file_exists($file)) {
						$this->routingFiles[$app] = $file;
					}
				}
			}
		}
		return $this->routingFiles;
	}

	/**
	 * Loads the routes
	 *
	 * @param null|string $app
	 */
	public function loadRoutes($app = null) {
		if (\is_string($app)) {
			$app = \OC_App::cleanAppId($app);
		}

		$requestedApp = $app;
		if ($this->loaded) {
			return;
		}
		if ($app === null) {
			$this->loaded = true;
			$routingFiles = $this->getRoutingFiles();
		} else {
			if (isset($this->loadedApps[$app])) {
				return;
			}
			$appPath = \OC_App::getAppPath($app);
			$file = $appPath . '/appinfo/routes.php';
			if ($appPath !== false && \file_exists($file)) {
				$routingFiles = [$app => $file];
			} else {
				$routingFiles = [];
			}
		}
		\OC::$server->getEventLogger()->start('loadroutes' . $requestedApp, 'Loading Routes');
		foreach ($routingFiles as $app => $file) {
			if (!isset($this->loadedApps[$app])) {
				if (!\OC_App::isAppLoaded($app)) {
					// app MUST be loaded before app routes
					// try again next time loadRoutes() is called
					$this->loaded = false;
					continue;
				}
				$this->loadedApps[$app] = true;
				$this->useCollection($app);
				$this->requireRouteFile($file, $app);
				$collection = $this->getCollection($app);
				$collection->addPrefix('/apps/' . $app);
				$this->root->addCollection($collection);

				// Also add the OCS collection
				$collection = $this->getCollection($app.'.ocs');
				$collection->addPrefix('/ocsapp');
				$this->root->addCollection($collection);
			}
		}
		if (!isset($this->loadedApps['core'])) {
			$this->loadedApps['core'] = true;
			$this->useCollection('root');
			require_once __DIR__ . '/../../../settings/routes.php';
			require_once __DIR__ . '/../../../core/routes.php';

			// Also add the OCS collection
			$collection = $this->getCollection('root.ocs');
			$collection->addPrefix('/ocsapp');
			$this->root->addCollection($collection);
		}
		if ($this->loaded) {
			// include ocs routes, must be loaded last for /ocs prefix
			$collection = $this->getCollection('ocs');
			$collection->addPrefix('/ocs');
			$this->root->addCollection($collection);
		}
		\OC::$server->getEventLogger()->end('loadroutes' . $requestedApp);
	}

	/**
	 * @return string
	 * @deprecated
	 */
	public function getCacheKey() {
		return '';
	}

	/**
	 * @param string $name
	 * @return \Symfony\Component\Routing\RouteCollection
	 */
	protected function getCollection($name) {
		if (!isset($this->collections[$name])) {
			$this->collections[$name] = new RouteCollection();
		}
		return $this->collections[$name];
	}

	/**
	 * Sets the collection to use for adding routes
	 *
	 * @param string $name Name of the collection to use.
	 * @return void
	 */
	public function useCollection($name) {
		$this->collection = $this->getCollection($name);
		$this->collectionName = $name;
	}

	/**
	 * returns the current collection name in use for adding routes
	 *
	 * @return string the collection name
	 */
	public function getCurrentCollection() {
		return $this->collectionName;
	}

	/**
	 * returns the current collections
	 *
	 * @return RouteCollection[] collections
	 */
	public function getCollections() {
		return $this->collections;
	}

	/**
	 * Create a \OC\Route\Route.
	 *
	 * @param string $name Name of the route to create.
	 * @param string $pattern The pattern to match
	 * @param array $defaults An array of default parameter values
	 * @param array $requirements An array of requirements for parameters (regexes)
	 * @return \OC\Route\Route
	 */
	public function create(
		$name,
		$pattern,
		array $defaults = [],
		array $requirements = []
	) {
		$route = new Route($pattern, $defaults, $requirements);
		$this->collection->add($name, $route);
		return $route;
	}

	/**
	 * Find the route matching $url
	 *
	 * @param string $url The url to find
	 * @throws \Exception
	 * @return void
	 */
	public function match($url) {
		if (\substr($url, 0, 6) === '/apps/') {
			// empty string / 'apps' / $app / rest of the route
			list(, , $app, ) = \explode('/', $url, 4);

			$app = \OC_App::cleanAppId($app);
			\OC::$REQUESTEDAPP = $app;
			$this->loadRoutes($app);
		} elseif (\substr($url, 0, 13) === '/ocsapp/apps/') {
			// empty string / 'ocsapp' / 'apps' / $app / rest of the route
			list(, , , $app, ) = \explode('/', $url, 5);

			$app = \OC_App::cleanAppId($app);
			\OC::$REQUESTEDAPP = $app;
			$this->loadRoutes($app);
		} elseif (\substr($url, 0, 6) === '/core/' or \substr($url, 0, 10) === '/settings/') {
			\OC::$REQUESTEDAPP = $url;
			if (!\OC::$server->getConfig()->getSystemValue('maintenance', false) && !Util::needUpgrade()) {
				\OC_App::loadApps();
			}
			$this->loadRoutes('core');
		} else {
			// Voll-Load-Pfad (/, /login, /ocs/..., Public-Links, Avatare, cron):
			// erst den kompilierten Routen-Cache versuchen — der matcht ohne die
			// ~30 routes.php-Includes und lädt nur die Routen der Besitzer-App nach.
			if ($this->matchWithCache($url)) {
				return;
			}
			// Cache-Miss mit nutzbarem Cache: einmalig alle Apps laden, damit der
			// Voll-Load vollständig ist (loadRoutes überspringt Apps, deren Code
			// nicht geladen ist) und die kompilierten Tabellen persistiert werden
			// können. Gleiche Gates wie der /core/-Zweig oben; alle Folge-Requests
			// treffen dann den Cache.
			if ($this->routeCache !== null && $this->routeCacheData === null
				&& !\OC::$server->getConfig()->getSystemValue('maintenance', false) && !Util::needUpgrade()
			) {
				\OC_App::loadApps();
			}
			$this->loadRoutes();
			$this->storeRouteCacheIfNeeded();
		}

		$matcher = new UrlMatcher($this->root, $this->context);

		if (\OC::$server->getRequest()->getMethod() === "OPTIONS" && \OC::$server->getRequest()->getHeader('Access-Control-Request-Method')) {
			try {
				// Checking whether the actual request (one which OPTIONS is pre-flight for)
				// Is actually valid
				$requestingMethod = \OC::$server->getRequest()->getHeader('Access-Control-Request-Method');
				$tempContext = $this->context;
				$tempContext->setMethod($requestingMethod);
				$tempMatcher = new UrlMatcher($this->root, $tempContext);
				$parameters = $tempMatcher->match($url);

				// Reach here if it's valid
				$response = new \OC\OCS\Result(null, 100, 'OPTIONS request successful');
				$response = \OC_Response::setOptionsRequestHeaders($response);
				\OC_API::respond($response, \OC_API::requestedFormat());

				// Return since no more processing for an OPTIONS request is required
				return;
			} catch (ResourceNotFoundException $e) {
				if (\substr($url, -1) !== '/') {
					// We allow links to apps/files? for backwards compatibility reasons
					// However, since Symfony does not allow empty route names, the route
					// we need to match is '/', so we need to append the '/' here.
					try {
						$parameters = $matcher->match($url . '/');
					} catch (ResourceNotFoundException $newException) {
						// If we still didn't match a route, we throw the original exception
						throw $e;
					}
				} else {
					throw $e;
				}
			}
		}

		try {
			$parameters = $matcher->match($url);
		} catch (ResourceNotFoundException $e) {
			if (\substr($url, -1) !== '/') {
				// We allow links to apps/files? for backwards compatibility reasons
				// However, since Symfony does not allow empty route names, the route
				// we need to match is '/', so we need to append the '/' here.
				try {
					$parameters = $matcher->match($url . '/');
				} catch (ResourceNotFoundException $newException) {
					// If we still didn't match a route, we throw the original exception
					throw $e;
				}
			} else {
				throw $e;
			}
		}

		$this->runRoute($parameters);
	}

	/**
	 * Dispatch matched route parameters (shared by the classic and the cached path)
	 *
	 * @param array $parameters
	 * @throws \Exception
	 */
	private function runRoute(array $parameters) {
		\OC::$server->getEventLogger()->start('run_route', 'Run route');
		if (isset($parameters['action'])) {
			$action = $parameters['action'];
			if (!\is_callable($action)) {
				throw new \Exception('not a callable action');
			}
			unset($parameters['action']);
			\call_user_func($action, $parameters);
		} elseif (isset($parameters['file'])) {
			include $parameters['file'];
		} else {
			throw new \Exception('no action available');
		}
		\OC::$server->getEventLogger()->end('run_route');
	}

	/**
	 * Try to match+dispatch $url via the compiled route cache.
	 *
	 * Fail-open: false means "not handled" — the caller continues on the classic
	 * full-load path, which reproduces the exact legacy behaviour (including the
	 * final ResourceNotFoundException for unknown urls).
	 *
	 * @param string $url
	 * @return bool true when the request was fully dispatched
	 */
	private function matchWithCache($url) {
		$isOptionsPreflight = false;
		$parameters = null;
		// WICHTIG: dieser try deckt NUR Matching und Action-Rekonstruktion ab.
		// Der eigentliche Dispatch (runRoute) passiert unten AUSSERHALB — seine
		// Exceptions muessen exakt wie auf dem klassischen Pfad propagieren.
		// Wuerde er hier drin laufen, ergaebe der \Throwable-Fallback ein
		// "return false" und der Aufrufer wuerde den Handler ein ZWEITES Mal
		// ausfuehren (doppelte Seiteneffekte bei state-changing OCS-Calls).
		try {
			if ($this->loaded) {
				return false;
			}
			if ($this->getRouteCache() === null || $this->routeCacheData === null) {
				return false;
			}

			$matcher = new CompiledUrlMatcher($this->routeCacheData['matcher'], $this->context);
			$request = \OC::$server->getRequest();

			if ($request->getMethod() === 'OPTIONS' && $request->getHeader('Access-Control-Request-Method')) {
				try {
					// mirrors the classic OPTIONS pre-flight check including its
					// context mutation via object reference
					$tempContext = $this->context;
					$tempContext->setMethod($request->getHeader('Access-Control-Request-Method'));
					$tempMatcher = new CompiledUrlMatcher($this->routeCacheData['matcher'], $tempContext);
					$tempMatcher->match($url);
					$isOptionsPreflight = true;
				} catch (ResourceNotFoundException $e) {
					if (\substr($url, -1) !== '/') {
						try {
							$parameters = $this->prepareCachedDispatch($matcher->match($url . '/'));
							if ($parameters === null) {
								return false;
							}
						} catch (ResourceNotFoundException $newException) {
							// the cache holds the complete route table, so "not found"
							// here means the classic path would throw as well — same
							// exception semantics, without the full route load
							// (ocs/v1.php relies on this to probe /ocs before /ocsapp)
							throw $e;
						}
					} else {
						throw $e;
					}
				}
			} else {
				try {
					$matched = $matcher->match($url);
				} catch (ResourceNotFoundException $e) {
					if (\substr($url, -1) !== '/') {
						try {
							$matched = $matcher->match($url . '/');
						} catch (ResourceNotFoundException $newException) {
							throw $e;
						}
					} else {
						throw $e;
					}
				}
				$parameters = $this->prepareCachedDispatch($matched);
				if ($parameters === null) {
					return false;
				}
			}
		} catch (ResourceNotFoundException $e) {
			// complete table -> identical to the classic outcome
			throw $e;
		} catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'route cache match failed, falling back to full route load: ' . $e->getMessage(),
				['app' => 'core']
			);
			return false;
		}

		if ($isOptionsPreflight) {
			$response = new \OC\OCS\Result(null, 100, 'OPTIONS request successful');
			$response = \OC_Response::setOptionsRequestHeaders($response);
			\OC_API::respond($response, \OC_API::requestedFormat());
			return true;
		}

		// ausserhalb jedes catch-alls — siehe Kommentar oben
		$this->runRoute($parameters);
		return true;
	}

	/**
	 * Reconstruct the non-serializable action of a cache-matched route by loading
	 * only the owning app's routes.
	 *
	 * @param array $parameters compiled matcher result (contains _route)
	 * @return array|null dispatchable parameters, or null to fall back to the classic path
	 */
	private function prepareCachedDispatch(array $parameters) {
		if (!isset($parameters['_route'])) {
			return null;
		}
		$routeName = $parameters['_route'];

		if (!isset($parameters['action']) && !isset($parameters['file'])) {
			if (!\array_key_exists($routeName, $this->routeCacheData['routeApps'])) {
				return null;
			}
			$owner = $this->routeCacheData['routeApps'][$routeName];
			if ($owner === null) {
				// legacy-OCS route without resolvable owner list
				return null;
			}

			$owners = $owner === RouteCache::OWNER_CORE ? [] : (array)$owner;
			foreach ($owners as $app) {
				if (!\OC_App::isAppLoaded($app)) {
					\OC_App::loadApp($app);
				}
				$this->loadRoutes($app);
			}
			if ($owners === []) {
				// core/settings routes are (re-)registered by any loadRoutes() call
				$this->loadRoutes('core');
			}

			$liveRoute = $this->root->get($routeName);
			if ($liveRoute === null && isset($this->collections['ocs'])) {
				// legacy OCS routes live in the 'ocs' collection and are only merged
				// into root on a full load
				$liveRoute = $this->collections['ocs']->get($routeName);
			}
			if ($liveRoute === null) {
				return null;
			}

			$liveDefaults = $liveRoute->getDefaults();
			if (isset($liveDefaults['action'])) {
				$parameters['action'] = $liveDefaults['action'];
			} elseif (isset($liveDefaults['file'])) {
				$parameters['file'] = $liveDefaults['file'];
			} else {
				return null;
			}
		}

		return $parameters;
	}

	/**
	 * Lazily set up the route cache and load its payload for the current signature.
	 *
	 * @return RouteCache|null null when the cache must not be used
	 */
	private function getRouteCache() {
		if ($this->routeCacheChecked) {
			return $this->routeCache;
		}
		$this->routeCacheChecked = true;
		try {
			$cache = new RouteCache();
			if (!$cache->isUsable()) {
				return null;
			}
			$this->routeCacheData = $cache->load($cache->getSignature($this->getRoutingFiles()));
			$this->routeCache = $cache;
			return $cache;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'route cache unavailable: ' . $e->getMessage(),
				['app' => 'core']
			);
			$this->routeCache = null;
			return null;
		}
	}

	/**
	 * Persist the compiled route tables after a COMPLETE full load (never after a
	 * partial one where apps were skipped because they were not loaded yet).
	 */
	private function storeRouteCacheIfNeeded() {
		try {
			if (!$this->loaded || $this->routeCache === null || $this->routeCacheData !== null) {
				return;
			}
			foreach ($this->getRoutingFiles() as $app => $file) {
				if (!isset($this->loadedApps[$app])) {
					return;
				}
			}
			$this->routeCache->store(
				$this->routeCache->getSignature($this->getRoutingFiles()),
				$this->root,
				$this->collections
			);
			// mark as present so this request doesn't try again
			$this->routeCacheData = ['matcher' => [], 'generator' => [], 'routeApps' => []];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'route cache store failed: ' . $e->getMessage(),
				['app' => 'core']
			);
		}
	}

	/**
	 * Get the url generator
	 *
	 * @return \Symfony\Component\Routing\Generator\UrlGenerator
	 *
	 */
	public function getGenerator() {
		if ($this->generator !== null) {
			return $this->generator;
		}

		return $this->generator = new UrlGenerator($this->root, $this->context);
	}

	/**
	 * Generate url based on $name and $parameters
	 *
	 * @param string $name Name of the route to use.
	 * @param array $parameters Parameters for the route
	 * @param bool $absolute
	 * @return string
	 */
	public function generate(
		$name,
		$parameters = [],
		$absolute = false
	) {
		$referenceType = UrlGenerator::ABSOLUTE_URL;
		if ($absolute === false) {
			$referenceType = UrlGenerator::ABSOLUTE_PATH;
		}

		// As long as the routes were not fully loaded anyway, the compiled generator
		// from the route cache answers without including a single routes.php.
		if (!$this->loaded) {
			try {
				if ($this->getRouteCache() !== null && $this->routeCacheData !== null && $this->routeCacheData['generator'] !== []) {
					$generator = new CompiledUrlGenerator($this->routeCacheData['generator'], $this->context);
					return $generator->generate($name, $parameters, $referenceType);
				}
			} catch (RouteNotFoundException $e) {
				// unknown in the cache — fall through to the classic full load
			} catch (\Throwable $e) {
				$this->logger->warning(
					'route cache generate failed, falling back: ' . $e->getMessage(),
					['app' => 'core']
				);
			}
		}

		$this->loadRoutes();
		try {
			return $this->getGenerator()->generate($name, $parameters, $referenceType);
		} catch (RouteNotFoundException $e) {
			$this->logger->logException($e);
			return '';
		}
	}

	/**
	 * To isolate the variable scope used inside the $file it is required in it's own method
	 *
	 * @param string $file the route file location to include
	 * @param string $appName
	 */
	private function requireRouteFile($file, $appName) {
		$this->setupRoutes(include_once $file, $appName);
	}

	/**
	 * If a routes.php file returns an array, try to set up the application and
	 * register the routes for the app. The application class will be chosen by
	 * camelcasing the appname, e.g.: my_app will be turned into
	 * \OCA\MyApp\AppInfo\Application. If that class does not exist, a default
	 * App will be initialized. This makes it optional to ship an
	 * appinfo/application.php by using the built in query resolver
	 *
	 * @param array $routes the application routes
	 * @param string $appName the name of the app.
	 */
	private function setupRoutes($routes, $appName) {
		if (\is_array($routes)) {
			$appNameSpace = App::buildAppNamespace($appName);

			$applicationClassName = $appNameSpace . '\\AppInfo\\Application';

			if (\class_exists($applicationClassName)) {
				$application = new $applicationClassName();
			} else {
				$application = new App($appName);
			}

			$application->registerRoutes($this, $routes);
		}
	}
}
