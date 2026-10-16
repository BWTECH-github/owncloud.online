OC.Lostpassword = {
	sendErrorMsg : t('core', 'Couldn\'t send reset email. Please contact your administrator.'),

	sendSuccessMsg : t('core', 'The link to reset your password has been sent to your email. If you do not receive it within a reasonable amount of time, check your spam/junk folders.<br>If it is not there ask your local administrator.'),

	encryptedMsg : t('core', "Your files are encrypted. If you haven't enabled the recovery key, there will be no way to get your data back after your password is reset.<br />If you are not sure what to do, please contact your administrator before you continue. <br />Do you really want to continue?")
			+ ('<br /><input type="checkbox" id="encrypted-continue" value="Yes" />')
			+ '<label for="encrypted-continue">'
			+ t('core', 'I know what I\'m doing')
			+ '</label><br />',

	resetErrorMsg : t('core', 'Password can not be changed. Please contact your administrator.'),

	init : function() {
		// '#login-throttle-reset' ist der Zurücksetzen-Link im Sperrhinweis. Er
		// hängt am selben Handler, weil der beide Fälle schon abdeckt: ist
		// lost_password_link gesetzt, wird dorthin navigiert, sonst geht die
		// Anforderung per POST an /lostpassword/email.
		//
		// off() vor on(): init() darf mehrfach laufen, ohne Handler zu stapeln -
		// sonst schickte ein Klick die Anforderung einmal pro Aufruf.
		$('#lost-password, #login-throttle-reset')
			.off('click', OC.Lostpassword.resetLink)
			.on('click', OC.Lostpassword.resetLink);
		$('#reset-password #submit')
			.off('click', OC.Lostpassword.resetPassword)
			.on('click', OC.Lostpassword.resetPassword);
		$('#password')
			.off('keypress', OC.Lostpassword.onPasswordKeypress)
			.on('keypress', OC.Lostpassword.onPasswordKeypress);
	},

	resetLink : function(event){
		event.preventDefault();
		if (!$('#user').val().length){
			if ($('#submit').prop('disabled')) {
				// Sperrzustand: js/login.js hat den Anmeldeknopf deaktiviert, ein
				// Klick darauf verpufft ohne jede Rückmeldung. Dann das leere Feld
				// selbst melden - so, wie es der Browser beim Absenden täte.
				OC.Lostpassword.reportEmptyUser();
			} else {
				$('#submit').trigger('click');
			}
		} else {
			if (OC.config['lost_password_link']) {
				window.location = OC.config['lost_password_link'];
			} else {
				$.post(
					OC.generateUrl('/lostpassword/email'),
					{
						user : $('#user').val()
					},
					OC.Lostpassword.sendLinkDone
				);
			}
		}
	},

	sendLinkDone : function(result){
		var sendErrorMsg;

		if (result && result.status === 'success'){
			OC.Lostpassword.sendLinkSuccess();
		} else {
			if (result && result.msg){
				sendErrorMsg = result.msg;
			} else {
				sendErrorMsg = OC.Lostpassword.sendErrorMsg;
			}
			OC.Lostpassword.sendLinkError(sendErrorMsg);
		}
	},

	reportEmptyUser : function(){
		var user = $('#user').get(0);
		user.focus();
		if (typeof user.reportValidity === 'function') {
			user.reportValidity();
		}
	},

	sendLinkSuccess : function(msg){
		var node = OC.Lostpassword.getSendStatusNode();
		// update is the better success message styling
		node.addClass('update').css({width:'auto'});
		OC.Lostpassword.showSendStatus(node, 'status', OC.Lostpassword.sendSuccessMsg);
	},

	sendLinkError : function(msg){
		var node = OC.Lostpassword.getSendStatusNode();
		node.addClass('warning');
		OC.Lostpassword.showSendStatus(node, 'alert', msg);
		// Neu laden hilft nicht weiter: '#lost-password' rendert der Server nur
		// direkt nach dem Fehlversuch (showLoginForm entfernt loginMessages).
		// Ohne Sperrhinweis gibt es also keinen zweiten Weg - der neue Versuch
		// muss hier angeboten werden.
		node.after(OC.Lostpassword.getRetryButton());
	},

	/**
	 * Knopf „Erneut anfordern“ nach einer fehlgeschlagenen Anforderung.
	 *
	 * Bisher war die Fehlermeldung selbst der einzige Auslöser für einen neuen
	 * Versuch - nur per Klick, Eingabe- und Leertaste taten nichts (SC 2.1.1).
	 * Ein echter Knopf ist per Tab erreichbar, löst auf beide Tasten aus und wird
	 * als Schaltfläche gemeldet; die Meldung bleibt reine Meldung (role="alert").
	 * type="button", weil er im Anmeldeformular vor „Anmelden“ steht und sonst
	 * dessen Standardknopf wäre: Die Eingabetaste im Passwortfeld schickte dann
	 * die Anforderung, statt anzumelden.
	 *
	 * @return {jQuery}
	 */
	getRetryButton : function(){
		return $('<button type="button" id="lost-password-retry"></button>')
			.text(t('core', 'Request again'))
			.on('click', OC.Lostpassword.resetLink);
	},

	/**
	 * Zeigt das Ergebnis der Anforderung und meldet es Hilfsmitteln.
	 *
	 * role="status" bzw. "alert" macht die Meldung zur Statusmeldung (SC 4.1.3).
	 * Der Fokus wandert auf sie, wenn er sonst verloren wäre - der angeklickte
	 * Link '#lost-password' ist gerade durch sie ersetzt worden (SC 2.4.3) - oder
	 * wenn er noch auf dem Link im Sperrhinweis steht: Die Meldung ist dann das
	 * Ergebnis genau dieser Aktion, und eine frisch eingefügte Live-Region lesen
	 * nicht alle Screenreader zuverlässig vor. Aus einem Feld, in das der Nutzer
	 * inzwischen gewechselt ist, wird er nie herausgezogen.
	 *
	 * @param {jQuery} node Knoten aus getSendStatusNode()
	 * @param {string} role 'status' oder 'alert'
	 * @param {string} html Meldung, darf <br> enthalten
	 */
	showSendStatus : function(node, role, html){
		var active = document.activeElement;
		node.attr('role', role).html(html);
		if (!active || active === document.body || $(active).is('#login-throttle-reset')) {
			node.focus();
		}
	},

	getSendStatusNode : function(){
		// tabindex="-1": per Skript fokussierbar, aber kein zusätzlicher Tab-Halt.
		var node = $('<p id="lost-password" tabindex="-1"></p>');
		// Der Knopf „Erneut anfordern“ gehört zur vorigen Meldung. Hatte er den
		// Fokus, fällt der jetzt auf body - showSendStatus holt ihn zur Meldung.
		$('#lost-password-retry').remove();
		if ($('#lost-password').length){
			// Der Link „Falsches Passwort. Zurücksetzen?“ oder eine frühere Meldung.
			// Auch im Sperrzustand: Der Fehlversuch, der die Sperre auslöst, wird
			// noch geprüft und als falsches Passwort gemeldet, die Seite danach
			// zeigt beide Links. Dann ersetzt auch die Meldung des Links im
			// Sperrhinweis diesen Link.
			$('#lost-password').replaceWith(node);
		} else {
			// Kein '#lost-password': Die Anforderung kam vom Link im Sperrhinweis,
			// und die Seite meldet keinen frischen Fehlversuch (etwa nach dem Neuladen
			// während der Sperre). Die Meldung steht dann dort, wo sonst
			// '#lost-password' steht - im Knopfbereich vor „Anmelden“. Bisher hing
			// sie an '#remember_login'; ist „Angemeldet bleiben“ abgeschaltet, fehlt
			// das Feld, und die Meldung erschien nie.
			$('form[name="login"] .submit-wrap').first().prepend(node);
		}
		return node;
	},

	resetPassword : function(event){
		$('#password').parent().removeClass('shake');

		event.preventDefault();
		if ($('#password').val() === $('#retypepassword').val()){
			// Eine Abweichung vom vorigen Versuch ist erledigt, auch wenn beide
			// Felder ohne Tastendruck (Einfügen, Passwortmanager) gefüllt wurden.
			OC.Lostpassword.clearMismatch();
			$('#reset-password #submit').addClass('icon-loading-small');
			$.post(
					$('#password').parents('form').attr('action'),
					{
						password : $('#password').val(),
						proceed: $('#encrypted-continue').is(':checked') ? 'true' : 'false'
					},
					OC.Lostpassword.resetDone
			);
		} else {
			// Die Passwörter weichen ab. Beide Felder werden geleert, die Eingabe
			// beginnt von vorn - deshalb springt der Fokus ins erste Feld. Den Grund
			// erfahren Hilfsmittel jetzt dort: Beide Felder sind als ungültig
			// markiert und per aria-describedby (Vorlage) mit der Meldung verbunden
			// (SC 3.3.1). Die Meldung selbst ist eine Live-Region (role="alert") und
			// wird auch vorgelesen, wenn der Fokus schon im ersten Feld stand und
			// sich darum nichts Neues ankündigt (SC 4.1.3).
			$('#password').val('');
			$('#retypepassword').val('');
			$('#password').parent().addClass('shake');
			$('#password, #retypepassword').attr('aria-invalid', 'true');
			$('#message').addClass('warning');
			$('#message').text(t('core', 'Passwords do not match'));
			$('#message').show();
			$('#password').focus();
		}
		if($('#encrypted-continue').is(':checked')) {
			$('#reset-password #submit').hide();
			$('#reset-password #float-spinner').removeClass('hidden');
		}
	},

	resetDone : function(result){
		var resetErrorMsg;
		if (result && result.status === 'success'){
			$.post(
					OC.webroot + '/',
					{
						user : window.location.href.split('/').pop(),
						password : $('#password').val()
					},
					OC.Lostpassword.redirect
			);
		} else {
			$('#reset-password #submit').removeClass('icon-loading-small');

			if (result && result.msg){
				resetErrorMsg = result.msg;
			} else if (result && result.encryption) {
				resetErrorMsg = OC.Lostpassword.encryptedMsg;
			} else {
				resetErrorMsg = OC.Lostpassword.resetErrorMsg;
			}
			OC.Lostpassword.resetError(resetErrorMsg);
		}
	},

	redirect : function(msg){
		if(OC.webroot !== '') {
			window.location = OC.webroot;
		} else {
			window.location = '/';
		}
	},

	resetError : function(msg){
		var node = OC.Lostpassword.getResetStatusNode();
		// Derselbe Knoten wie auf der Anmeldeseite, hier nur für Fehler. Der Fokus
		// bleibt auf „Passwort zurücksetzen“ - die Rolle sorgt dafür, dass die
		// Meldung trotzdem vorgelesen wird (SC 4.1.3).
		node.addClass('warning').attr('role', 'alert');
		node.html(msg);
	},

	getResetStatusNode : function (){
		if (!$('#lost-password').length){
			$('#reset-password .submit-wrap').prepend($('<p id="lost-password"></p>'));
		} else {
			$('#lost-password').replaceWith($('<p id="lost-password"></p>'));
		}
		return $('#lost-password');
	},

	/**
	 * Nimmt „Passwörter stimmen nicht überein“ samt aria-invalid zurück.
	 */
	clearMismatch : function(){
		$('#message').removeClass('warning').text('');
		$('#password, #retypepassword').removeAttr('aria-invalid');
	},

	onPasswordKeypress : function(){
		// The warning message should be shown only during password mismatch.
		// Else it should not. Auf der Anmeldeseite gibt es kein
		// '#retypepassword', dort bleibt alles, wie es ist.
		if ($('#retypepassword').length && $('#retypepassword').val().length === 0) {
			OC.Lostpassword.clearMismatch();
		}
	}

};

$(document).ready(function () {
	OC.Lostpassword.init();
});
