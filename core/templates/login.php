<?php /** @var $l \OCP\IL10N */ ?>
<?php
vendor_script('jsTimezoneDetect/jstz');
script('core', [
	'visitortimezone',
	'lostpassword',
	'login',
]);

// Seconds left of the brute-force cooldown, 0 when the user may try right away.
$throttleRetryAfter = (int)($_['throttleRetryAfter'] ?? 0);

/**
 * Same wording the countdown in js/login.js produces, so the first paint (and
 * the no-JavaScript case) already reads correctly instead of showing a
 * placeholder that only JavaScript fills in.
 */
$formatThrottleTime = function ($seconds) use ($l) {
	if ($seconds >= 60) {
		$minutes = (int)\floor($seconds / 60);
		return $l->n('%n minute', '%n minutes', $minutes)
			. ($seconds % 60 !== 0 ? ' ' . $l->n('%n second', '%n seconds', $seconds % 60) : '');
	}
	return $l->n('%n second', '%n seconds', $seconds);
};
?>

<!--[if IE 8]><style>input[type="checkbox"]{padding:0;}</style><![endif]-->
<form method="post" name="login" autocapitalize="none">
<?php if (!empty($_['accessLink'])) {
	?>
			<p class="warning">
				<?php p($l->t("You are trying to access a private link. Please log in first.")) ?>
			</p>
		<?php
} ?>
	<?php if (!empty($_['redirect_url'])) {
		print_unescaped('<input type="hidden" name="redirect_url" value="' . \OCP\Util::sanitizeHTML($_['redirect_url']) . '">');
	} ?>
		<?php if (isset($_['apacheauthfailed']) && ($_['apacheauthfailed'])): ?>
			<div class="warning">
				<?php p($l->t('Server side authentication failed!')); ?><br>
				<small><?php p($l->t('Please contact your administrator.')); ?></small>
			</div>
		<?php endif; ?>
		<?php foreach ($_['messages'] as $message): ?>
			<div class="warning">
				<?php p($message); ?><br>
			</div>
		<?php endforeach; ?>
		<?php if (isset($_['internalexception']) && ($_['internalexception'])): ?>
			<div class="warning">
				<?php p($l->t('An internal error occurred.')); ?><br>
				<small><?php p($l->t('Please try again or contact your administrator.')); ?></small>
			</div>
		<?php endif; ?>
		<div id="message" class="hidden">
			<img class="float-spinner" alt=""
				src="<?php p(image_path('core', 'loading-dark.gif'));?>">
			<span id="messageText"></span>
			<!-- the following div ensures that the spinner is always inside the #message div -->
			<div style="clear: both;"></div>
		</div>
		<?php if (isset($_['licenseMessage'])): ?>
			<div class="warning">
				<?php print_unescaped($_['licenseMessage']); ?>
			</div>
		<?php endif; ?>
		<?php if ($throttleRetryAfter > 0): ?>
			<?php /* Deliberately not an aria-live region: the notice is present on
					page load, so it is read as part of the document anyway, and a
					live region would re-announce the countdown every single second.
					js/login.js announces the one moment that matters - the end of
					the wait - through #login-throttle-status instead.

					The remaining time is rendered server-side too, so the notice is
					complete and correct without JavaScript; the countdown then just
					stops being live. */ ?>
			<div id="login-throttle" class="warning login-throttle"
				 data-retry-after="<?php p($throttleRetryAfter); ?>">
				<span class="login-throttle-icon" aria-hidden="true">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="none" focusable="false"
						 stroke="currentColor" stroke-width="2"
						 stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="9"></circle>
						<path d="M12 7v5l3 2"></path>
					</svg>
				</span>
				<span class="login-throttle-body">
					<strong class="login-throttle-title">
						<?php p($l->t('Login paused for a moment')); ?>
					</strong>
					<span class="login-throttle-text">
						<?php p($l->t('There were several failed attempts from your connection, so we are briefly slowing sign-in down to protect your account.')); ?>
					</span>
					<span class="login-throttle-text" id="login-throttle-countdown">
						<?php print_unescaped($l->t(
							'You can try again in %s.',
							['<strong id="login-throttle-remaining">'
								. \OCP\Util::sanitizeHTML($formatThrottleTime($throttleRetryAfter))
								. '</strong>']
						)); ?>
					</span>
					<?php /* Der Hinweis nannte einen Zuruecksetzen-Link, den genau diese
						Seite nicht enthaelt: '#lost-password' wird nur bei falschem Passwort
						gerendert, und im Sperrzustand werden die Zugangsdaten nie geprueft -
						der Nutzer las also eine Anweisung, der er nicht folgen konnte
						(SC 3.3.2). Derselbe href und derselbe Handler wie dort, siehe
						lostpassword.js:init. Ist das Zuruecksetzen abgeschaltet, entfaellt
						der Hinweis ganz, statt ins Leere zu zeigen. */ ?>
					<?php if (!empty($_['canResetPassword'])) { ?>
						<span class="login-throttle-text login-throttle-hint">
							<a id="login-throttle-reset" href="<?php p($_['resetPasswordLink']); ?>">
								<?php p($l->t('Forgotten your password? Use the reset link instead of trying again.')); ?>
							</a>
						</span>
					<?php } ?>
					<span class="login-throttle-progress" aria-hidden="true">
						<span class="login-throttle-progress-bar" id="login-throttle-bar"></span>
					</span>
				</span>
			</div>
			<span id="login-throttle-status" class="hidden-visually" role="status" aria-live="polite"></span>
		<?php endif; ?>
		<div class="grouptop<?php if (!empty($_['invalidpassword'])) {
			echo ' shake';
		} ?>">
	<?php
		if ($_['strictLoginEnforced'] === true) {
			$label = $l->t('Login');
		} else {
			$label = $l->t('Username or email');
		}
?>
			<label for="user" class=""><?php p($label); ?></label>
			
			<input type="text" name="user" id="user"
				value="<?php p($_['loginName']); ?>"
				aria-label="<?php $_['strictLoginEnforced'] === true ? p($l->t('Login')) : p($l->t('Username or email')); ?>"
				<?php p($_['user_autofocus'] ? 'autofocus' : ''); ?>
				placeholder="<?php p($label); ?>"
				autocomplete="on" autocorrect="off" required>
			
		</div>

		<div class="groupbottom<?php if (!empty($_['invalidpassword'])) {
			echo ' shake';
		} ?>">
			<label for="password" class=""><?php p($l->t('Password')); ?></label>
			
			<input type="password" name="password" id="password" value=""
				<?php p($_['user_autofocus'] ? '' : 'autofocus'); ?>
				aria-label="<?php p($l->t('Password')); ?>"
				placeholder="<?php p($l->t('Password')); ?>"
				autocomplete="off" autocorrect="off" required>
		</div>
		
		<div class="submit-wrap">
			<?php if (!empty($_['invalidpassword']) && !empty($_['canResetPassword'])) {
				?>
				<a id="lost-password" class="warning" href="<?php p($_['resetPasswordLink']); ?>">
					<?php p($l->t('Wrong password. Reset it?')); ?>
				</a>
				<?php
			} elseif (!empty($_['invalidpassword'])) {
				?>
					<p class="warning">
						<?php p($l->t('Wrong password.')); ?>
					</p>
				<?php
			} ?>

			<?php if (!empty($_['csrf_error'])) {
				?>
					<p class="warning">
						<?php p($l->t('You took too long to log in, please try again now')); ?>
					</p>
					<?php
			} ?>
				
			<button type="submit" id="submit" class="login-button"
				<?php if ($throttleRetryAfter > 0) {
					// Points at the notice so assistive technology can reach the
					// reason from the button. Not disabled server-side: without
					// JavaScript nothing would ever re-enable it, and the server
					// refuses the attempt anyway - js/login.js disables it for
					// exactly as long as the countdown runs.
					print_unescaped('aria-describedby="login-throttle"');
				} ?>>
				<span><?php p($l->t('Login')); ?></span>
				<div class="loading-spinner"><div></div><div></div><div></div><div></div></div>
			</button>
		</div>

		<?php if ($_['rememberLoginAllowed'] === true) : ?>
		<div class="remember-login-container">
			<?php
					$stayLoggedInText = $l->t('Stay logged in');

			if ($_['rememberLoginState'] === 0) {
				?>
			<input type="checkbox" name="remember_login" value="1" id="remember_login" class="checkbox checkbox--white" aria-label="<?php p($stayLoggedInText); ?>">
			<?php
			} else {
				?>
			<input type="checkbox" name="remember_login" value="1" id="remember_login" class="checkbox checkbox--white" checked="checked" aria-label="<?php p($stayLoggedInText); ?>">
			<?php
			} ?>
			<label for="remember_login"><?php p($stayLoggedInText); ?></label>
		</div>
		<?php endif; ?>
		<input type="hidden" name="timezone-offset" id="timezone-offset"/>
		<input type="hidden" name="timezone" id="timezone"/>
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']) ?>">

</form>
<?php if (!empty($_['alt_login'])) {
	?>
<form id="alternative-logins">
		<legend><?php p($l->t('Alternative Logins')) ?></legend>
		<ul>
			<?php foreach ($_['alt_login'] as $login): ?>
				<?php if (isset($login['img'])) {
					?>
					<li><a href="<?php print_unescaped($login['href']); ?>" ><img src="<?php p($login['img']); ?>"/></a></li>
				<?php
				} else {
					?>
						<li><a class="button" href="<?php print_unescaped($login['href']); ?>" ><?php p($login['name']); ?></a></li>
					<?php
				} ?>
			<?php endforeach; ?>
		</ul>
</form>
<?php
}
