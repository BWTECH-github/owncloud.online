<?php
/**
 * @author Noveen Sachdeva "noveen.sachdeva@research.iiit.ac.in"
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-08-10.
 * Changes:
 *   - Accessibility at ownCloud.online - WACG 2.1 AA Check [OC-WCAG-271,-272, -...
 */

script('settings', 'panels/cors');

?>

<div class="section" id="cors">
	<h2 class="app-name">CORS</h2>
	<span class="app-name">Cross-Origin Resource Sharing</span>

	<h3><?php p($l->t('White-listed Domains')); ?></h3>
	<p id="noDomains" <?php if (!empty($_['domains'])) { ?>class="hidden"<?php } ?>>
		<?php p($l->t('No Domains.')); ?>
	</p>

	<table class="grid">
		<thead>
		<tr>
			<th id="headerName" scope="col"><?php p($l->t('Domain')); ?></th>
			<th id="headerRemove">&nbsp;</th>
		</tr>
		</thead>
		<tbody>
			<?php foreach ($_['domains'] as $id => $domain) { ?>
				<tr>
					<td><?php p($domain); ?></td>
					<td>
						<input data-domain="<?php p($domain) ?>" type="button" class="button icon-delete removeDomainButton" value="" aria-label="<?php p($l->t('Delete')); ?>">
					</td>
				</tr>
			<?php } ?>
		</tbody>
	</table>

	<h3><?php p($l->t('Add Domain')); ?></h3>
		<label for="domain" class="hidden-visually"><?php p($l->t('Domain')); ?></label>
		<input id="domain" name="domain" type="text" placeholder="<?php p($l->t('Domain')); ?>">
		<input id="corsAddNewDomain" type="submit" class="button" value="<?php p($l->t('Add')); ?>">
	</form>
</div>
