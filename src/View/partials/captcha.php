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
<div class="captcha-wrap">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>
    <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($captchaSiteKey) ?>"></div>
</div>
<style>.captcha-wrap{margin:1rem 0}</style>
<?php endif; ?>
