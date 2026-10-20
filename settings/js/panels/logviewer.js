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

/**
 * Protokollansicht unter Einstellungen > Allgemein.
 *
 * Die Einträge kommen von /settings/admin/log/entries. Der Server liest
 * owncloud.log von hinten und gibt mit next_offset die Byte-Position zurück,
 * ab der „Mehr laden“ weiterliest. Stufe, App und Suche filtert der Server.
 */
(function (OC, $, moment) {
	'use strict';

	var PAGE_SIZE = 50;
	// Eine Seite ohne Treffer heißt nur, dass der Server sein Lesebudget
	// verbraucht hat. Die Ansicht liest dann von selbst weiter, höchstens so oft.
	var MAX_EMPTY_PAGES = 3;
	var DEBOUNCE_MS = 400;
	var SUMMARY_LENGTH = 300;

	function levelLabel(level) {
		switch (level) {
			case 0: return t('settings', 'Debug');
			case 1: return t('settings', 'Info');
			case 2: return t('settings', 'Warning');
			case 3: return t('settings', 'Error');
			case 4: return t('settings', 'Fatal');
			default: return '–';
		}
	}

	function formatTime(value) {
		var parsed = moment(value, moment.ISO_8601, true);
		return parsed.isValid() ? parsed.format('L LTS') : (value || '');
	}

	function isPlainExceptionPrefix(message) {
		return message === 'Exception' || message === 'Caused by';
	}

	/**
	 * Kurzfassung für die Tabelle: bei Ausnahmen Klasse und Meldung.
	 */
	function summary(entry) {
		var text = entry.message || '';
		if (entry.exception && entry.exception.message) {
			text = entry.exception['class'] + ': ' + entry.exception.message;
		} else if (entry.exception && isPlainExceptionPrefix(text)) {
			text = entry.exception['class'];
		}
		text = $.trim(text.replace(/\s+/g, ' '));
		if (text.length > SUMMARY_LENGTH) {
			text = text.substr(0, SUMMARY_LENGTH) + '…';
		}
		return text || '–';
	}

	function requestText(entry) {
		var parts = [];
		if (entry.method && entry.method !== '--') {
			parts.push(entry.method);
		}
		if (entry.url && entry.url !== '--') {
			parts.push(entry.url);
		}
		return parts.join(' ');
	}

	function exceptionText(exception) {
		var text = exception['class'];
		if (exception.code !== '' && exception.code !== '0' && exception.code !== undefined) {
			text += ' (' + exception.code + ')';
		}
		if (exception.message) {
			text += ': ' + exception.message;
		}
		if (exception.file) {
			text += '\n' + exception.file + (exception.line !== null && exception.line !== undefined ? ':' + exception.line : '');
		}
		return text;
	}

	/**
	 * @param {jQuery} $root Element #log-viewer aus panels/admin/logging.php
	 */
	var LogViewer = function ($root) {
		this.$root = $root;
		this.$form = $root.find('#log-viewer-filter');
		this.$level = $root.find('#log-viewer-level');
		this.$app = $root.find('#log-viewer-app');
		this.$apps = $root.find('#log-viewer-apps');
		this.$search = $root.find('#log-viewer-search');
		this.$status = $root.find('#log-viewer-status');
		this.$table = $root.find('#log-viewer-table');
		this.$tbody = this.$table.find('tbody');
		this.$more = $root.find('#log-viewer-more');

		this._request = null;
		this._generation = 0;
		this._nextOffset = null;
		this._shown = 0;
		this._rowCounter = 0;
		// App-Namen stammen aus dem Protokoll – ohne Prototyp, damit „constructor“
		// oder „__proto__“ als Name nichts durcheinanderbringen.
		this._apps = Object.create(null);
		this._timer = null;

		this._bind();
		this.reload();
	};

	LogViewer.prototype = {
		_bind: function () {
			var self = this;
			this.$form.on('submit', function (event) {
				event.preventDefault();
				self.reload();
			});
			this.$level.on('change', function () {
				self.reload();
			});
			this.$app.add(this.$search).on('input', function () {
				clearTimeout(self._timer);
				self._timer = setTimeout(function () {
					self.reload();
				}, DEBOUNCE_MS);
			});
			this.$more.on('click', function () {
				self.loadMore();
			});
			this.$tbody.on('click', '.log-viewer-toggle', function () {
				self._toggle($(this));
			});
		},

		/**
		 * @return {{level: string, app: string, search: string}}
		 */
		getFilters: function () {
			return {
				level: this.$level.val() || '',
				app: $.trim(this.$app.val() || ''),
				search: $.trim(this.$search.val() || '')
			};
		},

		/**
		 * Lädt die neuesten Einträge neu – nach einem Filterwechsel oder auf Knopfdruck.
		 */
		reload: function () {
			clearTimeout(this._timer);
			this._fetch(0, false, MAX_EMPTY_PAGES);
		},

		loadMore: function () {
			if (this._nextOffset !== null && this._request === null) {
				this._fetch(this._nextOffset, true, MAX_EMPTY_PAGES);
			}
		},

		_fetch: function (offset, append, emptyPagesLeft) {
			var self = this;
			var generation = ++this._generation;
			if (this._request) {
				this._request.abort();
			}
			var params = $.extend({count: PAGE_SIZE}, this.getFilters());
			if (offset > 0) {
				params.offset = offset;
			}

			this._setBusy(true);
			this._setStatus(t('settings', 'Loading …'));
			this._request = $.ajax({
				url: OC.generateUrl('/settings/admin/log/entries'),
				type: 'GET',
				dataType: 'json',
				data: params
			}).done(function (data) {
				if (generation !== self._generation) {
					return;
				}
				self._request = null;
				var entries = (data && data.entries) || [];
				if (!append || data.restarted) {
					self._clear();
				}
				self._render(entries);
				self._nextOffset = (data && typeof data.next_offset === 'number' && data.next_offset > 0) ? data.next_offset : null;
				if (entries.length === 0 && self._nextOffset !== null && emptyPagesLeft > 1) {
					self._fetch(self._nextOffset, true, emptyPagesLeft - 1);
					return;
				}
				self._finish();
			}).fail(function (xhr, status) {
				if (generation !== self._generation || status === 'abort') {
					return;
				}
				self._request = null;
				self._setBusy(false);
				self._setStatus(t('settings', 'Could not load the log.'));
			});
		},

		_clear: function () {
			this.$tbody.empty();
			this._shown = 0;
			this._nextOffset = null;
		},

		_render: function (entries) {
			var self = this;
			var newApps = false;
			$.each(entries, function (index, entry) {
				self.$tbody.append(self._renderRows(entry));
				self._shown++;
				if (entry.app && !self._apps[entry.app]) {
					self._apps[entry.app] = true;
					newApps = true;
				}
			});
			if (newApps) {
				this.$apps.empty();
				$.each(Object.keys(this._apps).sort(), function (index, app) {
					self.$apps.append($('<option>').attr('value', app));
				});
			}
		},

		_finish: function () {
			var moreHadFocus = this.$more.is(':focus');
			this._setBusy(false);
			this.$more.toggleClass('hidden', this._nextOffset === null);

			var status;
			if (this._shown === 0) {
				status = t('settings', 'No matching log entries.');
			} else {
				status = t('settings', 'Entries shown: {count}', {count: this._shown});
				if (this._nextOffset === null) {
					status += ' – ' + t('settings', 'No older entries.');
				}
			}
			this._setStatus(status);

			if (moreHadFocus && this._nextOffset === null) {
				// Der Knopf verschwindet – den Fokus auf den ältesten Eintrag setzen,
				// statt ihn ins Leere fallen zu lassen.
				this.$tbody.find('.log-viewer-toggle').last().trigger('focus');
			}
		},

		/**
		 * Der Knopf „Mehr laden“ bleibt während des Ladens bedienbar und fokussiert;
		 * ein zweiter Klick wird in loadMore() ignoriert. Ein deaktivierter Knopf
		 * verlöre den Tastaturfokus.
		 */
		_setBusy: function (busy) {
			this.$table.attr('aria-busy', busy ? 'true' : 'false');
		},

		_setStatus: function (text) {
			this.$status.text(text);
		},

		_toggle: function ($button) {
			var expanded = $button.attr('aria-expanded') === 'true';
			$button.attr('aria-expanded', expanded ? 'false' : 'true');
			this.$tbody.find('#' + $button.attr('aria-controls')).toggleClass('hidden', expanded);
		},

		/**
		 * Zeile mit Knopf in der Meldungsspalte plus aufklappbare Detailzeile.
		 * Alle Werte kommen als Text in die Seite, nie als HTML.
		 */
		_renderRows: function (entry) {
			var detailsId = 'log-viewer-details-' + (++this._rowCounter);
			var level = typeof entry.level === 'number' ? entry.level : null;
			var $row = $('<tr>').addClass('log-viewer-row');

			$('<td>').addClass('log-viewer-time')
				.append($('<time>').attr('datetime', entry.time || '').text(formatTime(entry.time)))
				.appendTo($row);
			$('<td>').addClass('log-viewer-level')
				.append($('<span>').addClass('log-viewer-badge log-level-' + (level === null ? 'unknown' : level)).text(levelLabel(level)))
				.appendTo($row);
			$('<td>').addClass('log-viewer-app').text(entry.app || '').appendTo($row);
			$('<td>').addClass('log-viewer-message')
				.append($('<button>').attr({
					type: 'button',
					'class': 'log-viewer-toggle',
					'aria-expanded': 'false',
					'aria-controls': detailsId
				}).text(summary(entry)))
				.appendTo($row);
			$('<td>').addClass('log-viewer-user').text(entry.user && entry.user !== '--' ? entry.user : '').appendTo($row);
			// In der Tabelle gekürzt, vollständig in den Details
			$('<td>').addClass('log-viewer-request')
				.append($('<span>').attr('title', requestText(entry)).text(requestText(entry)))
				.appendTo($row);

			var $details = $('<tr>').addClass('log-viewer-details hidden').attr('id', detailsId);
			$('<td>').attr('colspan', 6).append(this._renderDetails(entry)).appendTo($details);
			return $row.add($details);
		},

		_renderDetails: function (entry) {
			var $list = $('<dl>').addClass('log-viewer-detail');
			var add = function (label, value, preformatted) {
				if (value === null || value === undefined || value === '') {
					return;
				}
				$('<dt>').text(label).appendTo($list);
				var $value = $('<dd>').appendTo($list);
				if (preformatted) {
					$('<pre>').text(value).appendTo($value);
				} else {
					$value.text(value);
				}
			};

			if (!(entry.exception && isPlainExceptionPrefix(entry.message))) {
				add(t('settings', 'Message'), entry.message);
			}
			if (entry.exception) {
				add(t('settings', 'Exception'), exceptionText(entry.exception));
				add(t('settings', 'Trace'), entry.exception.trace, true);
			}
			$.each(entry.previous || [], function (index, cause) {
				add(t('settings', 'Caused by'), exceptionText(cause));
				add(t('settings', 'Trace'), cause.trace, true);
			});
			add(t('settings', 'Request'), requestText(entry));
			add(t('settings', 'Request ID'), entry.reqId);
			add(t('settings', 'Remote address'), entry.remoteAddr);
			return $list;
		}
	};

	OC.Settings = OC.Settings || {};
	OC.Settings.LogViewer = LogViewer;

	$(document).ready(function () {
		var $root = $('#log-viewer');
		if ($root.length) {
			OC.Settings.logViewer = new LogViewer($root);
		}
	});
})(OC, jQuery, moment);
