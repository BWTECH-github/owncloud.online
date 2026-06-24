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
			/* owncloud.online: Login-Kachel zentriert ueberm Hintergrundbild, ohne Scroll */
			html{height:100%;overflow:hidden;}
			#body-login{display:flex;flex-direction:column;height:100vh;overflow:hidden;background-color:#e9edf2;background-image:url('<?php print_unescaped(image_path('', 'login-background.jpg')); ?>');background-repeat:no-repeat;background-position:center center;background-size:cover;}
			#body-login .wrapper{flex:1 1 auto;min-height:0 !important;height:auto !important;margin:0 !important;display:flex !important;align-items:center;justify-content:center;width:100%;box-sizing:border-box;padding:24px;background:transparent !important;}
			#body-login .v-align{display:block !important;width:100%;max-width:320px;margin:0 auto;padding:32px 30px 26px;background:#fff !important;border-radius:16px;box-shadow:0 18px 50px rgba(4,30,66,.22);box-sizing:border-box;text-align:left;}
			#body-login .v-align #header{margin:0 0 18px;text-align:center;}
			#body-login .v-align #header .logo{margin:0 auto;float:none;}
			#body-login form{background:transparent !important;margin:0;box-shadow:none;border:none;text-align:left;}
			#body-login .v-align label,#body-login .v-align form>p{text-align:left;}
			#body-login .v-align .grouptop,#body-login .v-align .groupbottom{margin:0 !important;}
			#body-login .v-align .submit-wrap{margin:8px 0 0 !important;padding:0 !important;}
			#body-login .v-align form,#body-login .v-align .grouptop,#body-login .v-align .groupbottom,#body-login .v-align .submit-wrap,#body-login .v-align input,#body-login .v-align button,#body-login .v-align .login-button{width:100% !important;max-width:100% !important;box-sizing:border-box !important;}
			#body-login .v-align input[type="text"],#body-login .v-align input[type="password"],#body-login .v-align input[type="email"]{display:block;box-sizing:border-box;height:44px;width:100%;margin:0 0 12px;padding:9px 12px;border:1px solid #d6dce4 !important;border-radius:8px !important;background:#fff !important;color:#26384d;font-size:14px;box-shadow:none !important;text-align:left;}
			#body-login .v-align input:focus{border-color:#00b596 !important;outline:none !important;box-shadow:none !important;}
			#body-login .v-align input:focus-visible{box-shadow:0 0 0 2px rgba(0,181,150,.35) !important;}
			#body-login *:focus:not(:focus-visible){outline:none !important;box-shadow:none !important;}
			#body-login .v-align .login-button,#body-login .v-align input[type="submit"],#body-login .v-align button[type="submit"]{display:block;height:46px;width:100%;margin:6px 0 0;background:#00e4bd !important;color:#fff !important;border:none !important;border-radius:8px !important;font-weight:600;font-size:15px;cursor:pointer;box-shadow:none !important;}
			#body-login .v-align .login-button:hover,#body-login .v-align button[type="submit"]:hover{background:#00c9a7 !important;}
			#body-login footer{flex:0 0 auto;text-align:center;padding:10px 0 14px;margin:0;}
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
