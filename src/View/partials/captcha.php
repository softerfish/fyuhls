<?php
/**
 * Shared Turnstile captcha widget.
 * Include this partial inside a form where captcha should appear.
 *
 * Required variables:
 *   $captchaEnabled  bool   - whether captcha is on for this location
 *   $captchaSiteKey  string - the Turnstile site key from settings
 */
$captchaEnabled  = $captchaEnabled ?? false;
$captchaSiteKey  = $captchaSiteKey ?? \App\Model\Setting::get('captcha_site_key', '');
?>
<?php if ($captchaEnabled && $captchaSiteKey): ?>
<div style="margin: 1rem 0;">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>
    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($captchaSiteKey) ?>"></div>
</div>
<?php endif; ?>
