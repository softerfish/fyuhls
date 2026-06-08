<?php
$db = \App\Core\Database::getInstance()->getConnection();
\App\Service\MailService::ensureDefaultTemplates();

// Stats for the header
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'")->fetchColumn();
$failedCount = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'failed'")->fetchColumn();
$sentToday = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();

// Load current settings
$smtpHost = \App\Model\Setting::get('email_smtp_host', '');
$smtpPort = \App\Model\Setting::get('email_smtp_port', '25');
$fromAddr = \App\Model\Setting::get('email_from_address', '');
$secureMethod = \App\Model\Setting::get('email_secure_method', 'none');
$requiresAuth = \App\Model\Setting::get('email_smtp_requires_auth', '0') === '1';
$smtpUser = \App\Model\Setting::get('email_smtp_auth_username', '');
$limitPerMin = \App\Model\Setting::get('email_limit_per_minute', '20');
$templates = $db->query("SELECT * FROM email_templates ORDER BY template_key ASC")->fetchAll();

$templateGroups = [
    'Account' => [
        'confirm_email',
        'confirm_email_change',
        'welcome_email',
        'forgot_password',
        'package_changed',
        'account_downgrade',
        'premium_expiry_reminder_7d',
        'premium_expiry_reminder_1d',
        'storage_limit_warning',
    ],
    'Security' => [
        'new_device_login',
        'two_factor_enabled',
        'two_factor_disabled',
    ],
    'Rewards' => [
        'withdrawal_request_submitted',
        'withdrawal_status_approved',
        'withdrawal_status_paid',
        'withdrawal_status_rejected',
        'abuse_report_confirmation',
    ],
    'Support' => [
        'contact_form_responder',
        'dmca_form_responder',
        'admin_notification',
    ],
    'Tickets' => [
        'ticket_opened_admin',
        'ticket_opened_user',
        'ticket_staff_replied',
        'ticket_user_replied',
        'ticket_waiting_user_reminder',
        'ticket_closed',
        'contact_submitted_admin',
        'abuse_report_submitted_admin',
        'dmca_report_submitted_admin',
    ],
    'Payments' => [
        'payment_pending',
        'payment_on_hold',
        'payment_completed',
        'payment_failed',
        'payment_denied',
        'payment_refunded',
    ],
];

$templateByKey = [];
foreach ($templates as $templateRow) {
    $templateByKey[$templateRow['template_key']] = $templateRow;
}
?>

<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Email Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#email-overview"><i class="bi bi-bar-chart me-2"></i> Queue Overview</a>
            <a class="nav-link text-start" href="#email-smtp"><i class="bi bi-send-check me-2"></i> SMTP Config</a>
            <a class="nav-link text-start" href="#email-templates"><i class="bi bi-file-earmark-text me-2"></i> Templates</a>
            <a class="nav-link text-start" href="#email-tools"><i class="bi bi-tools me-2"></i> Test Tools</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">Email</h5>
                <p class="config-section-intro__text">Configure SMTP delivery, watch queue health, and manage the system templates that drive account, rewards, support, and payment messages.</p>
            </div>
            <ul class="config-summary-chips">
                <li class="config-summary-chip <?= $pendingCount > 0 ? 'config-summary-chip--warning' : 'config-summary-chip--success' ?>">Queue: <?= number_format($pendingCount) ?> pending</li>
                <li class="config-summary-chip <?= $failedCount > 0 ? 'config-summary-chip--warning' : 'config-summary-chip--success' ?>">Failures: <?= number_format($failedCount) ?></li>
                <li class="config-summary-chip config-summary-chip--info">24h sent: <?= number_format($sentToday) ?></li>
            </ul>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>Fyuhls queues most outbound email and lets cron process it in batches. That means SMTP settings, cron health, and your provider's rate limits all shape how quickly messages leave the system.</p>
            </div>
        </details>

<div class="email-sponsor-callout mb-4">
                    <div class="email-sponsor-callout__eyebrow">Sponsor</div>
                    <div class="email-sponsor-callout__title">
                        Get your email into users inbox: <a href="https://www.hostinger.com/?REFERRALCODE=PHXCORRECHKN" target="_blank" rel="noopener noreferrer">Hostinger Business Email</a>
                    </div>
                    <p class="email-sponsor-callout__text">
                        Packages start at $0.39/month before coupon, and using the supplied link can get you an additional 20% off.
                        <a href="https://www.hostinger.com/?REFERRALCODE=PHXCORRECHKN" target="_blank" rel="noopener noreferrer">Signup now</a>.
                    </p>
                </div>

<div class="row g-4">
    <!-- Queue Stats -->
    <div class="col-12" id="email-overview">
        <div class="row g-3">
            <div class="col-md-4">
                <?php renderAdminStatCard('Pending in Queue', number_format($pendingCount), 'bg-primary text-white', 'h2 mb-0'); ?>
            </div>
            <div class="col-md-4">
                <?php renderAdminStatCard('Sent (Last 24h)', number_format($sentToday), 'bg-success text-white', 'h2 mb-0'); ?>
            </div>
            <div class="col-md-4">
                <?php renderAdminStatCard('Failed Attempts', number_format($failedCount), 'bg-danger text-white', 'h2 mb-0'); ?>
            </div>
        </div>
    </div>

    <!-- SMTP Config -->
    <div class="col-lg-8" id="email-smtp">
        <?php renderAdminCardStart(null, ['headerHtml' => '<i class="bi bi-send-check me-2 text-primary"></i> SMTP Server Configuration', 'bodyClass' => 'p-4', 'cardClass' => 'border-0 shadow-sm config-section-card']); ?>
                <form method="POST" action="/admin/configuration/save" id="smtpForm">
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="section" value="email">

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label fw-bold">SMTP Host</label>
                            <input type="text" class="form-control" name="email_smtp_host" value="<?= htmlspecialchars($smtpHost) ?>" placeholder="smtp.example.com">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Port</label>
                            <input type="number" class="form-control" name="email_smtp_port" value="<?= htmlspecialchars($smtpPort) ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">From Address</label>
                            <input type="email" class="form-control" name="email_from_address" value="<?= htmlspecialchars($fromAddr) ?>" placeholder="noreply@yoursite.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Encryption</label>
                            <select class="form-select" name="email_secure_method">
                                <option value="none" <?= $secureMethod === 'none' ? 'selected' : '' ?>>None</option>
                                <option value="ssl" <?= $secureMethod === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                <option value="tls" <?= $secureMethod === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="email_smtp_requires_auth" id="smtpAuth" value="1" <?= $requiresAuth ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="smtpAuth">Server Requires Authentication</label>
                    </div>

                    <div id="authFields" class="<?= !$requiresAuth ? 'email-auth-optional' : '' ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" name="email_smtp_auth_username" value="<?= htmlspecialchars($smtpUser) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" class="form-control" name="email_smtp_auth_password" placeholder="******** (Leave blank to keep current)">
                            </div>
                        </div>
                        <small id="smtpAuthHelp" class="config-form-note">Most real SMTP providers on ports 465 or 587 expect authentication. Turn on <strong>Server Requires Authentication</strong> to use these credentials.</small>
                    </div>

                    <div class="mb-3 mt-4">
                        <label class="form-label fw-bold">Sending Rate Limit</label>
                        <div class="email-rate-limit input-group">
                            <input type="number" class="form-control" name="email_limit_per_minute" value="<?= htmlspecialchars($limitPerMin) ?>">
                            <span class="input-group-text text-muted small">emails / minute</span>
                        </div>
                        <small class="config-form-note">Adjust this to stay within your provider's hourly limits.</small>
                    </div>
<div class="config-sticky-save">
    <p class="config-sticky-save__text">SMTP changes affect every queued email in the app, so it's worth testing the connection before walking away.</p>
    <div class="email-sticky-actions">
        <div class="d-flex gap-2 flex-wrap justify-content-end">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-2"></i> Save SMTP Config
            </button>
            <button type="button" class="btn btn-outline-dark" id="testSmtpConnectionBtn">
                <i class="bi bi-plug me-2"></i> Test Connection
            </button>
        </div>
        <div id="smtpConnectionResult" class="email-test-result small mt-2" role="status" aria-live="polite" hidden></div>
    </div>
</div>
</form>
<?php renderAdminCardEnd(); ?>
</div>

<!-- Quick Tools -->
<div class="col-lg-4" id="email-tools">
    <div class="card border-0 shadow-sm mb-4 config-section-card">
        <div class="card-header bg-white fw-bold py-3">Send Test Email</div>
        <div class="card-body">
            <p class="small text-muted">Verify your SMTP configuration by sending a real email to yourself.</p>
            <div class="input-group mb-3">
                <input type="email" id="testEmailAddr" class="form-control form-control-sm" placeholder="your@email.com">
                <button class="btn btn-sm btn-dark" type="button" id="sendTestEmailBtn">Send</button>
            </div>
            <div id="testResult" class="email-test-result small mt-2" role="status" aria-live="polite" hidden></div>
        </div>
    </div>

    <div class="config-soft-callout config-soft-callout--info config-section-card">
        <h6 class="fw-bold mb-2 small text-uppercase">Enterprise Note</h6>
        <p class="extra-small mb-0">
            For high-volume sites (thousands of emails/day), ensure your <strong>Cron Heartbeat</strong> is set to run every minute. This allows the <code>MailQueueService</code> to process batches steadily without overwhelming your SMTP provider.
        </p>
    </div>
</div>
</div>

<div id="email-templates"></div>
<?php renderAdminCardStart(null, ['headerHtml' => '<div class="d-flex justify-content-between align-items-center"><span><i class="bi bi-file-earmark-text me-2 text-primary"></i> System Email Templates</span></div>', 'bodyClass' => 'p-0', 'cardClass' => 'border-0 shadow-sm mt-4 config-section-card']); ?>
<div class="table-responsive">
<table class="table table-hover align-middle mb-0">
<thead class="bg-light extra-small text-uppercase fw-bold">
    <tr>
        <th class="ps-4">Group</th>
        <th class="ps-4">Template Name</th>
        <th>Subject</th>
        <th class="text-end pe-4">Actions</th>
    </tr>
</thead>
<tbody>
    <?php foreach ($templateGroups as $groupLabel => $templateKeys): ?>
        <?php foreach ($templateKeys as $index => $templateKey): ?>
            <?php if (empty($templateByKey[$templateKey])) continue; ?>
            <?php $t = $templateByKey[$templateKey]; ?>
        <tr>
            <td class="email-template-group ps-4 small text-muted fw-bold"><?= $index === 0 ? htmlspecialchars($groupLabel) : '' ?></td>
            <td class="ps-4">
                <div class="fw-bold small"><?= str_replace('_', ' ', ucfirst($t['template_key'])) ?></div>
                <code class="extra-small text-muted"><?= $t['template_key'] ?></code>
                <?php if (!empty($t['description'])): ?>
                    <div class="extra-small text-muted mt-1"><?= htmlspecialchars($t['description']) ?></div>
                <?php endif; ?>
            </td>
            <td class="email-template-subject small"><?= htmlspecialchars($t['subject']) ?></td>
            <td class="text-end pe-4">
                <button class="btn btn-sm btn-outline-primary" type="button" data-template='<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>'>
                    <i class="bi bi-pencil"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
</tbody>
</table>
</div>
<?php renderAdminCardEnd(); ?>
</div>
</div>

<!-- Template Edit Modal -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="/admin/configuration/save">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="section" value="email_template">
                <input type="hidden" name="template_key" id="tplKey">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold" id="tplTitle">Edit Template</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject Line</label>
                        <input type="text" class="form-control" name="subject" id="tplSubject" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email Body</label>
                        <textarea class="form-control font-monospace small" name="body" id="tplBody" rows="12" required></textarea>
                        <div class="mt-2 extra-small text-muted">
                            Available variables include <code>{username}</code>, <code>{site_name}</code>, <code>{site_url}</code>, <code>{support_email}</code>, <code>{email}</code>, <code>{current_year}</code>, plus template-specific values like <code>{confirm_link}</code>, <code>{new_email}</code>, <code>{reset_link}</code>, <code>{subject}</code>, <code>{event_type}</code>, <code>{details}</code>, <code>{file_name}</code>, <code>{expiry_date}</code>, <code>{usage_percent}</code>, <code>{threshold}</code>, <code>{max_storage}</code>, <code>{old_package}</code>, <code>{new_package}</code>, <code>{amount}</code>, <code>{method}</code>, <code>{admin_note}</code>, <code>{gateway}</code>, <code>{package_name}</code>, <code>{login_ip}</code>, <code>{login_time}</code>, <code>{ticket_id}</code>, <code>{ticket_subject}</code>, <code>{ticket_status}</code>, <code>{ticket_type}</code>, <code>{ticket_url}</code>, <code>{user_name}</code>, <code>{user_email}</code>, <code>{reply_message}</code>, and <code>{support_inbox_email}</code>.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Update Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.email-auth-optional{opacity:.78}
.config-sticky-save .config-sticky-save__text{
    flex:1 1 260px;
    min-width:220px;
}
.email-sticky-actions{
    display:flex;
    flex:0 1 420px;
    flex-direction:column;
    align-items:stretch;
    width:min(100%, 420px);
    min-width:280px;
}
.email-rate-limit{max-width:300px}
.email-template-group{width:140px}
.email-template-subject{min-width:280px; white-space:normal; word-break:break-word}
.email-test-result{
    width:100%;
    margin-bottom:0;
    padding:.7rem .85rem;
    border:1px solid #dbe3f0;
    border-radius:10px;
    background:#f8fbff;
    text-align:left;
}
.email-test-result.text-success{
    border-color:#bbf7d0;
    background:#f0fdf4;
    color:#166534 !important;
}
.email-test-result.text-danger{
    border-color:#fecaca;
    background:#fef2f2;
    color:#b91c1c !important;
}
@media (max-width: 1199.98px){
    .email-sticky-actions{
        flex-basis:100%;
        width:100%;
        min-width:0;
    }
}
.email-sponsor-callout{
    margin-top:1rem;
    padding:1rem 1.125rem;
    border:1px solid rgba(37,99,235,.18);
    border-radius:14px;
    background:linear-gradient(180deg, rgba(239,246,255,.96) 0%, rgba(248,250,252,.96) 100%);
    box-shadow:0 10px 24px rgba(15,23,42,.06);
}
.email-sponsor-callout__eyebrow{
    margin-bottom:.35rem;
    font-size:.72rem;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#1d4ed8;
}
.email-sponsor-callout__title{
    margin-bottom:.4rem;
    font-size:1rem;
    font-weight:700;
    color:#0f172a;
}
.email-sponsor-callout__title a,
.email-sponsor-callout__text a{
    color:#1d4ed8;
    text-decoration:none;
}
.email-sponsor-callout__title a:hover,
.email-sponsor-callout__text a:hover{
    text-decoration:underline;
}
.email-sponsor-callout__text{
    margin:0;
    font-size:.92rem;
    line-height:1.55;
    color:#334155;
}
</style>

<script>
function updateEmailToolResult(resultDiv, status, message) {
    if (!resultDiv) {
        return;
    }

    resultDiv.className = 'email-test-result small mt-2 ' + (status === 'success' ? 'text-success' : 'text-danger');
    resultDiv.textContent = message;
    resultDiv.hidden = false;
}

function parseEmailToolResponse(response) {
    return response.text().then(text => {
        let payload = null;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            payload = null;
        }

        if (payload && typeof payload === 'object') {
            return payload;
        }

        const fallbackMessage = text.trim() !== '' ? text.trim() : 'The server returned an unexpected response.';
        return {
            status: 'error',
            message: fallbackMessage
        };
    });
}

function emailToolRequest(url, formData, timeoutMs) {
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    let timeoutHandle = null;

    if (controller) {
        timeoutHandle = window.setTimeout(function() {
            controller.abort();
        }, timeoutMs);
    }

    return fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
    })
    .then(parseEmailToolResponse)
    .catch(function(error) {
        if (error && error.name === 'AbortError') {
            throw new Error('The request timed out before the server responded. Check the SMTP host, port, and firewall reachability.');
        }
        throw error;
    })
    .finally(function() {
        if (timeoutHandle !== null) {
            window.clearTimeout(timeoutHandle);
        }
    });
}

function editTemplate(tpl) {
    document.getElementById('tplKey').value = tpl.template_key;
    document.getElementById('tplTitle').innerText = 'Edit: ' + tpl.template_key;
    document.getElementById('tplSubject').value = tpl.subject;
    document.getElementById('tplBody').value = tpl.body;
    new bootstrap.Modal(document.getElementById('templateModal')).show();
}

function testSmtpConnection(btn) {
    const originalHtml = btn.innerHTML;
    const resultDiv = document.getElementById('smtpConnectionResult');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Testing...';
    btn.disabled = true;
    updateEmailToolResult(resultDiv, 'success', 'Testing SMTP connection...');

    const formData = new FormData(document.getElementById('smtpForm'));

    emailToolRequest('/admin/email/test-connection', formData, 15000)
    .then(data => {
        updateEmailToolResult(resultDiv, data.status === 'success' ? 'success' : 'error', data.message || 'SMTP connection test finished.');
        if (data.status !== 'success') {
            window.adminAlert(data.message || 'SMTP connection test failed.');
        }
    })
    .catch(error => {
        const message = 'SMTP connection test failed before a valid response was returned. ' + error.message;
        updateEmailToolResult(resultDiv, 'error', message);
        window.adminAlert(message);
    })
    .finally(() => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    });
}

function sendTestEmail() {
    const target = document.getElementById('testEmailAddr').value;
    if(!target) { window.adminAlert('Enter an email address'); return; }

    const resultDiv = document.getElementById('testResult');
    updateEmailToolResult(resultDiv, 'success', 'Sending test email...');

    const formData = new FormData(document.getElementById('smtpForm'));
    formData.append('test_email_address', target);

    emailToolRequest('/admin/email/test-send', formData, 20000)
    .then(data => {
        updateEmailToolResult(resultDiv, data.status === 'success' ? 'success' : 'error', data.message || 'Test email request finished.');
        if (data.status !== 'success') {
            window.adminAlert(data.message || 'Test email failed.');
        }
    })
    .catch(e => {
        const message = 'Test email failed before a valid response was returned. ' + e.message;
        updateEmailToolResult(resultDiv, 'error', message);
        window.adminAlert(message);
    });
}

function initializeEmailToolBindings() {
    const smtpAuth = document.getElementById('smtpAuth');
    const authFields = document.getElementById('authFields');
    const smtpAuthHelp = document.getElementById('smtpAuthHelp');
    if (smtpAuth && authFields && smtpAuth.dataset.emailInit !== '1') {
        const syncAuthFields = function() {
            authFields.classList.toggle('email-auth-optional', !smtpAuth.checked);
            if (smtpAuthHelp) {
                smtpAuthHelp.innerHTML = smtpAuth.checked
                    ? 'SMTP authentication is enabled. These credentials will be used for save, connection test, and test email requests.'
                    : 'Most real SMTP providers on ports 465 or 587 expect authentication. Turn on <strong>Server Requires Authentication</strong> to use these credentials.';
            }
        };
        smtpAuth.addEventListener('change', syncAuthFields);
        smtpAuth.dataset.emailInit = '1';
        syncAuthFields();
    }

    const testConnectionBtn = document.getElementById('testSmtpConnectionBtn');
    if (testConnectionBtn && testConnectionBtn.dataset.emailInit !== '1') {
        testConnectionBtn.addEventListener('click', function(event) {
            event.preventDefault();
            testSmtpConnection(testConnectionBtn);
        });
        testConnectionBtn.dataset.emailInit = '1';
    }

    const sendTestEmailBtn = document.getElementById('sendTestEmailBtn');
    if (sendTestEmailBtn && sendTestEmailBtn.dataset.emailInit !== '1') {
        sendTestEmailBtn.addEventListener('click', function(event) {
            event.preventDefault();
            sendTestEmail();
        });
        sendTestEmailBtn.dataset.emailInit = '1';
    }

    document.querySelectorAll('[data-template]').forEach(function(button) {
        if (button.dataset.emailInit === '1') {
            return;
        }
        button.addEventListener('click', function() {
            const rawTemplate = button.getAttribute('data-template');
            if (!rawTemplate) {
                return;
            }

            try {
                editTemplate(JSON.parse(rawTemplate));
            } catch (error) {
                console.error('Failed to parse template data:', error);
            }
        });
        button.dataset.emailInit = '1';
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeEmailToolBindings, { once: true });
} else {
    initializeEmailToolBindings();
}
</script>
