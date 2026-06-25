<?php
print_unescaped($l->t("Hey there,\n\njust letting you know that you now have an %s account.\n\nYour username: %s\nAccess it: %s\n\n", [$theme->getName(), $_['username'], $_['url']]));

?>
<?php /* footer lives in core/templates; load it explicitly (inc() would look in the settings app) */ ?>
<?php print_unescaped((new \OC_Template('core', 'plain.mail.footer'))->fetchPage());
