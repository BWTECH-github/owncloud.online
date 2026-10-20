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
 */

describe('OC.Settings.LogViewer', function() {
	var $root;

	// Nachbau des Ausschnitts aus settings/templates/panels/admin/logging.php
	function fixture() {
		return $(
			'<div id="log-viewer">' +
				'<h3 id="log-viewer-heading">Log entries</h3>' +
				'<form id="log-viewer-filter" role="search">' +
					'<select id="log-viewer-level"><option value="">all</option><option value="3">errors</option></select>' +
					'<input type="text" id="log-viewer-app" list="log-viewer-apps">' +
					'<datalist id="log-viewer-apps"></datalist>' +
					'<input type="search" id="log-viewer-search">' +
					'<button type="submit" id="log-viewer-refresh">Refresh</button>' +
				'</form>' +
				'<p id="log-viewer-status" role="status" aria-live="polite"></p>' +
				'<table id="log-viewer-table"><thead></thead><tbody></tbody></table>' +
				'<button type="button" id="log-viewer-more" class="hidden">Load more</button>' +
			'</div>'
		);
	}

	function lastRequest() {
		return fakeServer.requests[fakeServer.requests.length - 1];
	}

	function respond(request, data, status) {
		request.respond(status || 200, {'Content-Type': 'application/json'}, JSON.stringify(data));
	}

	function page(entries, nextOffset, restarted) {
		return {
			entries: entries,
			next_offset: nextOffset === undefined ? null : nextOffset,
			restarted: !!restarted,
			size: 1000,
			scanned: 1000
		};
	}

	function entry(message, extra) {
		return $.extend({
			offset: 0,
			reqId: 'req-1',
			level: 2,
			time: '2026-10-19T20:35:05+02:00',
			remoteAddr: '192.0.2.10',
			user: 'alice',
			app: 'core',
			method: 'PROPFIND',
			url: '/remote.php/dav/files/alice/a.txt',
			message: message,
			exception: null,
			previous: [],
			truncated: false
		}, extra || {});
	}

	var exceptionEntry = entry('Exception', {
		level: 4,
		app: 'webdav',
		reqId: 'abc123',
		exception: {
			'class': 'RuntimeException',
			message: 'Sitzung weg',
			code: '0',
			file: '/var/www/remote.php',
			line: 198,
			trace: '#0 /var/www/remote.php(198): handleException()\n#1 {main}'
		},
		previous: [{
			'class': 'PDOException',
			message: 'Too many connections',
			code: '1040',
			file: '/var/www/lib/private/DB/Connection.php',
			line: 12,
			trace: '#0 {main}'
		}]
	});

	function rows() {
		return $root.find('tbody tr.log-viewer-row');
	}

	beforeEach(function() {
		$root = fixture();
		$('#testArea').append($root);
		new OC.Settings.LogViewer($root);
	});

	afterEach(function() {
		$root.remove();
	});

	it('asks for the newest entries right away and says it is loading', function() {
		expect(fakeServer.requests.length).toEqual(1);
		var request = fakeServer.requests[0];
		expect(request.method).toEqual('GET');
		expect(request.url.indexOf(OC.generateUrl('/settings/admin/log/entries') + '?')).toEqual(0);
		expect(OC.parseQueryString(request.url)).toEqual({count: '50', level: '', app: '', search: ''});
		expect($root.find('#log-viewer-status').text()).toEqual('Loading …');
		expect($root.find('#log-viewer-table').attr('aria-busy')).toEqual('true');
	});

	it('renders every value as text', function() {
		respond(lastRequest(), page([entry('<img src="x" onerror="window.__logViewerXss = 1">', {user: '--'}), exceptionEntry]));

		expect(rows().length).toEqual(2);
		expect($root.find('img').length).toEqual(0);
		expect(window.__logViewerXss).toBeUndefined();

		var $first = rows().eq(0);
		expect($first.find('.log-viewer-toggle').text()).toEqual('<img src="x" onerror="window.__logViewerXss = 1">');
		expect($first.find('.log-viewer-badge').text()).toEqual('Warning');
		expect($first.find('.log-viewer-badge').hasClass('log-level-2')).toEqual(true);
		expect($first.find('td.log-viewer-user').text()).toEqual('');
		expect($first.find('td.log-viewer-request').text()).toEqual('PROPFIND /remote.php/dav/files/alice/a.txt');
		expect($first.find('time').attr('datetime')).toEqual('2026-10-19T20:35:05+02:00');

		var $second = rows().eq(1);
		expect($second.find('.log-viewer-toggle').text()).toEqual('RuntimeException: Sitzung weg');
		expect($second.find('.log-viewer-badge').text()).toEqual('Fatal');
		expect($second.find('td.log-viewer-app').text()).toEqual('webdav');

		expect($root.find('#log-viewer-status').text()).toEqual('Entries shown: 2 – No older entries.');
		expect($root.find('#log-viewer-table').attr('aria-busy')).toEqual('false');
		expect($root.find('#log-viewer-apps option').map(function() {
			return this.value;
		}).get()).toEqual(['core', 'webdav']);
	});

	it('opens and closes the details of an entry', function() {
		respond(lastRequest(), page([exceptionEntry]));

		var $toggle = rows().eq(0).find('.log-viewer-toggle');
		var $details = $root.find('#' + $toggle.attr('aria-controls'));
		expect($toggle.is('button')).toEqual(true);
		expect($toggle.attr('aria-expanded')).toEqual('false');
		expect($details.hasClass('hidden')).toEqual(true);

		$toggle.trigger('click');
		expect($toggle.attr('aria-expanded')).toEqual('true');
		expect($details.hasClass('hidden')).toEqual(false);

		var labels = $details.find('dt').map(function() {
			return $(this).text();
		}).get();
		expect(labels).toEqual(['Exception', 'Trace', 'Caused by', 'Trace', 'Request', 'Request ID', 'Remote address']);
		var values = $details.find('dd');
		expect(values.eq(0).text()).toEqual('RuntimeException: Sitzung weg\n/var/www/remote.php:198');
		expect(values.eq(1).find('pre').text()).toEqual('#0 /var/www/remote.php(198): handleException()\n#1 {main}');
		expect(values.eq(2).text()).toEqual('PDOException (1040): Too many connections\n/var/www/lib/private/DB/Connection.php:12');
		expect(values.eq(5).text()).toEqual('abc123');

		$toggle.trigger('click');
		expect($toggle.attr('aria-expanded')).toEqual('false');
		expect($details.hasClass('hidden')).toEqual(true);
	});

	it('loads older entries from the returned offset', function() {
		respond(lastRequest(), page([entry('neu')], 1234));
		var $more = $root.find('#log-viewer-more');
		expect($more.hasClass('hidden')).toEqual(false);
		expect($root.find('#log-viewer-status').text()).toEqual('Entries shown: 1');

		$more.trigger('click');
		// Ein zweiter Klick während des Ladens schickt nichts doppelt.
		$more.trigger('click');
		expect(fakeServer.requests.length).toEqual(2);
		expect(OC.parseQueryString(lastRequest().url).offset).toEqual('1234');

		respond(lastRequest(), page([entry('alt')]));
		expect(rows().length).toEqual(2);
		expect(rows().eq(1).find('.log-viewer-toggle').text()).toEqual('alt');
		expect($more.hasClass('hidden')).toEqual(true);
		expect($root.find('#log-viewer-status').text()).toEqual('Entries shown: 2 – No older entries.');
	});

	it('starts over when the log file was rotated in between', function() {
		respond(lastRequest(), page([entry('vor der Rotation')], 1234));
		$root.find('#log-viewer-more').trigger('click');
		respond(lastRequest(), page([entry('nach der Rotation')], null, true));

		expect(rows().length).toEqual(1);
		expect(rows().eq(0).find('.log-viewer-toggle').text()).toEqual('nach der Rotation');
	});

	it('sends the filters and replaces the table', function() {
		respond(lastRequest(), page([entry('vorher')]));

		$root.find('#log-viewer-level').val('3').trigger('change');
		expect(OC.parseQueryString(lastRequest().url).level).toEqual('3');
		respond(lastRequest(), page([entry('Fehler', {level: 3})]));

		$root.find('#log-viewer-app').val(' webdav ');
		$root.find('#log-viewer-search').val('Too many');
		$root.find('#log-viewer-filter').trigger('submit');
		expect(OC.parseQueryString(lastRequest().url)).toEqual({count: '50', level: '3', app: 'webdav', search: 'Too many'});
		respond(lastRequest(), page([exceptionEntry]));

		expect(rows().length).toEqual(1);
		expect(rows().eq(0).find('.log-viewer-toggle').text()).toEqual('RuntimeException: Sitzung weg');
	});

	it('ignores the answer to a request that a newer one replaced', function() {
		var first = lastRequest();
		$root.find('#log-viewer-filter').trigger('submit');
		expect(fakeServer.requests.length).toEqual(2);
		expect(first.aborted).toEqual(true);

		respond(lastRequest(), page([entry('aktuell')]));
		expect(rows().length).toEqual(1);
		expect(rows().eq(0).find('.log-viewer-toggle').text()).toEqual('aktuell');
	});

	it('keeps reading when a page only used up the scan budget', function() {
		respond(lastRequest(), page([], 500));
		expect(fakeServer.requests.length).toEqual(2);
		expect(OC.parseQueryString(lastRequest().url).offset).toEqual('500');
		expect($root.find('#log-viewer-status').text()).toEqual('Loading …');

		respond(lastRequest(), page([], null));
		expect($root.find('#log-viewer-status').text()).toEqual('No matching log entries.');
		expect($root.find('#log-viewer-more').hasClass('hidden')).toEqual(true);
	});

	it('stops reading on its own after a few empty pages', function() {
		respond(lastRequest(), page([], 900));
		respond(lastRequest(), page([], 600));
		respond(lastRequest(), page([], 300));
		expect(fakeServer.requests.length).toEqual(3);
		expect($root.find('#log-viewer-status').text()).toEqual('No matching log entries.');
		expect($root.find('#log-viewer-more').hasClass('hidden')).toEqual(false);
	});

	it('reports a failed request', function() {
		respond(lastRequest(), {message: 'Too many requests'}, 429);
		expect($root.find('#log-viewer-status').text()).toEqual('Could not load the log.');
		expect($root.find('#log-viewer-table').attr('aria-busy')).toEqual('false');
	});
});
