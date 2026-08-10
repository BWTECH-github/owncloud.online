<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-16.
 * Changes:
 *   - rename 'license key' -> 'support key' + remove owncloud sales leak + DE i18n
 */
script('settings', 'panels/license');
?>
<div class="section">
	<h2 class="app-name"><?php p($l->t('Enterprise Support Key'));?></h2>
	<div id="license_message_div" <?php print_unescaped($_['divMessageClass']); ?>>
	<?php foreach ($_['messageInfo']['translated_message'] as $lineNumber => $line): ?>
		<?php if (\in_array($lineNumber, $_['messageInfo']['contains_html'], true)): ?>
			<p><?php print_unescaped($line); ?></p>
		<?php else: ?>
			<p><?php p($line); ?></p>
		<?php endif; ?>
	<?php endforeach; ?>
	</div>

	<div>
		<?php /* OC-WCAG-007: Das Feld hatte keinen barrierefreien Namen. Der sichtbare Text
				 war ein blosser Textknoten und damit nicht programmatisch zugeordnet.
				 <label for> nutzt den bereits vorhandenen l10n-String weiter. */ ?>
		<label for="license_input_text"><?php p($l->t('Enter a new support key:')); ?></label>
		<input id="license_input_text" type="text" style="width: 350px; max-width: 100%" />
		<input id="license_input_button" type="button" value="<?php p($l->t('Save')); ?>"/>
		<br>
		<input id="license_remove_button" type="button" value="<?php p($l->t('Remove current support key')); ?>"/>
	</div>
</div>