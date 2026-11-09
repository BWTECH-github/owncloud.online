/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * jQuery 2.1.4 - CVE-2019-11358 und CVE-2015-9251.
 *
 * CVE-2019-11358: $.extend(true, …) mit einem Schlüssel "__proto__" (etwa aus
 * JSON.parse) verändert Object.prototype.
 * CVE-2015-9251: Eine Cross-Domain-Anfrage ohne ausdrücklichen dataType führt
 * eine Antwort mit Skript-Inhaltstyp sofort aus.
 *
 * Beides lässt sich nur im echten Browser belegen, deshalb Chromium. Für die
 * zweite Lücke laufen zwei Server auf verschiedenen Ports, also zwei Herkünfte.
 * Geprüft werden beide Fassungen: die ausgelieferte dist/jquery.min.js
 * (core/js/core.json) und die Quelle dist/jquery.js.
 *
 *   node tests/js/jquery_cve_test.js
 */

'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '../..');

// Playwright liegt je nach Rechner woanders: Umgebungsvariable zuerst, dann die
// node_modules des Repos, dann die globale Installation des DEV-Rechners.
const PLAYWRIGHT = [process.env.PLAYWRIGHT_MODULE, path.join(ROOT, 'node_modules', 'playwright'), '/opt/node22/lib/node_modules/playwright']
	.find((p) => p && fs.existsSync(p));
if (!PLAYWRIGHT) {
	console.error('Playwright nicht gefunden: PLAYWRIGHT_MODULE setzen oder `npm install playwright` im Repo');
	process.exit(2);
}
const { chromium } = require(PLAYWRIGHT);

const LIBS = ['core/vendor/jquery/dist/jquery.js', 'core/vendor/jquery/dist/jquery.min.js'];

let failures = 0;
let checks = 0;

function check(condition, label) {
	checks++;
	if (!condition) {
		failures++;
		console.log('FAILED: ' + label);
	}
}

function listen(handler) {
	return new Promise((resolve) => {
		const server = http.createServer(handler);
		server.listen(0, '127.0.0.1', () => resolve(server));
	});
}

// Herkunft A: die Seite, jQuery und eine Skript-Antwort derselben Herkunft
function pageHandler(req, res) {
	const url = new URL(req.url, 'http://127.0.0.1');
	if (url.pathname === '/harness') {
		const lib = url.searchParams.get('lib');
		if (LIBS.indexOf(lib) === -1) {
			res.writeHead(404);
			res.end();
			return;
		}
		res.writeHead(200, { 'Content-Type': 'text/html' });
		res.end('<!doctype html><meta charset="utf-8"><script src="/' + lib + '"></script>'
			+ '<script>window.__xss = 0; window.__same = 0; window.__explicit = 0;</script>');
		return;
	}
	if (url.pathname === '/same.js') {
		res.writeHead(200, { 'Content-Type': 'text/javascript' });
		res.end('window.__same = 1;');
		return;
	}
	const file = path.join(ROOT, decodeURIComponent(url.pathname));
	if (!file.startsWith(ROOT + path.sep) || LIBS.indexOf(path.relative(ROOT, file).split(path.sep).join('/')) === -1) {
		res.writeHead(404);
		res.end();
		return;
	}
	res.writeHead(200, { 'Content-Type': 'application/javascript' });
	res.end(fs.readFileSync(file));
}

// Herkunft B: fremde Gegenstelle, die Skript ausliefert und CORS erlaubt
function foreignHandler(req, res) {
	const url = new URL(req.url, 'http://127.0.0.1');
	const headers = { 'Access-Control-Allow-Origin': '*' };
	if (url.pathname === '/evil') {
		res.writeHead(200, Object.assign(headers, { 'Content-Type': 'text/javascript' }));
		res.end('window.__xss = 1;');
	} else if (url.pathname === '/explicit') {
		res.writeHead(200, Object.assign(headers, { 'Content-Type': 'text/javascript' }));
		res.end('window.__explicit = 1;');
	} else if (url.pathname === '/data') {
		res.writeHead(200, Object.assign(headers, { 'Content-Type': 'application/json' }));
		res.end('{"ok":true}');
	} else {
		res.writeHead(404, headers);
		res.end();
	}
}

async function run(page, base, foreign, lib) {
	await page.goto(base + '/harness?lib=' + encodeURIComponent(lib), { waitUntil: 'load' });

	const extend = await page.evaluate(() => {
		const $ = window.jQuery;
		$.extend(true, {}, JSON.parse('{"__proto__": {"polluted": "yes"}}'));
		const deep = $.extend(true, { a: { b: 1 }, l: [1, 2] }, { a: { c: 2 }, l: [3] });
		const shallow = $.extend({}, { x: 1 }, { y: 2 });
		const result = {
			version: $.fn.jquery,
			polluted: ({}).polluted,
			deep: JSON.stringify(deep),
			shallow: JSON.stringify(shallow)
		};
		delete Object.prototype.polluted;
		return result;
	});

	// Jede Anfrage einzeln abwarten, damit eine ausgeführte Antwort sicher vor
	// dem Ablesen der Marker angekommen ist.
	const ajax = await page.evaluate((foreignBase) => {
		const $ = window.jQuery;
		const call = (options) => new Promise((resolve) => {
			$.ajax(options)
				.done((data, status, xhr) => resolve({ ok: true, type: typeof data, data: typeof data === 'string' ? data : JSON.stringify(data) }))
				.fail((xhr, status, error) => resolve({ ok: false, status: status, error: String(error) }));
		});
		return (async () => {
			const crossNoType = await call({ url: foreignBase + '/evil' });
			const sameNoType = await call({ url: '/same.js' });
			const crossScript = await call({ url: foreignBase + '/explicit', dataType: 'script' });
			const crossJson = await call({ url: foreignBase + '/data' });
			return {
				crossNoType: crossNoType,
				sameNoType: sameNoType,
				crossScript: crossScript,
				crossJson: crossJson,
				xss: window.__xss,
				same: window.__same,
				explicit: window.__explicit
			};
		})();
	}, foreign);

	// jquery.min.js ist vollständig neu erzeugt. Deshalb auch die frühere
	// Korrektur (CVE-2020-11022) und die Grundfunktionen mitprüfen, ein kaputter
	// Teil der Bibliothek fiele im Diff einer minifizierten Datei nicht auf.
	const smoke = await page.evaluate(() => new Promise((resolve) => {
		const $ = window.jQuery;
		const host = $('<div id="host"></div>').appendTo(document.body);
		host.html('<style><style /><img src=x onerror="window.__xss = 2"></style>');
		const built = $('<ul><li class="a">1</li><li class="b">2</li><li class="a">3</li></ul>').appendTo(document.body);
		let clicks = 0;
		built.on('click', 'li.a', () => { clicks++; });
		built.find('li').trigger('click');
		built.find('.b').addClass('c').attr('data-x', 'y').css('width', '10px');
		setTimeout(() => resolve({
			xss: window.__xss,
			imgs: document.querySelectorAll('img[src="x"]').length,
			clicks: clicks,
			selected: built.find('li:not(.b)').length,
			text: built.children().eq(1).text(),
			classes: built.find('.b').attr('class'),
			data: built.find('.c').data('x'),
			width: built.find('.c')[0].style.width,
			html: $('<div/>').html('<p><b>x</b></p>').find('b').text(),
			each: $.map([1, 2, 3], (v) => v * 2).join(','),
			deferred: $.when($.Deferred().resolve(1)).state()
		}), 300);
	}));

	console.log('  ' + lib);
	console.log('    extend: ' + JSON.stringify(extend));
	console.log('    ajax:   ' + JSON.stringify(ajax));
	console.log('    smoke:  ' + JSON.stringify(smoke));

	check(smoke.xss === 0 && smoke.imgs === 0, lib + ': self-closing tags are still not expanded (CVE-2020-11022)');
	check(smoke.clicks === 2, lib + ': delegated events still fire');
	check(smoke.selected === 2 && smoke.text === '2', lib + ': selectors and traversal still work');
	check(smoke.classes === 'b c' && smoke.data === 'y' && smoke.width === '10px', lib + ': class, attr, data and css still work');
	check(smoke.html === 'x' && smoke.each === '2,4,6' && smoke.deferred === 'resolved', lib + ': html, map and Deferred still work');

	check(extend.version === '2.1.4', lib + ': the library still reports its version');
	check(extend.polluted === undefined, lib + ': $.extend(true) does not pollute Object.prototype (CVE-2019-11358)');
	check(extend.deep === '{"a":{"b":1,"c":2},"l":[3,2]}', lib + ': deep $.extend still merges objects and arrays');
	check(extend.shallow === '{"x":1,"y":2}', lib + ': shallow $.extend still copies');

	check(ajax.xss === 0, lib + ': a cross-domain script response without dataType is not executed (CVE-2015-9251)');
	check(ajax.crossNoType.ok === true && ajax.crossNoType.data === 'window.__xss = 1;', lib + ': that response still arrives as text');
	check(ajax.same === 1, lib + ': a same-origin script response without dataType still runs (as in jQuery 3)');
	check(ajax.explicit === 1 && ajax.crossScript.ok === true, lib + ': cross-domain dataType "script" still runs');
	check(ajax.crossJson.ok === true && ajax.crossJson.data === '{"ok":true}', lib + ': cross-domain JSON is still parsed');
}

(async () => {
	const server = await listen(pageHandler);
	const foreignServer = await listen(foreignHandler);
	const base = 'http://127.0.0.1:' + server.address().port;
	const foreign = 'http://127.0.0.1:' + foreignServer.address().port;
	// CHROMIUM_EXECUTABLE, wenn die Browser-Fassung nicht zur Playwright-Fassung passt
	const browser = await chromium.launch(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {});
	const page = await browser.newPage();

	page.on('pageerror', (e) => {
		failures++;
		console.log('FAILED: page error: ' + e.message);
	});

	try {
		for (const lib of LIBS) {
			await run(page, base, foreign, lib);
		}
	} finally {
		await browser.close();
		server.close();
		foreignServer.close();
	}

	if (failures === 0) {
		console.log('jQuery CVE OK (' + checks + ' checks, both shipped builds)');
	}
	process.exit(failures === 0 ? 0 : 1);
})();
