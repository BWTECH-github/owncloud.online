<div class="update" data-productname="<?php p($_['productName']) ?>" data-version="<?php p($_['version']) ?>">
	<div class="updateOverview">
		<?php if ($_['isAppsOnlyUpgrade']) {
			?>
		<h2 class="title"><?php p($l->t('App update required')); ?></h2>
		<?php
		} else {
			?>
		<h2 class="title"><?php p($l->t(
			'%s will be updated to version %s',
			[$_['productName'], $_['version']]
		)); ?></h2>
		<?php
		} ?>
		<?php if (!empty($_['appsToUpgrade'])) {
			?>
		<div class="infogroup">
			<span><?php p($l->t('These apps will be updated:')); ?></span>
			<ul class="content appList">
				<?php foreach ($_['appsToUpgrade'] as $appInfo) {
					?>
				<li><?php p($appInfo['name']) ?> (<?php p($appInfo['id']) ?>)</li>
				<?php
				} ?>
			</ul>
		</div>
		<?php
		} ?>
		<?php if (!empty($_['oldTheme'])) {
			?>
		<div class="infogroup">
			<?php p($l->t('The theme %s has been disabled.', [$_['oldTheme']])) ?>
		</div>
		<?php
		} ?>
		<div class="infogroup bold">
			<?php p($l->t('Please make sure that the database, the config folder and the data folder have been backed up before proceeding.')) ?>
		</div>
		<input class="updateButton" type="button" value="<?php p($l->t('Start update')) ?>">
		<div class="infogroup">
			<?php p($l->t('To avoid timeouts with larger installations, you can instead run the following command from your installation directory:')) ?>
			<pre>./occ upgrade</pre>
		</div>
	</div>

	<div class="update-progress hidden">
		<?php /* „Aktualisierung starten“ blendet sich beim Aktivieren selbst aus. Damit
			der Fokus nicht auf body fällt (SC 2.4.3), setzt js/update.js ihn auf die
			Überschrift - tabindex="-1" macht sie dafür fokussierbar, ohne einen
			Tab-Halt hinzuzufügen. Schritte und Ergebnis stehen in
			#update-progress-message, einer Live-Region; Fehler meldet
			#update-progress-message-error als alert (SC 4.1.3). */ ?>
		<h2 id="update-progress-title" tabindex="-1"></h2>
		<div id="update-progress-icon" class="icon-loading-dark"></div>
		<p id="update-progress-message-error" class="warning hidden" role="alert"></p>
		<ul id="update-progress-message-warnings" class="warning hidden"></ul>
		<p id="update-progress-message" role="status"></p>
		<?php /* Ohne href war der Anker per Tastatur nicht erreichbar (SC 2.1.1). Er
			bleibt ein Anker mit role="button", weil update.css ihn als
			a.update-show-detailed gestaltet; Leertaste und aria-expanded kommen aus
			js/update.js. Das Pfeilbild ist Zierde und braucht keinen Alternativtext. */ ?>
		<a class="update-show-detailed" href="#" role="button" aria-expanded="false" aria-controls="update-progress-detailed"><?php p($l->t('Detailed logs')); ?> <img src="<?php print_unescaped(image_path('', 'actions/caret.svg')); ?>" alt="" /></a>
		<div id="update-progress-detailed" class="hidden warning"></div>
	</div>
</div>
