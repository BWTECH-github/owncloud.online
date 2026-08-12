/**
 * Copyright (c) 2015
 *  Vincent Petry <pvince81@owncloud.com>
 *  Jan-Christoph Borchardt, http://jancborchardt.net
 * This file is licensed under the Affero General Public License version 3 or later.
 * See the COPYING-README file.
 */

/**
 * @namespace
 * @memberOf OC
 */
OC.Login = _.extend(OC.Login || {}, {
	onLogin: function () {
		$('#submit')
			.removeClass('icon-confirm')
			.addClass('icon-loading-small')
			.css('opacity', '1');
		return true;
	},

	rememberLogin: function(){
		if($(this).is(":checked")){
	    	if($("#user").val() && $("#password").val()) {
	     	 	$('#submit').trigger('click');
	    	}
        }
	},

	/**
	 * Human-readable remaining time, matching the wording the template renders
	 * server-side so the text does not change style when the countdown takes over.
	 *
	 * @param {number} seconds
	 * @returns {string}
	 */
	formatThrottleTime: function (seconds) {
		if (seconds >= 60) {
			var minutes = Math.floor(seconds / 60);
			var rest = seconds % 60;
			var text = n('core', '%n minute', '%n minutes', minutes);
			if (rest !== 0) {
				text += ' ' + n('core', '%n second', '%n seconds', rest);
			}
			return text;
		}
		return n('core', '%n second', '%n seconds', seconds);
	},

	/**
	 * Live countdown for the brute-force cooldown notice.
	 *
	 * The remaining seconds are derived from a fixed target timestamp rather than
	 * decremented per tick: a background tab gets its timers throttled, and
	 * counting ticks would drift until the notice disagrees with the server.
	 *
	 * The submit button is disabled here rather than in the template, because
	 * only this code can re-enable it - without JavaScript a disabled button
	 * would stay dead until a reload.
	 */
	initThrottleCountdown: function () {
		var $notice = $('#login-throttle');
		if (!$notice.length) {
			return;
		}

		var total = parseInt($notice.attr('data-retry-after'), 10);
		if (isNaN(total) || total <= 0) {
			return;
		}

		var $remaining = $('#login-throttle-remaining');
		var $bar = $('#login-throttle-bar');
		var $status = $('#login-throttle-status');
		var $submit = $('#submit');
		var target = Date.now() + total * 1000;
		var timer;

		$submit.prop('disabled', true);
		$bar.css('width', '100%');

		var finish = function () {
			clearInterval(timer);
			$submit.prop('disabled', false);
			$notice.addClass('login-throttle--ready');
			$bar.css('width', '0%');
			$('#login-throttle-countdown').text(t('core', 'You can try again now.'));
			// The only moment worth announcing - the notice itself was already
			// read out as part of the page, and a per-second live region would
			// talk over everything else.
			$status.text(t('core', 'You can try again now.'));
		};

		var tick = function () {
			var left = Math.ceil((target - Date.now()) / 1000);
			if (left <= 0) {
				finish();
				return;
			}
			$remaining.text(OC.Login.formatThrottleTime(left));
			$bar.css('width', (left / total * 100) + '%');
		};

		tick();
		timer = setInterval(tick, 1000);
	}
});

$(document).ready(function() {
	$('form[name=login]').submit(OC.Login.onLogin);

	$('#remember_login').click(OC.Login.rememberLogin);

	OC.Login.initThrottleCountdown();
});
