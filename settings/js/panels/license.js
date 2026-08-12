$(document).ready(function() {
	// Am Absenden der Form, nicht am Klick: so wirkt auch die Eingabetaste, und
	// bei leerem Feld meldet der Browser den Fehler selbst (required im
	// Template) statt ihn stillschweigend zu schlucken - SC 3.3.1.
	$('#license_form').on('submit', function (event) {
		event.preventDefault();
		var license = $('#license_input_text').val();

		$.post(OC.generateUrl('/license/license'), {
			licenseString: license
		}).done(function () {
			location.reload();
		}).fail(function () {
			// Ohne diesen Zweig blieb eine Ablehnung des Servers folgenlos: die
			// Seite lud nicht neu, es erschien keine Meldung, und der Eindruck
			// war, es sei nichts passiert.
			OC.Notification.showTemporary(t('core', 'Error'));
			$('#license_input_text').focus();
		});
	});

	$('#license_remove_button').click(function () {
		// Rueckfrage vor einer nicht umkehrbaren Aktion - SC 3.3.4. Text und
		// Titel sind bereits uebersetzte Strings, es kommt keiner hinzu.
		OC.dialogs.confirm(
			t('settings', 'Remove current support key'),
			t('settings', 'Enterprise Support Key'),
			function (confirmation) {
				if (!confirmation) {
					return;
				}
				$.ajax({
					url: OC.generateUrl('/license/license'),
					type: 'DELETE',
				}).done(function () {
					location.reload();
				}).fail(function () {
					OC.Notification.showTemporary(t('core', 'Error'));
				});
			},
			true
		);
	});
});
