<div class="config-section-shell">
    <div class="config-section-nav">
        <div class="config-section-nav__eyebrow">Ticket Sections</div>
        <div class="nav flex-column nav-pills">
            <a class="nav-link text-start active" href="#ticket-delivery"><i class="bi bi-envelope-check me-2"></i> Email Delivery</a>
            <a class="nav-link text-start" href="#ticket-triggers"><i class="bi bi-bell me-2"></i> Trigger Rules</a>
            <a class="nav-link text-start" href="#ticket-reminders"><i class="bi bi-hourglass-split me-2"></i> Reminders</a>
            <a class="nav-link text-start" href="#ticket-rate-limits"><i class="bi bi-speedometer2 me-2"></i> Rate Limits</a>
        </div>
    </div>
    <div class="config-section-content">
        <div class="config-section-intro">
            <div>
                <h5 class="config-section-intro__title">Tickets</h5>
                <p class="config-section-intro__text">Control where ticket email goes, which lifecycle events notify staff or users, when waiting-on-user reminders should fire, and how aggressively each intake path is rate limited.</p>
            </div>
            <ul class="config-summary-chips">
                <li class="config-summary-chip <?= (($ticketEmailsEnabled ?? '1') === '1') ? 'config-summary-chip--success' : 'config-summary-chip--warning' ?>">Ticket email: <?= (($ticketEmailsEnabled ?? '1') === '1') ? 'Enabled' : 'Disabled' ?></li>
                <li class="config-summary-chip config-summary-chip--info">Support inbox: <?= !empty($ticketSupportInboxEmail) ? htmlspecialchars($ticketSupportInboxEmail) : 'Fallback to admin email' ?></li>
                <li class="config-summary-chip <?= (($ticketWaitingUserRemindersEnabled ?? '1') === '1') ? 'config-summary-chip--info' : 'config-summary-chip--warning' ?>">Reminder: <?= (($ticketWaitingUserRemindersEnabled ?? '1') === '1') ? ((int)($ticketWaitingUserReminderDays ?? 3) . ' day(s)') : 'Off' ?></li>
            </ul>
        </div>
        <details class="config-help-panel">
            <summary>How this works</summary>
            <div class="config-help-panel__body">
                <p>Support tickets now have a dedicated logged-in user page and a shared admin queue. These settings decide which lifecycle events generate email, which inbox receives staff notifications, and how aggressively public or account-side ticket actions are rate limited.</p>
                <p class="mb-0">DMCA senders may represent automated rights-enforcement teams, so the defaults below are deliberately more permissive than the general contact or abuse flows.</p>
            </div>
        </details>

        <form method="POST" action="/admin/configuration/save">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="section" value="tickets">

            <div id="ticket-delivery"></div>
            <?php renderAdminCardStart('Email Delivery', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="ticket_emails_enabled" id="ticketEmailsEnabled" value="1" <?= ($ticketEmailsEnabled ?? '1') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold" for="ticketEmailsEnabled">Enable Ticket Email Notices</label>
                </div>
                <small class="config-form-note d-block mt-2">Turn this off if you want the ticket system to rely only on in-app notifications and the admin queue.</small>
            </div>
            <div class="mb-0">
                <label class="form-label fw-bold">Support Inbox Email</label>
                <input type="email" class="form-control" name="ticket_support_inbox_email" value="<?= htmlspecialchars($ticketSupportInboxEmail ?? '') ?>" placeholder="support@example.com">
                <small class="config-form-note">Leave blank to fall back to the General admin notification email, then the From Address if needed.</small>
            </div>
            <?php renderAdminCardEnd(); ?>

            <div id="ticket-triggers"></div>
            <?php renderAdminCardStart('Email Trigger Rules', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
            <div class="row g-4">
                <div class="col-lg-6">
                    <h6 class="fw-bold small text-uppercase text-muted mb-3">Admin Inbox Events</h6>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_admin_on_open" id="ticketNotifyAdminOnOpen" value="1" <?= ($ticketNotifyAdminOnOpen ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyAdminOnOpen">Notify on new support ticket</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_admin_on_user_reply" id="ticketNotifyAdminOnUserReply" value="1" <?= ($ticketNotifyAdminOnUserReply ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyAdminOnUserReply">Notify when a user replies</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_admin_on_contact" id="ticketNotifyAdminOnContact" value="1" <?= ($ticketNotifyAdminOnContact ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyAdminOnContact">Notify on contact form submissions</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_admin_on_abuse" id="ticketNotifyAdminOnAbuse" value="1" <?= ($ticketNotifyAdminOnAbuse ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyAdminOnAbuse">Notify on abuse reports</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_admin_on_dmca" id="ticketNotifyAdminOnDmca" value="1" <?= ($ticketNotifyAdminOnDmca ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyAdminOnDmca">Notify on DMCA submissions</label>
                    </div>
                </div>

                <div class="col-lg-6">
                    <h6 class="fw-bold small text-uppercase text-muted mb-3">User-Facing Events</h6>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_user_on_open" id="ticketNotifyUserOnOpen" value="1" <?= ($ticketNotifyUserOnOpen ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyUserOnOpen">Send ticket-open confirmation</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_user_on_staff_reply" id="ticketNotifyUserOnStaffReply" value="1" <?= ($ticketNotifyUserOnStaffReply ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyUserOnStaffReply">Notify when staff replies</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="ticket_notify_user_on_close" id="ticketNotifyUserOnClose" value="1" <?= ($ticketNotifyUserOnClose ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="ticketNotifyUserOnClose">Notify when a ticket is closed</label>
                    </div>
                </div>
            </div>
            <?php renderAdminCardEnd(); ?>

            <div id="ticket-reminders"></div>
            <?php renderAdminCardStart('Waiting on User Reminders', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="ticket_waiting_user_reminders_enabled" id="ticketWaitingUserRemindersEnabled" value="1" <?= ($ticketWaitingUserRemindersEnabled ?? '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="ticketWaitingUserRemindersEnabled">Enable Waiting-on-User Reminder Emails</label>
            </div>
            <div class="mb-0">
                <label class="form-label fw-bold">Reminder Delay (Days)</label>
                <input type="number" class="form-control" name="ticket_waiting_user_reminder_days" value="<?= htmlspecialchars($ticketWaitingUserReminderDays ?? '3') ?>" min="1" max="30">
                <small class="config-form-note">Applies to support and contact tickets by default. Abuse and DMCA reminders stay off to avoid noisy legal loops.</small>
            </div>
            <?php renderAdminCardEnd(); ?>

            <div id="ticket-rate-limits"></div>
            <?php renderAdminCardStart('Rate Limits', ['cardClass' => 'border-0 shadow-sm mb-4 config-section-card']); ?>
            <div class="row g-4">
                <div class="col-xl-6">
                    <h6 class="fw-bold small text-uppercase text-muted mb-3">Support Ticket Actions</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">User Creates / Window</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_support_create_user" value="<?= htmlspecialchars($ticketRateLimitSupportCreateUser ?? '5') ?>" min="1" max="100">
                            <small class="config-form-note">Default 5 ticket opens.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Create Window (Minutes)</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_support_create_window" value="<?= htmlspecialchars($ticketRateLimitSupportCreateWindow ?? '60') ?>" min="1" max="1440">
                            <small class="config-form-note">Default 60 minutes.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">IP Creates / Window</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_support_create_ip" value="<?= htmlspecialchars($ticketRateLimitSupportCreateIp ?? '10') ?>" min="1" max="250">
                            <small class="config-form-note">Default 10 ticket opens.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">User Replies / Window</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_support_reply_user" value="<?= htmlspecialchars($ticketRateLimitSupportReplyUser ?? '20') ?>" min="1" max="500">
                            <small class="config-form-note">Default 20 replies.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Reply Window (Minutes)</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_support_reply_window" value="<?= htmlspecialchars($ticketRateLimitSupportReplyWindow ?? '60') ?>" min="1" max="1440">
                            <small class="config-form-note">Default 60 minutes.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">IP Replies / Window</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_support_reply_ip" value="<?= htmlspecialchars($ticketRateLimitSupportReplyIp ?? '40') ?>" min="1" max="1000">
                            <small class="config-form-note">Default 40 replies.</small>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <h6 class="fw-bold small text-uppercase text-muted mb-3">Public Intake Limits</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Contact / IP</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_contact_ip" value="<?= htmlspecialchars($ticketRateLimitContactIp ?? '6') ?>" min="1" max="250">
                            <small class="config-form-note">Default 6 contact messages.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Contact Window (Minutes)</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_contact_window" value="<?= htmlspecialchars($ticketRateLimitContactWindow ?? '60') ?>" min="1" max="1440">
                            <small class="config-form-note">Default 60 minutes.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Abuse / IP</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_abuse_ip" value="<?= htmlspecialchars($ticketRateLimitAbuseIp ?? '12') ?>" min="1" max="500">
                            <small class="config-form-note">Default 12 abuse reports.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Abuse Window (Minutes)</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_abuse_window" value="<?= htmlspecialchars($ticketRateLimitAbuseWindow ?? '60') ?>" min="1" max="1440">
                            <small class="config-form-note">Default 60 minutes.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">DMCA / IP</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_dmca_ip" value="<?= htmlspecialchars($ticketRateLimitDmcaIp ?? '30') ?>" min="1" max="2000">
                            <small class="config-form-note">Default 30 DMCA notices.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">DMCA Window (Minutes)</label>
                            <input type="number" class="form-control" name="ticket_rate_limit_dmca_window" value="<?= htmlspecialchars($ticketRateLimitDmcaWindow ?? '60') ?>" min="1" max="1440">
                            <small class="config-form-note">Default 60 minutes to stay friendlier to legitimate reporting companies.</small>
                        </div>
                    </div>
                </div>
            </div>
            <?php renderAdminCardEnd(); ?>

            <div class="config-sticky-save">
                <p class="config-sticky-save__text">These controls change how quickly ticket traffic reaches staff and how aggressively the public-facing forms can be used, so conservative defaults are usually the safer call.</p>
                <button type="submit" class="btn btn-primary px-5">
                    <i class="bi bi-save me-2"></i> Save Ticket Settings
                </button>
            </div>
        </form>
    </div>
</div>
