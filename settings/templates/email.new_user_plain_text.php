<?php
print_unescaped($l->t("Hey there,\n\njust letting you know that you now have an %s account.\n\nYour username: %s\nAccess it: %s\n\n", [$theme->getName(), $_['username'], $_['url']]));

?>
<?php print_unescaped($this->inc('plain.mail.footer'));
