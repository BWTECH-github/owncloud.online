<?php
/**
 *
 * Einheitlicher owncloud.online Plaintext-E-Mail-Footer (Signatur). Gruss +
 * Slogan folgen der Sprache ($l->t); der rechtliche Block ist feststehend.
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-25.
 * Changes:
 *   - unified BW-TECH signature footer, drop casual Cheers sign-off
 */
p($l->t('Best regards,'));
p("\n");
p($l->t('your %s Team', [$theme->getName()]));
p("\n\n");
p($theme->getName() . ' - ' . $theme->getSlogan());
p("\n\n");
p($l->t('A trademark of'));
p("\nBW-TECH GMBH");
p("\nIT Service für Systemintegration und Datensicherheit");
p("\nAlbert-Bassermann-Strasse 31, D-68782 Brühl");
p("\nPhone: +49 6202 95323 - 41 / Fax: +49 6202 95323 – 99");
p("\n\nhttps://www.bw-tech.de / " . $theme->getBaseUrl());
p("\n\nGeschäftsführer: Thomas Wuckel | Frank Böttcher");
p("\nRegistergericht: Mannheim . Registernummer: HRB 704981");
p("\nUst-ID-Nr.: DE261314735  Steuer-Nr.: 43004/12609");
