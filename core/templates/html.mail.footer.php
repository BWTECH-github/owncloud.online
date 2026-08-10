<?php
/**
 *
 * Einheitlicher owncloud.online E-Mail-Footer (Signatur). Gruss + Slogan sind
 * uebersetzbar ($l->t -> folgt der Sprache); der rechtliche Block (Anschrift,
 * Geschaeftsfuehrer, Register, USt-ID) ist feststehend.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-25.
 * Changes:
 *   - use BW-TECH brand red #e6374b in the signature footer
 *   - unified BW-TECH signature footer, drop casual Cheers sign-off
 */
?>
<?php p($l->t('Best regards,')); ?><br>
<?php print_unescaped($l->t('your %s Team', [$theme->getName()])); ?>
<br><br>
<strong><?php p($theme->getName()); ?></strong> &ndash; <?php p($theme->getSlogan()); ?>
<br><br>
<?php p($l->t('A trademark of')); ?><br>
<span style="color:#e6374b;font-weight:bold;">BW-TECH GMBH</span><br>
IT Service für Systemintegration und Datensicherheit<br>
Albert-Bassermann-Strasse 31, D-68782 Brühl<br>
Phone: +49 6202 95323 - 41 / Fax: +49 6202 95323 &ndash; 99
<br><br>
<a href="https://www.bw-tech.de" style="color:#00b596;">https://www.bw-tech.de</a> / <a href="<?php p($theme->getBaseUrl()); ?>" style="color:#00b596;"><?php p($theme->getBaseUrl()); ?></a>
<br><br>
<span style="color:#5b6675;">
Geschäftsführer: Thomas Wuckel | Frank Böttcher<br>
Registergericht: Mannheim . Registernummer: HRB 704981<br>
Ust-ID-Nr.: DE261314735&nbsp;&nbsp;Steuer-Nr.: 43004/12609
</span>
