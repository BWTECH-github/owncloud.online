/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * „Detaillierte Protokolle“ auf der Update-Seite (core/js/update.js): ein Anker
 * mit role="button" muss wie ein Knopf bedienbar sein und seinen Zustand melden.
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

	function key(name) {
		var event = $.Event('keydown', {key: name});
		$link.trigger(event);
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

	it('toggles on the space key like a button, without scrolling', function() {
		var event = key(' ');
		expect(event.isDefaultPrevented()).toEqual(true);
		expect($details.hasClass('hidden')).toEqual(false);
		expect($link.attr('aria-expanded')).toEqual('true');
	});

	it('ignores other keys', function() {
		var event = key('a');
		expect(event.isDefaultPrevented()).toEqual(false);
		expect($details.hasClass('hidden')).toEqual(true);
		expect($link.attr('aria-expanded')).toEqual('false');
	});
});
