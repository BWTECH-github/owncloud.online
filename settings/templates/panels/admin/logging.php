<?php
/**
 * @var array $_
 * @var \OCP\IL10N $l
 * @var OC_Defaults $theme
 */
$levelLabels = [
	$l->t('Everything (fatal issues, errors, warnings, info, debug)'),
	$l->t('Info, warnings, errors and fatal issues'),
	$l->t('Warnings, errors and fatal issues'),
	$l->t('Errors and fatal issues'),
	$l->t('Fatal issues only'),
];
?>
<?php if ($_['showLog'] && $_['doesLogFileExist']): ?>
	<div class="section">
		<h2 class="app-name"><?php p($l->t('Log')); ?></h2>
		<?php /* OC-WCAG-008: Das Auswahlfeld hatte keinen barrierefreien Namen. Der sichtbare
				 Text war ein blosser Textknoten und damit nicht programmatisch zugeordnet.
				 <label for> nutzt den bereits vorhandenen l10n-String weiter. */ ?>
		<p><label for="loglevel"><?php p($l->t('What to log')); ?></label>
			<select name='loglevel' id='loglevel'>
				<?php for ($i = 0; $i < 5; $i++):
					$selected = '';
					if ($i == $_['loglevel']):
						$selected = 'selected="selected"';
					endif; ?>
					<option value='<?php p($i) ?>' <?php p($selected) ?>><?php p($levelLabels[$i]) ?></option>
				<?php endfor; ?>
			</select><span id="log_level_save_msg"></span>
		</p>
		<br/>
		<?php if ($_['logFileSize'] > 0): ?>
			<a href="<?php print_unescaped($_['urlGenerator']->linkToRoute('settings.LogSettings.download')); ?>"
			   class="button" id="downloadLog"
			><?php p($l->t('Download logfile (%s)', [\OCP\Util::humanFileSize($_['logFileSize'])])); ?></a>
		<?php endif; ?>
		<?php if ($_['logFileSize'] > (100 * 1024 * 1024)): ?>
			<br>
			<em>
				<?php p($l->t('The logfile is bigger than 100 MB. Downloading it may take some time!')); ?>
			</em>
		<?php endif; ?>
	</div>
<?php endif; ?>
