/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license AGPL-3.0
 *
 * Rückmeldung von „Passwort vergessen“ auf der Anmeldeseite und auf der Seite
 * „Neues Passwort“ (core/js/lostpassword.js): wo die Meldung erscheint, wie
 * Hilfsmittel sie erfahren, wo der Tastaturfokus danach steht und wie es nach
 * einem Fehler weitergeht.
 */

describe('OC.Lostpassword', function() {
	var $form;

	/**
	 * Ausschnitt aus core/templates/login.php in den Varianten, die hier zählen:
	 * falsches Passwort (Link '#lost-password' im Knopfbereich), Sperrzustand (Link
	 * '#login-throttle-reset' im Hinweis, Knopf von js/login.js deaktiviert), beides
	 * zugleich und „Angemeldet bleiben“ an oder abgeschaltet.
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

	function retry() {
		return $('#lost-password-retry');
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

	describe('after a failed request', function() {
		// Die Meldung ersetzt den Link „Zurücksetzen?“. Neu laden hilft nicht - der
		// Server zeigt den Link nur direkt nach dem Fehlversuch -, also muss die
		// Seite selbst einen neuen Versuch anbieten, per Tastatur wie per Maus.
		beforeEach(function() {
			renderLogin({wrongPassword: true, user: 'alice'});
			$('#lost-password').focus().click();
			respond({status: 'error', msg: 'Mail server unreachable'});
		});

		it('offers a real button right after the message', function() {
			expect(retry().length).toEqual(1);
			expect(retry().prop('tagName')).toEqual('BUTTON');
			// type="button": sonst wäre er der Standardknopf des Formulars, und die
			// Eingabetaste im Passwortfeld schickte die Anforderung statt anzumelden.
			expect(retry().attr('type')).toEqual('button');
			expect(retry().text()).toEqual('Request again');
			expect(message().next()[0]).toBe(retry()[0]);
		});

		it('keeps the message a plain alert', function() {
			expect(message().attr('role')).toEqual('alert');
			expect(message().attr('tabindex')).toEqual('-1');
			expect($.contains(message()[0], retry()[0])).toEqual(false);
			// Ein Klick auf den Meldungstext ist kein verstecktes Bedienelement mehr.
			message().click();
			expect(fakeServer.requests.length).toEqual(1);
		});

		it('sends a new request from the button', function() {
			retry().click();

			expect(fakeServer.requests.length).toEqual(2);
			expect(fakeServer.requests[1].url).toEqual(OC.generateUrl('/lostpassword/email'));
		});

		it('removes the button and focuses the new message once the retry succeeds', function() {
			retry().focus().click();
			respond({status: 'success'});

			expect(retry().length).toEqual(0);
			expect(message().length).toEqual(1);
			expect(message().attr('role')).toEqual('status');
			expect(document.activeElement).toBe(message()[0]);
		});

		it('keeps one message and one button after another failure', function() {
			retry().focus().click();
			respond({status: 'error', msg: 'Still unreachable'});

			expect(message().length).toEqual(1);
			expect(retry().length).toEqual(1);
			expect(message().text()).toEqual('Still unreachable');
			expect(message().next()[0]).toBe(retry()[0]);
			expect(document.activeElement).toBe(message()[0]);
		});
	});

	describe('right after the attempt that starts the throttle', function() {
		// Der Fehlversuch, der die Sperre auslöst, wird noch geprüft und gezählt.
		// Die Seite danach zeigt beide Links: den Hinweis mit '#login-throttle-reset'
		// und im Knopfbereich '#lost-password' („Falsches Passwort. Zurücksetzen?“).
		beforeEach(function() {
			renderLogin({throttle: true, wrongPassword: true, user: 'alice'});
		});

		it('replaces the wrong-password link when the request comes from the notice', function() {
			$('#login-throttle-reset').focus().click();
			respond({status: 'success'});

			expect(message().length).toEqual(1);
			expect(message().prop('tagName')).toEqual('P');
			expect($('a#lost-password').length).toEqual(0);
			expect($.contains($form.find('.submit-wrap')[0], message()[0])).toEqual(true);
			expect($('#login-throttle-reset').length).toEqual(1);
			expect(document.activeElement).toBe(message()[0]);
		});

		it('handles the wrong-password link the same way', function() {
			$('#lost-password').focus().click();
			respond({status: 'error', msg: 'Mail server unreachable'});

			expect(fakeServer.requests.length).toEqual(1);
			expect(message().length).toEqual(1);
			expect(message().attr('role')).toEqual('alert');
			expect(retry().length).toEqual(1);
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
			// Nach dem Erfolg gibt es nichts mehr zu wiederholen.
			expect(retry().length).toEqual(0);
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

	describe('on the new-password page', function() {
		/**
		 * Ausschnitt aus core/templates/lostpassword/resetpassword.php: #message ist
		 * von Anfang an eine Live-Region und beschreibt beide Felder.
		 */
		function renderReset(password, retype) {
			$('#testArea').append(
				'<form action="/lostpassword/set/token/alice" id="reset-password" method="post">' +
				'<div class="grouptop"><label for="password">New password</label>' +
				'<input type="password" name="password" id="password" value="' + password + '"' +
				' aria-describedby="message"></div>' +
				'<div class="groupbottom"><label for="retypepassword">Confirm Password</label>' +
				'<input type="password" name="retypepassword" id="retypepassword" value="' + retype + '"' +
				' aria-describedby="message"></div>' +
				'<div class="submit-wrap"><span id="message" role="alert"></span>' +
				'<button type="submit" id="submit">Reset password</button></div></form>'
			);
			OC.Lostpassword.init();
		}

		it('announces a failed reset as alert', function() {
			renderReset('secret', 'secret');
			$('#reset-password #submit').click();
			respond({status: 'error', msg: 'Could not reset password because the token expired'});

			expect(message().length).toEqual(1);
			expect($.contains($('#reset-password .submit-wrap')[0], message()[0])).toEqual(true);
			expect(message().attr('role')).toEqual('alert');
			expect(message().text()).toEqual('Could not reset password because the token expired');
		});

		it('marks both fields invalid on a mismatch and starts over in the first one', function() {
			renderReset('secret', 'secreet');
			$('#reset-password #submit').focus().click();

			expect(fakeServer.requests.length).toEqual(0);
			expect($('#message').text()).toEqual('Passwords do not match');
			// Beide Felder sind geleert, die Eingabe beginnt von vorn. Der Fokus
			// springt deshalb ins erste Feld - und das sagt jetzt auch, warum.
			expect($('#password').val()).toEqual('');
			expect($('#retypepassword').val()).toEqual('');
			expect($('#password').attr('aria-invalid')).toEqual('true');
			expect($('#retypepassword').attr('aria-invalid')).toEqual('true');
			expect(document.activeElement.id).toEqual('password');
		});

		it('drops the error state once the user types a new password', function() {
			renderReset('secret', 'secreet');
			$('#reset-password #submit').click();
			$('#password').val('n').trigger('keypress');

			expect($('#message').text()).toEqual('');
			expect($('#password').attr('aria-invalid')).toBeUndefined();
			expect($('#retypepassword').attr('aria-invalid')).toBeUndefined();
		});

		it('sends matching passwords without marking anything invalid', function() {
			renderReset('secret', 'secret');
			$('#reset-password #submit').click();

			expect(fakeServer.requests.length).toEqual(1);
			expect($('#password').attr('aria-invalid')).toBeUndefined();
			expect($('#retypepassword').attr('aria-invalid')).toBeUndefined();
		});

		it('clears a stale mismatch when the passwords match on the next try', function() {
			renderReset('secret', 'secreet');
			$('#reset-password #submit').click();
			// Ohne Tastendruck neu gefüllt, etwa durch Einfügen oder einen Passwortmanager.
			$('#password').val('secret');
			$('#retypepassword').val('secret');
			$('#reset-password #submit').click();

			expect(fakeServer.requests.length).toEqual(1);
			expect($('#message').text()).toEqual('');
			expect($('#password').attr('aria-invalid')).toBeUndefined();
			expect($('#retypepassword').attr('aria-invalid')).toBeUndefined();
		});
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
