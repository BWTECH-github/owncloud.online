<?php /** @var $l \OCP\IL10N */ ?>
<?php $_['appNavigation']->printPage(); ?>
<div id="app-content">
	<?php foreach ($_['appContents'] as $content) {
		// Each view container starts with an inert <template> as its first
		// child. Inactive views are parked inside it so that only one set
		// of list ids exists in the document at any time (WCAG 2.1
		// SC 4.1.1, duplicate ids - OC-WCAG-279 / OP-185). The 'files'
		// view starts inline because its FileList is constructed eagerly
		// on document-ready (apps/files/js/app.js); its empty template is
		// the parking slot used later by OCA.Files.Navigation.
		$parked = $content['id'] !== 'files';
		?>
	<div id="app-content-<?php p($content['id']) ?>" class="hidden viewcontainer">
	<template class="viewcontent"><?php if ($parked) {
		print_unescaped($content['content']);
	} ?></template>
	<?php if (!$parked) {
		print_unescaped($content['content']);
	} ?>
	</div>
	<?php
	} ?>
	<div id="searchresults" class="hidden"></div>
</div><!-- closing app-content -->

<!-- config hints for javascript -->
<input type="hidden" name="filesApp" id="filesApp" value="1" />
<input type="hidden" name="usedSpacePercent" id="usedSpacePercent" value="<?php p($_['usedSpacePercent']); ?>" />
<input type="hidden" name="owner" id="owner" value="<?php p($_['owner']); ?>" />
<input type="hidden" name="ownerDisplayName" id="ownerDisplayName" value="<?php p($_['ownerDisplayName']); ?>" />
<input type="hidden" name="fileNotFound" id="fileNotFound" value="<?php p($_['fileNotFound']); ?>" />
<?php if (!$_['isPublic']) :?>
<input type="hidden" name="mailNotificationEnabled" id="mailNotificationEnabled" value="<?php p($_['mailNotificationEnabled']) ?>" />
<input type="hidden" name="mailPublicNotificationEnabled" id="mailPublicNotificationEnabled" value="<?php p($_['mailPublicNotificationEnabled']) ?>" />
<input type="hidden" name="socialShareEnabled" id="socialShareEnabled" value="<?php p($_['socialShareEnabled']) ?>" />
<input type="hidden" name="allowShareWithLink" id="allowShareWithLink" value="<?php p($_['allowShareWithLink']) ?>" />
<input type="hidden" name="publicUploadEnabled" id="publicUploadEnabled" value="<?php p($_['publicUploadEnabled']) ?>" />
<input type="hidden" name="defaultFileSorting" id="defaultFileSorting" value="<?php p($_['defaultFileSorting']) ?>" />
<input type="hidden" name="defaultFileSortingDirection" id="defaultFileSortingDirection" value="<?php p($_['defaultFileSortingDirection']) ?>" />
<input type="hidden" name="showHiddenFiles" id="showHiddenFiles" value="<?php p($_['showHiddenFiles']); ?>" />
<?php endif;
