<div id="controls">
		<div class="actions creatable hidden">
			<div id="uploadprogresswrapper">
				<div id="uploadprogressbar">
					<em class="label outer" style="display:none"><span class="desktop"><?php p($l->t('Uploading...'));?></span><span class="mobile"><?php p($l->t('...'));?></span></em>
				</div>
				<?php /* Das Bedienelement bricht einen laufenden Upload ab (Klickbindung
				in js/file-upload.js:1112, eingeblendet in Z. 1482). Es ist
				ein reines Symbol-Bedienelement: die Grafik kommt aus
				.icon-close, value bleibt leer, weil ein Wert hier als
				sichtbarer Text im Knopf erscheinen und die Symboldarstellung
				zerstoeren wuerde. Bei <input type="button"> IST value der
				barrierefreie Name - ohne ihn bleibt der Name leer. aria-label
				traegt ihn daher, ohne die Darstellung anzutasten. */ ?>
				<input type="button" class="stop icon-close" style="display:none" value=""
					   aria-label="<?php p($l->t('Cancel')); ?>" />
			</div>
		</div>
		<div id="file_action_panel"></div>
		<div class="notCreatable notPublic hidden">
			<?php p($l->t('You don’t have permission to upload or create files here'))?>
		</div>
	<?php /* Note: the template attributes are here only for the public page. These are normally loaded
			 through ajax instead (updateStorageStatistics).
	*/ ?>
	<input type="hidden" name="permissions" value="" id="permissions">
	<input type="hidden" id="free_space" value="<?php isset($_['freeSpace']) ? p($_['freeSpace']) : '' ?>">
	<?php if (isset($_['dirToken'])):?>
	<input type="hidden" id="publicUploadRequestToken" name="requesttoken" value="<?php p($_['requesttoken']) ?>" />
	<input type="hidden" id="dirToken" name="dirToken" value="<?php p($_['dirToken']) ?>" />
	<?php endif;?>
	<input type="hidden" class="max_human_file_size"
		   value="(max <?php isset($_['uploadMaxHumanFilesize']) ? p($_['uploadMaxHumanFilesize']) : ''; ?>)">
</div>

<div id="emptycontent" class="hidden">
	<div class="icon-folder"></div>
	<h2><?php p($l->t('No files in here')); ?></h2>
	<p class="uploadmessage hidden"><?php p($l->t('Upload some content or sync with your devices!')); ?></p>
	<p class="nouploadmessage hidden"><?php p($l->t('You don’t have permission to upload or create files here')); ?></p>
</div>

<div class="nofilterresults emptycontent hidden">
	<div class="icon-search"></div>
	<h2><?php p($l->t('No entries found in this folder')); ?></h2>
	<p></p>
</div>

<table id="filestable" data-allow-public-upload="<?php p($_['publicUploadEnabled'])?>" data-preview-x="32" data-preview-y="32">
	<thead>
		<tr>
			<th id='headerName' class="hidden column-name">
				<div id="headerName-container">
					<input type="checkbox" id="select_all_files" class="select-all checkbox"/>
					<label for="select_all_files">
						<span class="hidden-visually"><?php p($l->t('Select all'))?></span>
					</label>
					<button type="button" class="name sort columntitle" data-sort="name"><span><?php p($l->t('Name')); ?></span><span class="sort-indicator"></span></button>
					<span id="selectedActionsList" class="selectedActions">
						<button type="button" class="download">
							<span class="icon icon-download"></span>
							<span><?php p($l->t('Download'))?></span>
						</button>
						<button type="button" class="download mobile button">
							<span class="icon icon-download "></span>
							<span class="hidden-visually"><?php p($l->t('Download'))?></span>
						</button>
						<button type="button" class="delete-selected mobile button">
							<span class="icon icon-delete"></span>
							<span class="hidden-visually"><?php p($l->t('Delete'))?></span>
						</button>
					</span>
				</div>
			</th>
			<th id="headerSize" class="hidden column-size">
				<button type="button" class="size sort columntitle" data-sort="size"><span><?php p($l->t('Size')); ?></span><span class="sort-indicator"></span></button>
			</th>
			<th id="headerDate" class="hidden column-mtime">
				<button type="button" class="columntitle" data-sort="mtime"><span><?php p($l->t('Modified')); ?></span><span class="sort-indicator"></span></button>
					<span class="selectedActions"><button type="button" class="delete-selected">
						<span class="icon icon-delete"></span>
						<span><?php p($l->t('Delete'))?></span>
					</button></span>
			</th>
		</tr>
	</thead>
	<tbody id="fileList">
	</tbody>
	<tfoot>
	</tfoot>
</table>
<input type="hidden" name="dir" id="dir" value="" />
<div class="hiddenuploadfield">
	<?php /* Das Feld ist per CSS auf 0x0 und opacity:0 gesetzt. Es gehoert
	deshalb NICHT in den Tabulator-Pfad: ein Fokusrahmen auf einer 0x0-Flaeche
	mit opacity:0 wird mitgezeichnet und ist trotzdem unsichtbar - der Nutzer
	saehe nicht, wo der Fokus steht (SC 2.4.7). Bedient wird der Upload ueber
	den Menueeintrag im "Neu"-Menue, der seit OC-WCAG-270 ein <button> ist und
	den Klick an dieses Feld weiterreicht (newfilemenu.js). Das aria-label
	bleibt: das Feld ist weiterhin programmatisch fokussierbar, und der Name
	deckt sich wortgleich mit dem Menueeintrag. */ ?>
	<input type="file" id="file_upload_start" class="hiddenuploadfield" name="files[]"
		   tabindex="-1" aria-label="<?php p($l->t('Upload')); ?>" />
</div>
<div id="editor"></div><!-- FIXME Do not use this div in your app! It is deprecated and will be removed in the future! -->
<div id="uploadsize-message" title="<?php p($l->t('Upload too large'))?>">
	<p>
	<?php p($l->t('The files you are trying to upload exceed the maximum size for file uploads on this server.'));?>
	</p>
</div>
