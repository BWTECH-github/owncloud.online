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
<?php if ($_['showLog'] && $_['doesLogFileExist']):
	script('settings', 'panels/logviewer');
	?>
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

		<?php /* Protokollansicht: Die Einträge holt panels/logviewer.js von
			/settings/admin/log/entries. Der Filter nach Stufe nutzt die Texte der
			Logstufe oben, er zeigt die gewählte Stufe und alles darüber. */ ?>
		<div id="log-viewer" class="log-viewer">
			<h3 id="log-viewer-heading"><?php p($l->t('Log entries')); ?></h3>
			<form id="log-viewer-filter" class="log-viewer-filter" role="search" aria-labelledby="log-viewer-heading">
				<div class="log-viewer-field">
					<label for="log-viewer-level"><?php p($l->t('Level')); ?></label>
					<select id="log-viewer-level" name="level">
						<option value=""><?php p($levelLabels[0]); ?></option>
						<?php for ($i = 1; $i < 5; $i++): ?>
							<option value="<?php p($i); ?>"><?php p($levelLabels[$i]); ?></option>
						<?php endfor; ?>
					</select>
				</div>
				<div class="log-viewer-field">
					<label for="log-viewer-app"><?php p($l->t('App')); ?></label>
					<input type="text" id="log-viewer-app" name="app" list="log-viewer-apps" autocomplete="off" spellcheck="false">
					<datalist id="log-viewer-apps"></datalist>
				</div>
				<div class="log-viewer-field">
					<label for="log-viewer-search"><?php p($l->t('Search')); ?></label>
					<input type="search" id="log-viewer-search" name="search" autocomplete="off" spellcheck="false">
				</div>
				<button type="submit" id="log-viewer-refresh"><?php p($l->t('Refresh')); ?></button>
			</form>
			<p id="log-viewer-status" class="log-viewer-status" role="status" aria-live="polite"></p>
			<?php /* Die Rollen stehen ausdrücklich da: Schmal zeigt settings.css die
				Einträge als Karten (display: block/flex), und Safari verwirft die
				Tabellensemantik sonst. */ ?>
			<div class="log-viewer-scroll">
				<table id="log-viewer-table" class="grid log-viewer-table" role="table" aria-labelledby="log-viewer-heading">
					<thead role="rowgroup">
						<tr role="row">
							<th scope="col" role="columnheader" class="log-viewer-time"><?php p($l->t('Time')); ?></th>
							<th scope="col" role="columnheader" class="log-viewer-level"><?php p($l->t('Level')); ?></th>
							<th scope="col" role="columnheader" class="log-viewer-app"><?php p($l->t('App')); ?></th>
							<th scope="col" role="columnheader" class="log-viewer-message"><?php p($l->t('Message')); ?></th>
							<th scope="col" role="columnheader" class="log-viewer-user"><?php p($l->t('User')); ?></th>
							<th scope="col" role="columnheader" class="log-viewer-request"><?php p($l->t('Request')); ?></th>
						</tr>
					</thead>
					<tbody role="rowgroup"></tbody>
				</table>
			</div>
			<?php /* Fehler stehen getrennt vom Status direkt über „Mehr laden“: Dort
				sieht man sie auch am Ende einer langen Liste, und role=alert sagt sie
				sofort an. */ ?>
			<p id="log-viewer-error" class="log-viewer-error" role="alert"></p>
			<button type="button" id="log-viewer-more" class="hidden"><?php p($l->t('Load more')); ?></button>
		</div>
	</div>
<?php endif; ?>
