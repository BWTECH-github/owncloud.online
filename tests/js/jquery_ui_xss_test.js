/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * jQuery UI 1.10.0 - CVE-2021-41182, -41183, -41184.
 *
 * Alle drei laufen darauf hinaus, dass eine Option, die als Text oder als
 * Auswahlausdruck gemeint ist, als Markup gebaut wird. Ob die Kette
 * unterbrochen ist, laesst sich nicht am Quelltext ablesen, sondern nur
 * daran, was im DOM ankommt - deshalb ein echtes Chromium.
 *
 * Geprueft werden beide Fassungen: die ausgelieferte
 * ui/minified/jquery-ui.custom.min.js (core/js/core.json) und die Quelle
 * ui/jquery-ui.custom.js.
 *
 *   node tests/js/jquery_ui_xss_test.js
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
	await page.goto(base + '/tests/js/jquery-ui-xss-harness.html?lib=' + encodeURIComponent(lib), { waitUntil: 'load' });
	await page.waitForFunction('window.__ready === true');

	// Der Kalender haengt an einem einzigen #ui-datepicker-div, das sich alle
	// Instanzen teilen. Was er gezeichnet hat, muss deshalb abgelesen werden,
	// bevor die naechste Instanz entsteht.
	await page.evaluate('window.__openHostileDatepicker()');
	await page.waitForTimeout(150);
	const drawn = await page.evaluate(() => {
		const div = document.getElementById('ui-datepicker-div');
		return {
			// Der Text muss ankommen - sonst beweist ein leerer Kalender nichts.
			textSeen: !!div && div.textContent.indexOf('<img src=x') !== -1,
			appendTextSeen: (document.querySelector('.ui-datepicker-append') || {}).textContent === '<img src=x onerror="window.__xss=1">',
			datepickerRendered: document.querySelectorAll('#ui-datepicker-div td a').length
		};
	});

	await page.evaluate('window.__hostileAltField()');
	await page.evaluate('window.__hostilePosition()');
	await page.waitForTimeout(200);

	const hostile = await page.evaluate(() => ({
		xss: window.__xss,
		// Beide Optionen sind als Auswahlausdruck gemeint. Eine Zeichenkette,
		// die keiner ist, muss zurueckgewiesen werden - jQuery UI 1.13 wirft
		// dabei, und das ist die richtige Antwort.
		altFieldRejected: typeof window.__altFieldThrew === 'string',
		positionRejected: typeof window.__positionThrew === 'string',
		injectedImages: document.querySelectorAll('img[src="x"]').length,
	}));

	console.log('  ' + lib);
	console.log('    drawn:   ' + JSON.stringify(drawn));
	console.log('    hostile: ' + JSON.stringify(hostile));

	check(hostile.xss === 0, lib + ': nothing executed from the datepicker, altField or position options');
	check(hostile.injectedImages === 0, lib + ': no img element was built from any of the three options');
	check(drawn.textSeen === true, lib + ': the hostile text arrives as text (the calendar really rendered)');
	check(drawn.appendTextSeen === true, lib + ': appendText arrives as text');
	check(drawn.datepickerRendered > 27, lib + ': the calendar still draws its days');
	check(hostile.altFieldRejected === true, lib + ': a non-selector altField is rejected, not built (CVE-2021-41182)');
	check(hostile.positionRejected === true, lib + ": a non-selector position({of}) is rejected, not built (CVE-2021-41184)");

	const plain = await page.evaluate('window.__plainDatepicker()');
	const pos = await page.evaluate('window.__plainPosition()');
	console.log('    plain:   ' + JSON.stringify(plain));
	console.log('    position:' + JSON.stringify(pos));

	check(plain.value === '22-09-2026', lib + ': the datepicker still writes the selected date');
	check(plain.monthOptions === 12, lib + ': the month select still has twelve entries');
	check(plain.firstMonthOption === 'Jan', lib + ': localized month names still render');
	check(plain.dayHeaders === 'SoMoDiMiDoFrSa', lib + ': localized day headers still render');
	check(plain.dayHeaderTitle === 'Sonntag', lib + ': the day header title attribute still carries the long name');
	check(plain.weekHeader.length > 0, lib + ': the week column still renders');
	check(plain.prevTitle === 'Zurueck', lib + ': the navigation title attribute still carries its text');
	check(plain.days > 27, lib + ': the calendar still draws its days');
	check(pos.dx === 0 && pos.dy === 0, lib + ': position({of: selector}) still aligns the element');

	// Der Fix hat jquery-ui.custom.min.js neu erzeugt, und diese Datei wird auf
	// jeder Seite ausgeliefert. Die Widgets, die im Produkt in Gebrauch sind,
	// werden deshalb mitgeprueft - ein kaputtes Widget faellt in einem Diff
	// nicht auf.
	const widgets = await page.evaluate('window.__widgetSmoke()');
	console.log('    widgets: ' + JSON.stringify(widgets));

	check(widgets.version === '1.10.0', lib + ': the library still reports its version');
	check(widgets.tabsActive === 0 && widgets.tabsSwitched === 1, lib + ': tabs still switch');
	check(widgets.tabsPanelVisible === true, lib + ': the activated tab panel is shown');
	check(widgets.buttonClass === true, lib + ': button still decorates');
	check(widgets.buttonsetClass === true, lib + ': buttonset still decorates');
	check(widgets.progressValue === 42, lib + ': progressbar still keeps its value');
	check(widgets.progressWidth === 1, lib + ': progressbar still draws its bar');
	check(widgets.acItems === 2, lib + ': autocomplete still lists its suggestions');
	check(widgets.acFirstText === '<b>roh</b>', lib + ': autocomplete shows a suggestion as text');
	check(widgets.acBoldTags === 0, lib + ': autocomplete builds no markup from a suggestion');
	check(widgets.draggableClass === true, lib + ': draggable still initialises');
	check(widgets.droppableClass === true, lib + ': droppable still initialises');
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
		for (const lib of ['ui/jquery-ui.custom.js', 'ui/minified/jquery-ui.custom.min.js']) {
			await run(page, base, lib);
		}
	} finally {
		await browser.close();
		server.close();
	}

	if (failures === 0) {
		console.log('jQuery UI XSS OK (' + checks + ' checks, both shipped builds)');
	}
	process.exit(failures === 0 ? 0 : 1);
})();
