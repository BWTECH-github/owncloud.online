/*
 * Copyright (c) 2014
 *
 * This file is licensed under the Affero General Public License version 3
 * or later.
 *
 * See the COPYING-README file.
 *
 */

(function() {
	OC.Update = {
		_started : false,

		/**
		 * Start the upgrade process.
		 *
		 * @param $el progress list element
		 */
		start: function($el, options) {
			if (this._started) {
				return;
			}

			var hasWarnings = false;

			this.$el = $el;

			this._started = true;

			var self = this;

			$(window).on('beforeunload.inprogress', function () {
				return t('core', 'The upgrade is in progress, leaving this page might interrupt the process in some environments.');
			});

			$('#update-progress-title').html(t(
				'core',
				'Updating to {version}', {
					version: options.version
				})
			);

			var updateEventSource = new OC.EventSource(OC.webroot+'/core/ajax/update.php');
			updateEventSource.listen('success', function(message) {
				self.setMessage(message);
			});
			updateEventSource.listen('notice', function(message) {
				self.setPermanentMessage(message);
				hasWarnings = true;
			});
			updateEventSource.listen('error', function(message) {
				$('#update-progress-message').hide();
				$('#update-progress-icon')
					.addClass('icon-error-white')
					.removeClass('icon-loading-dark');
				message = message || t('core', 'An error occurred.');
				$(window).off('beforeunload.inprogress');
				self.setErrorMessage(message);
				message = t('core', 'Please reload the page.');
				$('<span>').addClass('error').append('<a href=".">'+message+'</a><br />').appendTo($el);
				updateEventSource.close();
			});
			updateEventSource.listen('failure', function(message) {
				$(window).off('beforeunload.inprogress');
				$('#update-progress-message').hide();
				$('#update-progress-icon')
					.addClass('icon-error-white')
					.removeClass('icon-loading-dark');

				self.setErrorMessage(message);
				// Ohne Verweis auf fremde Foren oder Fehlerlisten: Den Grund nennt die
				// Fehlermeldung davor, Einzelheiten stehen im Serverprotokoll. Als
				// Text eingefügt - die Übersetzung enthält kein HTML mehr. Der Hinweis
				// steht in derselben Alert-Region wie der Grund; unten an die
				// Fortschrittsanzeige gehängt sagte ihn kein Screenreader an (SC 4.1.3).
				$('#update-progress-message-error')
					.append($('<br>'))
					.append($('<span>')
						.addClass('bold')
						.text(t('core', 'The update was unsuccessful. Please check the server log or contact your administrator.')));
			});
			updateEventSource.listen('done', function() {
				$(window).off('beforeunload.inprogress');

				$('#update-progress-icon')
					.addClass('icon-checkmark-white')
					.removeClass('icon-loading-dark');

				// Das Ergebnis ersetzt den letzten Schritt in #update-progress-message.
				// Bisher wurde die Meldung ausgeblendet und das Ergebnis als neuer Span
				// angehängt - der war keine Live-Region, Screenreader erfuhren vom
				// Ende der Aktualisierung nichts (SC 4.1.3).
				if (hasWarnings) {
					self.setResult(
						t('core', 'The update was successful. There were warnings.') +
						'<br>' + t('core', 'Please reload the page.')
					);
				} else {
					// FIXME: use product name
					self.setResult(t('core', 'The update was successful. Redirecting you to owncloud.online now.'));
					setTimeout(function () {
						OC.redirect(OC.webroot + '/');
					}, 3000);
				}
			});
		},

		/**
		 * Ergebnis der Aktualisierung anzeigen. #update-progress-message ist eine
		 * Live-Region (role="status" in der Vorlage), das Ergebnis wird also
		 * vorgelesen wie zuvor jeder Schritt.
		 *
		 * @param {string} html Ergebnis, darf <br> enthalten
		 */
		setResult: function(html) {
			$('#update-progress-message').html(html);
		},

		setMessage: function(message) {
			var parsedMessage = this.parseMessage(message);
			$('#update-progress-message').html(parsedMessage);
			$('#update-progress-detailed')
				.append($('<span>'))
				.append(parsedMessage)
				.append($('<br>'));
		},

		setPermanentMessage: function(message) {
			var parsedMessage = this.parseMessage(message);
			$('#update-progress-message').html(parsedMessage);
			$('#update-progress-message-warnings')
				.show()
				.append($('<ul>').append(parsedMessage));
			$('#update-progress-detailed')
				.append($('<span>'))
				.append(parsedMessage)
				.append($('<br>'));
		},

		setErrorMessage: function (message) {
			var parsedMessage = this.parseMessage(message);
			$('#update-progress-message-error')
				.show()
				.html(parsedMessage);
			$('#update-progress-detailed')
				.append($('<span>'))
				.append(parsedMessage)
				.append($('<br>'));
		},

		parseMessage: function (message) {
			// generate a tags from urls
			var urlRegex = /(https?:\/\/[^\s]+)/g;
			return message.replace(urlRegex, '<a href="$1" target="_blank">$1</a>');
		},

	};

})();

$(document).ready(function() {
	function isSpace(event) {
		return event.key === ' ' || event.key === 'Spacebar';
	}

	// Delegiert wie „Detaillierte Protokolle“ unten: Die Specs fügen die Vorlage
	// erst nach dem Laden ein.
	$(document).on('click', '.updateButton', function() {
		var $updateEl = $('.update');
		var $progressEl = $('.update-progress');
		$progressEl.removeClass('hidden');
		$('.updateOverview').addClass('hidden');
		$('#update-progress-message-error').hide();
		$('#update-progress-message-warnings').hide();
		OC.Update.start($progressEl, {
			productName: $updateEl.attr('data-productname'),
			version: $updateEl.attr('data-version')
		});
		// Der Knopf hat sich eben selbst ausgeblendet, der Fokus fiele auf body
		// (SC 2.4.3). Die Überschrift der Fortschrittsanzeige übernimmt ihn
		// (tabindex="-1" in der Vorlage), Screenreader lesen sie sofort vor.
		$('#update-progress-title').trigger('focus');
		return false;
	});
	// „Detaillierte Protokolle“ ist ein Anker mit role="button": href="#" macht
	// ihn per Tab erreichbar und lässt die Eingabetaste nativ auslösen, die
	// Leertaste wird nachgereicht. aria-expanded meldet, ob die Protokolle offen
	// sind. Delegiert, damit es nicht darauf ankommt, ob der Anker beim Laden
	// schon im DOM steht (die Specs fügen ihn erst später ein).
	$(document).on('click', '.update-show-detailed', function() {
		var $details = $('#update-progress-detailed').toggleClass('hidden');
		$(this).attr('aria-expanded', $details.hasClass('hidden') ? 'false' : 'true');
		return false;
	});
	// Leertaste wie beim nativen Knopf: keydown nur abfangen (sonst scrollt die
	// Seite), ausgelöst wird beim Loslassen - einmal, auch wenn die Taste
	// gedrückt bleibt und keydown sich wiederholt. Es zählt nur, wenn die Taste
	// auf dem Anker gedrückt wurde und der Fokus bis zum Loslassen dort blieb.
	$(document).on('keydown', '.update-show-detailed', function(event) {
		if (isSpace(event)) {
			event.preventDefault();
			$(this).data('spacePressed', true);
		}
	});
	$(document).on('keyup', '.update-show-detailed', function(event) {
		if (isSpace(event) && $(this).data('spacePressed')) {
			event.preventDefault();
			$(this).removeData('spacePressed').trigger('click');
		}
	});
	$(document).on('focusout', '.update-show-detailed', function() {
		$(this).removeData('spacePressed');
	});
});
