/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * Rückmeldung von „Passwort vergessen“ auf der Anmeldeseite (core/js/lostpassword.js):
 * wo die Meldung erscheint, wie Hilfsmittel sie erfahren und wo der Tastaturfokus
 * danach steht.
 */

describe('OC.Lostpassword', function() {
	var $form;

	/**
	 * Ausschnitt aus core/templates/login.php in den Varianten, die hier zählen:
	 * falsches Passwort (Link '#lost-password' im Knopfbereich), Sperrzustand (Link
	 * '#login-throttle-reset' im Hinweis, Knopf von js/login.js deaktiviert) und
	 * „Angemeldet bleiben“ an oder abgeschaltet.
	 */
	function renderLogin(options) {
		var html = '<form method="post" name="login">';
		if (options.throttle) {
			html += '<div id="login-throttle" class="warning login-throttle">' +
				'<span class="login-throttle-text login-throttle-hint">' +
				'<a id="login-throttle-reset" href="">Forgotten your password?</a>' +
				'</span></div>';
		}
		html += '<div class="grouptop"><input type="text" name="user" id="user" value="' +
			(options.user || '') + '" required></div>' +
			'<div class="groupbottom"><input type="password" name="password" id="password" value=""></div>' +
			'<div class="submit-wrap">';
		if (options.wrongPassword) {
			html += '<a id="lost-password" class="warning" href="">Wrong password. Reset it?</a>';
		}
		html += '<button type="submit" id="submit" class="login-button"' +
			(options.throttle ? ' disabled' : '') + '>Login</button></div>';
		if (options.rememberLogin) {
			html += '<div class="remember-login-container">' +
				'<input type="checkbox" name="remember_login" value="1" id="remember_login">' +
				'<label for="remember_login">Stay logged in</label></div>';
		}
		html += '</form>';
		$form = $(html);
		// Nie wirklich absenden - das würde die Testseite verlassen.
		$form.on('submit', function(event) {
			event.preventDefault();
		});
		$('#testArea').append($form);
		OC.Lostpassword.init();
	}

	function respond(body) {
		var request = fakeServer.requests[fakeServer.requests.length - 1];
		request.respond(200, {'Content-Type': 'application/json'}, JSON.stringify(body));
	}

	function message() {
		return $('#lost-password');
	}

	describe('after a wrong password', function() {
		beforeEach(function() {
			renderLogin({wrongPassword: true, user: 'alice'});
			$('#lost-password').focus();
		});

		it('moves focus from the replaced link to the success message', function() {
			expect(document.activeElement.id).toEqual('lost-password');
			$('#lost-password').click();
			expect(fakeServer.requests.length).toEqual(1);
			respond({status: 'success'});

			expect(message().length).toEqual(1);
			expect(message().prop('tagName')).toEqual('P');
			expect(message().attr('role')).toEqual('status');
			expect(message().attr('tabindex')).toEqual('-1');
			expect(message().text()).toContain('The link to reset your password has been sent');
			expect(document.activeElement).toBe(message()[0]);
		});

		it('announces a failure as alert and focuses it', function() {
			$('#lost-password').click();
			respond({status: 'error', msg: 'Mail server unreachable'});

			expect(message().attr('role')).toEqual('alert');
			expect(message().hasClass('warning')).toEqual(true);
			expect(message().text()).toEqual('Mail server unreachable');
			expect(document.activeElement).toBe(message()[0]);
		});
	});

	describe('while login is throttled', function() {
		it('shows the message without the remember-login checkbox', function() {
			renderLogin({throttle: true, user: 'alice'});
			$('#login-throttle-reset').focus().click();
			respond({status: 'success'});

			expect(message().length).toEqual(1);
			expect(message().is(':visible')).toEqual(true);
			expect($.contains($form.find('.submit-wrap')[0], message()[0])).toEqual(true);
			expect(message().attr('role')).toEqual('status');
			// Der Link bleibt stehen, die Meldung ist das Ergebnis seiner Aktion.
			expect($('#login-throttle-reset').length).toEqual(1);
			expect(document.activeElement).toBe(message()[0]);
		});

		it('puts the message in the same place with the remember-login checkbox', function() {
			renderLogin({throttle: true, user: 'alice', rememberLogin: true});
			$('#login-throttle-reset').focus().click();
			respond({status: 'success'});

			expect(message().length).toEqual(1);
			expect($.contains($form.find('.submit-wrap')[0], message()[0])).toEqual(true);
			expect(message().closest('.remember-login-container').length).toEqual(0);
		});

		it('replaces an earlier message instead of stacking them', function() {
			renderLogin({throttle: true, user: 'alice'});
			$('#login-throttle-reset').click();
			respond({status: 'error', msg: 'First failure'});
			$('#login-throttle-reset').click();
			respond({status: 'success'});

			expect(message().length).toEqual(1);
			expect(message().attr('role')).toEqual('status');
			expect(message().hasClass('warning')).toEqual(false);
		});

		it('sends one request per click, also after a failure', function() {
			renderLogin({throttle: true, user: 'alice'});
			$('#login-throttle-reset').click();
			respond({status: 'error', msg: 'First failure'});
			$('#login-throttle-reset').click();

			expect(fakeServer.requests.length).toEqual(2);
		});

		it('does not pull focus out of a field the user moved to', function() {
			renderLogin({throttle: true, user: 'alice'});
			$('#login-throttle-reset').focus().click();
			$('#password').focus();
			respond({status: 'success'});

			expect(document.activeElement.id).toEqual('password');
			expect(message().attr('role')).toEqual('status');
		});

		it('points at the empty user field instead of doing nothing', function() {
			renderLogin({throttle: true});
			$('#login-throttle-reset').focus().click();

			expect(fakeServer.requests.length).toEqual(0);
			expect(document.activeElement.id).toEqual('user');
		});
	});

	it('announces a failed reset on the new-password page as alert', function() {
		// Ausschnitt aus core/templates/lostpassword/resetpassword.php
		$('#testArea').append(
			'<form action="/lostpassword/set/token/alice" id="reset-password" method="post">' +
			'<input type="password" name="password" id="password" value="secret">' +
			'<input type="password" name="retypepassword" id="retypepassword" value="secret">' +
			'<div class="submit-wrap"><span id="message"></span>' +
			'<button type="submit" id="submit">Reset password</button></div></form>'
		);
		OC.Lostpassword.init();
		$('#reset-password #submit').click();
		respond({status: 'error', msg: 'Could not reset password because the token expired'});

		expect(message().length).toEqual(1);
		expect($.contains($('#reset-password .submit-wrap')[0], message()[0])).toEqual(true);
		expect(message().attr('role')).toEqual('alert');
		expect(message().text()).toEqual('Could not reset password because the token expired');
	});

	it('still lets the browser validate an empty user field when not throttled', function() {
		renderLogin({wrongPassword: true});
		var submitClicked = false;
		$('#submit').on('click', function(event) {
			submitClicked = true;
			event.preventDefault();
		});
		$('#lost-password').click();

		expect(submitClicked).toEqual(true);
		expect(fakeServer.requests.length).toEqual(0);
	});
});
