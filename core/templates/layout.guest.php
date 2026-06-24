<!DOCTYPE html>
<html class="ng-csp" data-placeholder-focus="false" lang="<?php p($_['language']); ?>" translate="no">
	<head data-requesttoken="<?php p($_['requesttoken']); ?>">
		<meta charset="utf-8">
		<meta name="google" content="notranslate" />
		<title>
		<?php p($theme->getTitle()); ?>
		</title>
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="referrer" content="never">
		<meta name="viewport" content="width=device-width, minimum-scale=1.0, maximum-scale=1.0">
		<?php if ($theme->getiTunesAppId() !== '') {
			?>
			<meta name="apple-itunes-app" content="app-id=<?php p($theme->getiTunesAppId()); ?>">
		<?php
		} ?>
		<meta name="theme-color" content="<?php p($theme->getMailHeaderColor()); ?>">
		<link rel="icon" href="<?php print_unescaped(image_path('', 'favicon.ico')); /* IE11+ supports png */ ?>">
		<link rel="apple-touch-icon-precomposed" href="<?php print_unescaped(image_path('', 'favicon-touch.png')); ?>">
		<link rel="mask-icon" sizes="any" href="<?php print_unescaped(image_path('', 'favicon-mask.svg')); ?>" color="#041e42">
		<?php foreach ($_['cssfiles'] as $cssfile): ?>
			<link rel="stylesheet" href="<?php print_unescaped($cssfile); ?>">
		<?php endforeach; ?>
		<?php foreach ($_['printcssfiles'] as $cssfile): ?>
			<link rel="stylesheet" href="<?php print_unescaped($cssfile); ?>" media="print">
		<?php endforeach; ?>
		<?php foreach ($_['jsfiles'] as $jsfile): ?>
			<script src="<?php print_unescaped($jsfile); ?>"></script>
		<?php endforeach; ?>
		<?php print_unescaped($_['headers']); ?>
		<style>
			/* owncloud.online: zentrierte runde Login-Kachel ueberm Hintergrundbild */
			#body-login{background:#e9edf2 url('<?php print_unescaped(image_path('', 'login-background.jpg')); ?>') no-repeat center center fixed;background-size:cover;}
			#body-login .wrapper{display:flex !important;align-items:center;justify-content:center;min-height:100vh;width:100%;box-sizing:border-box;padding:24px;background:transparent !important;}
			#body-login .v-align{display:block !important;width:100%;max-width:360px;margin:0 auto;padding:36px 32px 24px;background:#fff !important;border-radius:16px;box-shadow:0 18px 50px rgba(4,30,66,.22);box-sizing:border-box;}
			#body-login .v-align #header{margin-bottom:14px;}
			#body-login form{background:transparent !important;margin:0;box-shadow:none;border:none;}
			#body-login .v-align input[type="text"],#body-login .v-align input[type="password"],#body-login .v-align input[type="email"]{display:block;box-sizing:border-box;height:44px;width:100%;margin:0 0 10px;padding:9px 12px;border:1px solid #d6dce4 !important;border-radius:8px !important;background:#fff !important;color:#26384d;font-size:14px;box-shadow:none !important;}
			#body-login .v-align input:focus{border-color:#00b596 !important;outline:none !important;box-shadow:none !important;}
			#body-login .v-align input:focus-visible{box-shadow:0 0 0 2px rgba(0,181,150,.35) !important;}
			#body-login *:focus:not(:focus-visible){outline:none !important;box-shadow:none !important;}
			#body-login .v-align .login-button,#body-login .v-align input[type="submit"],#body-login .v-align button[type="submit"]{height:46px;width:100%;margin-top:4px;background:#00e4bd !important;color:#fff !important;border:none !important;border-radius:8px !important;font-weight:600;font-size:15px;cursor:pointer;box-shadow:none !important;}
			#body-login .v-align .login-button:hover,#body-login .v-align button[type="submit"]:hover{background:#00c9a7 !important;}
		</style>
	</head>
	<body id="<?php p($_['bodyid']);?>" <?php
			if ($theme->getName() !== 'ownCloud') {
				print_unescaped('class="theme-' . \str_replace(' ', '-', $theme->getName()) . ' has-theme"');
			} ?> >
		<?php include('layout.noscript.warning.php'); ?>
		<div class="wrapper">
			<div class="v-align">
				<?php if ($_['bodyid'] === 'body-login'): ?>
					<header role="banner">
						<div id="header">
							<div class="logo">
								<h1 class="hidden-visually">
									<?php print_unescaped($theme->getHTMLName()); ?>
								</h1>
							</div>
							<div id="logo-claim" style="display:none;"><?php print_unescaped($theme->getLogoClaim()); ?></div>
						</div>
					</header>
				<?php endif; ?>
				<?php print_unescaped($_['content']); ?>
			</div>
		</div>
		<footer role="contentinfo">
			<p class="info">
				<?php print_unescaped($theme->getLongFooter()); ?>
			</p>
		</footer>
	</body>
</html>
