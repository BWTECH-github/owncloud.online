<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Prueft, wem die App Schreibrechte gibt.
 *
 * Frueher hiess eine leere write_groups-Liste "instanzweit erlaubt": jedes
 * App- oder Geraetetoken durfte schreiben, jedes Admin-Token die Benutzer-
 * und Gruppenverwaltung bedienen. Dieser Test haelt die neue Regel fest -
 * ohne benannte Gruppe darf niemand schreiben.
 */

declare(strict_types=1);

/*
 * Der Controller erbt von OCP\AppFramework\Controller und tippt seine Felder
 * auf OCP-Schnittstellen. Fuer diesen Test genuegen Attrappen: geprueft wird
 * die Rechtelogik, nicht der Rahmen. Sie stehen in eigenen Namensraum-Bloecken,
 * damit sie vor dem Laden der Controller-Datei existieren.
 */
namespace OCP\AppFramework {
	class Controller {
		public function __construct($appName = '', $request = null) {
		}
	}
}

namespace OCP {
	interface IConfig {
	}
	interface IGroupManager {
	}
	interface ILogger {
	}
	interface IRequest {
	}
	interface IUserSession {
	}
}

namespace OCA\OcoMcp\Mcp {
	class ServerFactory {
	}
}

namespace OCA\OcoMcp\Security {
	class BasicAuthCredentials {
	}
}

namespace {
	require_once __DIR__ . '/../lib/Controller/McpController.php';

	$failures = [];

	function check(string $label, bool $ok): void {
		global $failures;
		if (!$ok) {
			$failures[] = $label;
		}
	}

	/** Config-Ersatz: liefert genau die Werte, die der Test vorgibt. */
	final class TestConfig implements \OCP\IConfig {
		/** @var array<string,string> */
		private array $werte;
		/** @var array<string,string> */
		public array $geschrieben = [];

		public function __construct(array $werte) {
			$this->werte = $werte;
		}

		public function getAppValue(string $app, string $key, string $default = ''): string {
			return $this->werte[$key] ?? $default;
		}

		public function setAppValue(string $app, string $key, string $value): void {
			// Wie die echte Konfiguration: Geschriebenes ist danach lesbar.
			// Ohne das laeuft die Drosselung der Warnung ins Leere und der
			// Test misst etwas, das es in der Anwendung nicht gibt.
			$this->geschrieben[$key] = $value;
			$this->werte[$key] = $value;
		}
	}

	/** Gruppenverwaltung-Ersatz mit fester Zuordnung. */
	final class TestGroupManager implements \OCP\IGroupManager {
		/** @var array<string,string[]> */
		private array $mitgliedschaften;

		public function __construct(array $mitgliedschaften) {
			$this->mitgliedschaften = $mitgliedschaften;
		}

		public function isInGroup(string $uid, string $gid): bool {
			return \in_array($gid, $this->mitgliedschaften[$uid] ?? [], true);
		}
	}

	/** Protokoll-Ersatz, merkt sich die Meldungen. */
	final class TestLogger implements \OCP\ILogger {
		/** @var string[] */
		public array $warnungen = [];

		public function warning(string $text, array $kontext = []): void {
			$this->warnungen[] = $text;
		}
	}

	/**
	 * Baut den Controller ohne Konstruktor und setzt nur die Felder, die
	 * userHasWriteAccess braucht.
	 */
	function baueController(TestConfig $config, TestGroupManager $gruppen, TestLogger $logger): object {
		$klasse = new \ReflectionClass(\OCA\OcoMcp\Controller\McpController::class);
		$controller = $klasse->newInstanceWithoutConstructor();
		foreach (['config' => $config, 'groupManager' => $gruppen, 'logger' => $logger] as $name => $wert) {
			$feld = $klasse->getProperty($name);
			$feld->setAccessible(true);
			$feld->setValue($controller, $wert);
		}
		return $controller;
	}

	function darfSchreiben(object $controller, string $uid): bool {
		$methode = new \ReflectionMethod($controller, 'userHasWriteAccess');
		$methode->setAccessible(true);
		return (bool)$methode->invoke($controller, $uid);
	}

	/* ---------- Ohne write_groups darf niemand ---------- */
	$logger = new TestLogger();
	$controller = baueController(
		new TestConfig([]),
		new TestGroupManager(['alice' => ['admin'], 'bob' => []]),
		$logger
	);
	check('leere Liste: Administrator darf nicht schreiben', darfSchreiben($controller, 'alice') === false);
	check('leere Liste: gewoehnliches Konto darf nicht schreiben', darfSchreiben($controller, 'bob') === false);
	check('leere Liste: Hinweis wird protokolliert', \count($logger->warnungen) === 1);
	check(
		'Hinweis nennt den Weg zur Behebung',
		\count($logger->warnungen) > 0 && \strpos($logger->warnungen[0], 'write_groups') !== false
	);

	/* ---------- Nur benannte Gruppen duerfen ---------- */
	$controller = baueController(
		new TestConfig(['write_groups' => 'mcp-writers, redakteure']),
		new TestGroupManager([
			'alice' => ['admin'],
			'bob'   => ['mcp-writers'],
			'carla' => ['redakteure'],
			'dora'  => ['gaeste'],
		]),
		new TestLogger()
	);
	check('benannte Gruppe: Mitglied darf schreiben', darfSchreiben($controller, 'bob') === true);
	check('benannte Gruppe: zweites Mitglied darf schreiben', darfSchreiben($controller, 'carla') === true);
	check('benannte Gruppe: Administrator ohne Mitgliedschaft darf nicht', darfSchreiben($controller, 'alice') === false);
	check('benannte Gruppe: Fremder darf nicht', darfSchreiben($controller, 'dora') === false);

	/* ---------- Randfaelle der Liste ---------- */
	$controller = baueController(
		new TestConfig(['write_groups' => '  ,  ,  ']),
		new TestGroupManager(['bob' => ['']]),
		new TestLogger()
	);
	check('Liste aus Trennzeichen erlaubt niemandem etwas', darfSchreiben($controller, 'bob') === false);

	$logger = new TestLogger();
	$controller = baueController(
		new TestConfig(['write_groups' => '   ']),
		new TestGroupManager(['bob' => ['admin']]),
		$logger
	);
	check('Liste aus Leerzeichen zaehlt als leer', darfSchreiben($controller, 'bob') === false);
	check('Liste aus Leerzeichen protokolliert den Hinweis', \count($logger->warnungen) === 1);

	/* ---------- Der Hinweis wiederholt sich nicht ununterbrochen ---------- */
	$logger = new TestLogger();
	$config = new TestConfig(['write_warning_logged_at' => (string)\time()]);
	$controller = baueController($config, new TestGroupManager([]), $logger);
	darfSchreiben($controller, 'bob');
	check('frischer Zeitstempel unterdrueckt die Wiederholung', \count($logger->warnungen) === 0);

	if ($failures) {
		echo "FAIL: MCP write access\n";
		foreach ($failures as $f) {
			echo "  - $f\n";
		}
		exit(1);
	}
	echo "PASS: MCP write access (leere write_groups erlauben niemandem etwas)\n";
}
