/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * CVE-2016-10744 am laufenden Objekt.
 *
 * Select2 3.5.4 setzt den Rueckgabewert von formatResult und formatSelection
 * roh in die Auswahlliste ein - label.html(), container.append(), .html().
 * Das geschieht unabhaengig von escapeMarkup. Ob die Kette unterbrochen ist,
 * laesst sich nicht am Quelltext ablesen, sondern nur daran, was im DOM
 * ankommt: deshalb ein echtes Chromium.
 *
 * Geprueft werden beide ausgelieferten Fassungen - die Seite verwendet
 * select2.min.js, die Einstellungsseite zusaetzlich select2.js.
 *
 *   node tests/js/select2_xss_test.js
 */

'use strict';

const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '../..');
const TYPES = {
	'.js': 'application/javascript',
	'.html': 'text/html',
	'.css': 'text/css',
	'.map': 'application/json',
	'.png': 'image/png',
	'.gif': 'image/gif'
};

let failures = 0;
let checks = 0;

function check(condition, label) {
	checks++;
	if (!condition) {
		failures++;
		console.log('FAILED: ' + label);
	}
}

function serve() {
	return new Promise((resolve) => {
		const server = http.createServer((req, res) => {
			const file = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
			if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
				res.writeHead(404);
				res.end('not found');
				return;
			}
			res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'text/plain' });
			res.end(fs.readFileSync(file));
		});
		server.listen(0, '127.0.0.1', () => resolve(server));
	});
}

async function run(page, base, lib) {
	await page.goto(base + '/tests/js/select2-xss-harness.html?lib=' + lib, { waitUntil: 'load' });
	await page.waitForFunction('window.__ready === true');
	await page.evaluate('window.__openAll()');
	await page.evaluate("['dom-formatter','string-formatter'].forEach(window.__select)");
	await page.waitForTimeout(150);

	const result = await page.evaluate(() => {
		const dropdowns = Array.from(document.querySelectorAll('.select2-drop'));
		const chosen = Array.from(document.querySelectorAll('.select2-chosen'));
		const all = dropdowns.concat(chosen);

		return {
			xss: window.__xss,
			// Ein <img> ist der Beweis, dass Markup ausgefuehrt wurde.
			injectedImages: all.reduce((n, el) => n + el.querySelectorAll('img[src="x"]').length, 0),
			// Der Name muss als sichtbarer Text ankommen.
			textSeen: all.some((el) => el.textContent.indexOf('<img src=x') !== -1),
			// Die eingebaute Hervorhebung baut <span class="select2-match">.
			builtinMarkup: document.querySelectorAll('.select2-drop .select2-match, .select2-drop .select2-result-label').length,
			// Ein Formatierer, der bewusst Markup als Objekt liefert.
			deliberateMarkup: document.querySelectorAll('.deliberate').length,
			// Unsere DOM-gebauten Formatierer.
			builtByUs: document.querySelectorAll('.built').length
		};
	});

	console.log('  ' + lib + ': ' + JSON.stringify(result));

	check(result.xss === 0, lib + ': no script ran from a hostile tag name');
	check(result.injectedImages === 0, lib + ': the hostile name produced no img element');
	check(result.textSeen === true, lib + ': the hostile name is visible as text (the entry really rendered)');
	check(result.builtinMarkup > 0, lib + ': the built-in formatter still produces its own markup');
	check(result.deliberateMarkup > 0, lib + ': a formatter returning a jQuery object keeps its markup');
	check(result.builtByUs > 0, lib + ': DOM-built formatters render');

	// Der Fix hat select2.min.js neu erzeugt. Diese Probe belegt, dass die
	// Datei sonst unveraendert arbeitet - eine kaputte Auswahlliste faellt in
	// einem Diff nicht auf, hier schon.
	const api = await page.evaluate('window.__apiSmoke()');
	console.log('    api: ' + JSON.stringify(api));

	check(api.valRoundTrip === 'a,c', lib + ": select2('val') returns what was set");
	check(api.dataRoundTrip === 'Alpha,Gamma', lib + ": select2('data') round-trips");
	check(api.hasContainer === 1, lib + ": select2('container') resolves");
	check(api.hasDropdown === 1, lib + ": select2('dropdown') resolves");
	check(api.searchResults === 1, lib + ': search filters the result list');
	check(api.choices === 2, lib + ': multi-select renders one choice per value');
	check(api.destroyed === 'yes', lib + ": select2('destroy') removes the container");
}

(async () => {
	const server = await serve();
	const base = 'http://127.0.0.1:' + server.address().port;
	const browser = await chromium.launch();
	const page = await browser.newPage();

	page.on('pageerror', (e) => {
		failures++;
		console.log('FAILED: page error: ' + e.message);
	});

	try {
		for (const lib of ['select2.js', 'select2.min.js']) {
			await run(page, base, lib);
		}
	} finally {
		await browser.close();
		server.close();
	}

	if (failures === 0) {
		console.log('Select2 XSS OK (' + checks + ' checks, both shipped builds)');
	}
	process.exit(failures === 0 ? 0 : 1);
})();
