<?php if (OC_Util::getEditionString() === OC_Util::EDITION_COMMUNITY): ?>
	<p>
		<?php print_unescaped(\str_replace(
	[
		'{communityopen}',
		'{bwtechopen}',
		'{githubopen}',
		'{licenseopen}',
		'{linkclose}',
	],
	[
		'<a href="https://owncloud.online" target="_blank" rel="noreferrer">',
		'<a href="https://www.bw.tech" target="_blank" rel="noreferrer">',
		'<a href="https://github.com/BWTECH-github/owncloud.online" target="_blank" rel="noreferrer">',
		'<a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noreferrer">',
		'</a>',
	],
	$l->t('Developed by the {communityopen}ownCloud community{linkclose} and continued by {bwtechopen}BW.Tech{linkclose}, the {githubopen}source code{linkclose} is licensed under the {licenseopen}<abbr title="Affero General Public License">AGPL</abbr>{linkclose}.')
)); ?>
	</p>
<?php endif; ?>
