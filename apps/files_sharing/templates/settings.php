<?php
/**
 * @author Juan Pablo Villafáñez <jvillafanez@solidgear.es>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-08-06.
 * Changes: style(frontend): Accessibility at ownCloud.online - WACG 2.1 AA Check
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
script('files_sharing', 'settings');
?>

<div class="section" id="files_sharing">
	<h2 class="app-name"><?php p($l->t('Group Sharing Blacklist')); ?></h2>
	<div class="indent">
		<?php /* OC-WCAG-002: barrierefreier Name fuer die Gruppenauswahl
				(SC 3.3.2 / 4.1.2 / 1.3.1). Die id ist hier zwingend, nicht
				optional: select2 3.5.4 kopiert in
				core/vendor/select2/select2.js den Text von label[for=<id>] in
				sein eigenes Offscreen-Label des sichtbaren Suchfelds. Ohne id
				wird der Selektor zu label[for='undefined'], das Label bleibt
				leer und das Widget unbenannt.

				aria-label waere hier wirkungslos: select2 setzt das Original
				auf display:none und tabindex="-1", es ist damit gar nicht mehr
				im Accessibility-Tree. Bedienbar ist nur das erst zur Laufzeit
				erzeugte Suchfeld.

				Das <p> bleibt erhalten und nimmt das <label> auf, damit
				Blockfluss und Position des Widgets unveraendert bleiben. */ ?>
		<p><label for="blacklistedReceiverGroups"><?php p($l->t('Exclude groups from receiving shares')); ?></label></p>
		<input name="blacklisted_receiver_groups" id="blacklistedReceiverGroups" class="noautosave" value="<?php p($_['blacklistedReceivers']) ?>" style="width: 400px"/>
		<br />
		<em><?php p($l->t('These groups will not be available to share with. Members of the group are not restricted in initiating shares and receiving personal shares/invitations. Furthermore they can receive shares addressed to other groups they are members of as usual.')); ?></em>
	</div>

	<p>
		<input type="checkbox"
			   name="public_share_sharers_groups_allowlist_enabled"
			   id="publicShareSharersGroupsAllowlistEnabled" class="checkbox noautosave"
			   value="1" <?php if ($_['publicShareSharersGroupsAllowlistEnabled'] === 'yes') {
			   	print_unescaped('checked="checked"');
			   } ?> />
		<label for="publicShareSharersGroupsAllowlistEnabled"><?php p($l->t('Only certain groups are allowed to create public links')); ?></label><br/>
		<span id="setAllowlistPublicShareSharersGroups"
			  class="indent <?php if ($_['publicShareSharersGroupsAllowlistEnabled'] === 'no') {
			  	p('hidden');
			  } ?>">
		  <?php /* OC-WCAG-003: dasselbe select2-Muster, hier fehlte nur das
				  Label - die id war schon vorhanden. Bewusst visuell
				  ausgeblendet: anders als beim Feld oben gibt es hier keinen
				  eigenen sichtbaren Beschriftungstext, und ein zweiter
				  sichtbarer Satz direkt unter der Checkbox waere doppelte
				  Beschriftung. Nachgenutzt wird der bereits uebersetzte
				  Checkbox-String; ein eigener, praeziserer String waere
				  schoener, muesste aber erst uebersetzt werden. */ ?>
		  <label for="allowlistPublicShareSharersGroups" class="hidden-visually"><?php p($l->t('Only certain groups are allowed to create public links')); ?></label>
		  <input name="public_share_sharers_groups_allowlist"
				 id="allowlistPublicShareSharersGroups"
				 class="noautosave"
				 value="<?php p($_['publicShareSharersGroupsAllowlist']) ?>"
				 style="width: 400px"/>
		  </span>
		</p>
</div>