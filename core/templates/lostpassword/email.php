<table cellspacing="0" cellpadding="0" border="0" width="100%">
<tr><td>
<table cellspacing="0" cellpadding="0" border="0" width="600px">
<tr>
<td colspan="2" style="padding:0;">
<table cellspacing="0" cellpadding="0" border="0" width="100%">
<tr><td style="height:4px;line-height:4px;font-size:0;background-color:<?php p($theme->getMailHeaderColor());?>;">&nbsp;</td></tr>
<tr><td align="center" style="padding:26px 0 14px;background-color:#ffffff;"><img src="<?php p(\OC::$server->getURLGenerator()->getAbsoluteURL(image_path('', 'logo-mail.gif'))); ?>" alt="<?php p($theme->getName()); ?>" style="display:block;margin:0 auto;border:0;max-width:210px;height:auto;"></td></tr>
</table>
</td>
</tr>
<tr><td colspan="2">&nbsp;</td></tr>
<tr>
<td width="20px">&nbsp;</td>
<td style="font-weight:normal; font-size:0.8em; line-height:1.2em; font-family:verdana,'arial',sans;">
<?php
/**
 * @author Christopher Schäpers <kondou@ts.unde.re>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
print_unescaped(\str_replace('{link}', "<a href=\"{$_['link']}\">{$_['link']}</a>", $l->t('Use the following link to reset your password: {link}')));
print_unescaped("<br><br>");
?>
</td>
</tr>
<tr><td colspan="2">&nbsp;</td></tr>
<tr>
<td width="20px">&nbsp;</td>
<td style="font-weight:normal; font-size:0.8em; line-height:1.2em; font-family:verdana,'arial',sans;">
	<?php print_unescaped($this->inc('html.mail.footer')); ?>
</td>
</tr>
<tr>
<td colspan="2">&nbsp;</td>
</tr>
</table>
</td></tr>
</table>
