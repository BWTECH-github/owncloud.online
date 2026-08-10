<?php
/**
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Modified by BW-Tech GmbH on 2026-06-25.
 * Changes:
 *   - load shared footer from core in settings invite emails
 *   - unified BW-TECH signature footer, drop casual Cheers sign-off
 */
print_unescaped($l->t("Hey there,\n\njust letting you know that you now have an %s account.\n\nYour username: %s\nAccess it: %s\n\n", [$theme->getName(), $_['username'], $_['url']]));

?>
<?php /* footer lives in core/templates; load it explicitly (inc() would look in the settings app) */ ?>
<?php print_unescaped((new \OC_Template('core', 'plain.mail.footer'))->fetchPage());
