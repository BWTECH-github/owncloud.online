<ul id="usergrouplist" data-sort-groups="<?php p($_['sortGroups']); ?>">
	<!-- Add new group -->
	<?php if ($_['isAdmin']) {
		?>
	<?php /* OC-WCAG-189..193: Diese Anker navigieren nicht, sie schalten die
		Gruppenauswahl bzw. loesen Aktionen aus - vorgelesen wurde aber "Link".
		role="button" korrigiert die Rolle (SC 4.1.2), ohne das Element zu
		tauschen: <button> wuerde die 16 Regeln in core/css/apps.css verlieren,
		die #app-navigation-Anker gestalten, und die geteilt sich jede App-
		Navigation. href bleibt, damit Fokus und Eingabetaste nativ erhalten
		bleiben; die Leertaste ergaenzt groups.js. */ ?>
	<li id="newgroup-init">
		<a href="#" role="button">
			<span><?php p($l->t('Add Group'))?></span>
		</a>
	</li>
	<?php
	} ?>
	<li id="newgroup-form" style="display: none">
		<form>
			<label for="newgroupname" class="hidden-visually"><?php p($l->t('Group')); ?></label>
			<input type="text" id="newgroupname" placeholder="<?php p($l->t('Group')); ?>..." />
			<input type="submit" class="button icon-add" value="" aria-label="<?php p($l->t('Add Group')); ?>" />
		</form>
	</li>
	<!-- Everyone -->
	<li id="everyonegroup" data-gid="_everyone" data-usercount="" class="isgroup">
		<a href="#" role="button">
			<span class="groupname" title="<?php p($l->t('Everyone')); ?>">
				<?php p($l->t('Everyone')); ?>
			</span>
			<span class="usercount tag" id="everyonecount">
			</span>
		</a>
		<span class="utils">
		</span>
	</li>

	<!-- The Admin Group -->
	<?php foreach ($_["adminGroup"] as $adminGroup): ?>
		<li data-gid="admin" data-usercount="<?php p($adminGroup['usercount']); ?>" class="isgroup">
			<a href="#" role="button">
				<span class="groupname" title="<?php p($l->t('Admins')); ?>">
					<?php p($l->t('Admins')); ?>
				</span>
				<span class="usercount tag">
					<?php p($adminGroup['usercount']); ?>
				</span>
			</a>
			<span class="utils">
			</span>
		</li>
	<?php endforeach; ?>

	<!--List of Groups-->
	<?php foreach ($_["groups"] as $group): ?>
		<li data-gid="<?php p($group['id']) ?>" data-usercount="<?php p($group['usercount']) ?>" class="isgroup">
			<a href="#" role="button" class="dorename">
				<span class="groupname" title="<?php p($group['name']); ?>">
					<?php p($group['name']); ?>
				</span>
				<span class="usercount tag">
						<?php p($group['usercount']); ?>
				</span>
			</a>
			<span class="utils">
					<?php if ($_['isAdmin']): ?>
				<a href="#" role="button" class="action delete" aria-label="<?php p($l->t('Delete'))?>">
					<img src="<?php print_unescaped(image_path('core', 'actions/delete.svg')) ?>" alt="" />
				</a>
				<?php endif; ?>
			</span>
		</li>
	<?php endforeach; ?>
</ul>
