/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * Update-Seite (core/js/update.js): „Detaillierte Protokolle“ ist ein Anker mit
 * role="button" und muss wie ein Knopf bedienbar sein und seinen Zustand melden;
 * „Aktualisierung starten“ blendet sich selbst aus, der Fokus darf dabei nicht
 * verloren gehen, und Fortschritt und Ergebnis müssen Hilfsmitteln gemeldet werden.
 */

describe('OC.Update detailed logs toggle', function() {
	var $link, $details;

	beforeEach(function() {
		// Ausschnitt aus core/templates/update.admin.php
		$('#testArea').append(
			'<div class="update-progress">' +
			'<a class="update-show-detailed" href="#" role="button" aria-expanded="false"' +
			' aria-controls="update-progress-detailed">Detailed logs</a>' +
			'<div id="update-progress-detailed" class="hidden warning"></div>' +
			'</div>'
		);
		$link = $('.update-show-detailed');
		$details = $('#update-progress-detailed');
	});

	/**
	 * Echtes Tastaturereignis statt $.Event: Nur so kommt event.repeat an, das
	 * der Browser bei gedrückt gehaltener Taste setzt.
	 */
	function key(type, name, repeat) {
		var event = new KeyboardEvent(type, {key: name, repeat: !!repeat, bubbles: true, cancelable: true});
		$link[0].dispatchEvent(event);
		return event;
	}

	it('opens and closes on click and keeps aria-expanded in sync', function() {
		$link.click();
		expect($details.hasClass('hidden')).toEqual(false);
		expect($link.attr('aria-expanded')).toEqual('true');

		$link.click();
		expect($details.hasClass('hidden')).toEqual(true);
		expect($link.attr('aria-expanded')).toEqual('false');
	});

	it('toggles when space is released, like a button, without scrolling', function() {
		var down = key('keydown', ' ');
		expect(down.defaultPrevented).toEqual(true);
		// Wie beim nativen Knopf löst erst das Loslassen aus.
		expect($details.hasClass('hidden')).toEqual(true);

		key('keyup', ' ');
		expect($details.hasClass('hidden')).toEqual(false);
		expect($link.attr('aria-expanded')).toEqual('true');
	});

	it('toggles only once while space is held down', function() {
		key('keydown', ' ');
		var repeats = [key('keydown', ' ', true), key('keydown', ' ', true), key('keydown', ' ', true)];
		key('keyup', ' ');

		expect(repeats.every(function(event) { return event.defaultPrevented; })).toEqual(true);
		expect($details.hasClass('hidden')).toEqual(false);
		expect($link.attr('aria-expanded')).toEqual('true');
	});

	it('ignores a space release that was not pressed on the toggle', function() {
		key('keyup', ' ');

		expect($details.hasClass('hidden')).toEqual(true);
		expect($link.attr('aria-expanded')).toEqual('false');
	});

	it('does not toggle when focus leaves before space is released', function() {
		key('keydown', ' ');
		$link.trigger('focusout');
		key('keyup', ' ');

		expect($details.hasClass('hidden')).toEqual(true);
		expect($link.attr('aria-expanded')).toEqual('false');
	});

	it('ignores other keys', function() {
		var down = key('keydown', 'a');
		key('keyup', 'a');

		expect(down.defaultPrevented).toEqual(false);
		expect($details.hasClass('hidden')).toEqual(true);
		expect($link.attr('aria-expanded')).toEqual('false');
	});
});

describe('OC.Update start', function() {
	var source, clock;

	beforeEach(function() {
		// Ausschnitt aus core/templates/update.admin.php
		$('#testArea').append(
			'<div class="update" data-productname="owncloud.online" data-version="11.0.31">' +
			'<div class="updateOverview">' +
			'<input class="updateButton" type="button" value="Start update">' +
			'</div>' +
			'<div class="update-progress hidden">' +
			'<h2 id="update-progress-title" tabindex="-1"></h2>' +
			'<div id="update-progress-icon" class="icon-loading-dark"></div>' +
			'<p id="update-progress-message-error" class="warning hidden" role="alert"></p>' +
			'<ul id="update-progress-message-warnings" class="warning hidden"></ul>' +
			'<p id="update-progress-message" role="status"></p>' +
			'<a class="update-show-detailed" href="#" role="button" aria-expanded="false"' +
			' aria-controls="update-progress-detailed">Detailed logs</a>' +
			'<div id="update-progress-detailed" class="hidden warning"></div>' +
			'</div>' +
			'</div>'
		);
		// Statt des Servers: die Ereignisse der Aktualisierung von Hand auslösen.
		source = {
			listeners: {},
			listen: function(type, callback) {
				this.listeners[type] = callback;
			},
			close: sinon.stub()
		};
		sinon.stub(OC, 'EventSource').returns(source);
		sinon.stub(OC, 'redirect');
		// Nach Erfolg leitet update.js nach drei Sekunden weiter.
		clock = sinon.useFakeTimers();
	});

	afterEach(function() {
		clock.restore();
		OC.redirect.restore();
		OC.EventSource.restore();
		OC.Update._started = false;
		// start() bindet die Rückfrage beim Verlassen der Seite; nur Abschluss
		// und Abbruch nehmen sie wieder weg. Sonst bliebe sie für alle
		// folgenden Specs hängen.
		$(window).off('beforeunload.inprogress');
	});

	function message() {
		return $('#update-progress-message');
	}

	it('moves focus to the progress heading when the start button hides itself', function() {
		$('.updateButton').focus().click();

		expect($('.updateOverview').hasClass('hidden')).toEqual(true);
		expect($('#update-progress-title').text()).toEqual('Updating to 11.0.31');
		expect(document.activeElement.id).toEqual('update-progress-title');
	});

	it('shows each step in the status message', function() {
		$('.updateButton').click();
		source.listeners.success('Updated database');

		expect(message().text()).toEqual('Updated database');
	});

	it('announces success in the status message instead of hiding it', function() {
		$('.updateButton').click();
		source.listeners.success('Updated database');
		source.listeners.done();

		expect(message().css('display')).not.toEqual('none');
		expect(message().text()).toEqual('The update was successful. Redirecting you to owncloud.online now.');

		clock.tick(3000);
		expect(OC.redirect.calledOnce).toEqual(true);
	});

	it('announces success with warnings in the status message', function() {
		$('.updateButton').click();
		source.listeners.notice('Disabled incompatible app: foo');
		source.listeners.done();

		expect(message().css('display')).not.toEqual('none');
		expect(message().text()).toContain('The update was successful. There were warnings.');
		expect(message().text()).toContain('Please reload the page.');
		expect(OC.redirect.called).toEqual(false);
	});

	/**
	 * Bisher verwies der Abbruch auf ein Forum bzw. die Fehlerliste eines
	 * fremden Projekts. Jetzt ein neutraler Hinweis ohne Link; den Grund nennt
	 * weiter die Fehlermeldung.
	 */
	var failureHint = 'The update was unsuccessful. Please check the server log or contact your administrator.';

	/**
	 * Ob die Rückfrage beim Verlassen der Seite gebunden ist. Ein beforeunload
	 * auslösen geht hier nicht: jQuery riefe dabei auch window.onbeforeunload von
	 * Karma auf, und das meldet einen Neuladevorgang der Testseite.
	 */
	function guardsLeaving() {
		var events = $._data(window, 'events') || {};
		return (events.beforeunload || []).some(function(handler) {
			return handler.namespace === 'inprogress';
		});
	}

	it('reports a refused major update without links to outside projects', function() {
		var reason = 'Exception: Updates between multiple major versions and downgrades are unsupported.';
		$('.updateButton').click();
		source.listeners.failure(reason);

		expect($('.update-progress span.bold').text()).toEqual(failureHint);
		expect($('.update-progress a[href^="http"]').length).toEqual(0);
		expect($('#update-progress-message-error').text()).toContain(reason);
	});

	it('shows the same neutral hint for any other failure and stops guarding the page', function() {
		$('.updateButton').click();
		expect(guardsLeaving()).toEqual(true);
		source.listeners.failure('Exception: Database locked');

		expect($('.update-progress span.bold').text()).toEqual(failureHint);
		expect($('.update-progress a[href^="http"]').length).toEqual(0);
		expect(guardsLeaving()).toEqual(false);
	});

	/**
	 * Der Hinweis hing bisher als eigener Span unten an der Fortschrittsanzeige,
	 * außerhalb jeder Live-Region: Screenreader sagten nur den Grund an (SC 4.1.3).
	 */
	it('announces the failure hint together with the reason in the alert region', function() {
		var reason = 'Exception: Database locked';
		$('.updateButton').click();
		source.listeners.failure(reason);

		var $alert = $('#update-progress-message-error[role="alert"]');
		expect($alert.find('span.bold').text()).toEqual(failureHint);
		expect($alert.text()).toEqual(reason + failureHint);
		expect($('.update-progress span.bold').length).toEqual(1);
	});
});
