# Changelog

## v1.0.1

- Fixed an upgrade recovery lockout where `packages` schema drift could fatal public/package pages before staff could log in and run Database Health schema repair.
- Added cURL fallbacks, GitHub API-compatible archive headers, source-ZIP metadata handling, and admin diagnostics for release checks and update downloads when PHP stream URL access is unavailable or unreliable.

## v1.0.0

### Staff Permissions and Moderation Foundation
- Added a staff-capability model to replace the old all-or-nothing admin role, introduced a `moderator` role for file moderation plus abuse/DMCA handling, and self-healed older installs by expanding `users.role` and creating `staff_permissions`.
- Added protected Super Admin handling in User Management: the first installed admin becomes the setup-time super admin, older installs backfill that protection to the earliest admin if none is marked yet, normal admins do not get super-admin edit power by default, and `staff.edit_super_admin` must be granted before another staff account can edit, demote, ban, delete, or override 2FA on that protected account.
- Expanded activity and investigation tooling with richer staff activity logging, actor-role tracking, a new `/admin/staff-activity` view, dedicated `/admin/investigations/uploader/{id}` and `/admin/investigations/file/{id}` pages, direct investigation links from fraud review and file moderation, and summaries for reward activity, referrers, countries, top files, and recent staff actions.
- Tightened staff-activity and financial-review audit integrity by correcting user target links in `/admin/staff-activity`, cleaning up timeline rendering, logging withdrawal note-only edits as their own audited event, and keeping withdrawal review activity behind the same payout-management capability boundary instead of letting generic activity viewers see payout-note history.
- Hardened admin/staff access boundaries across Configuration, Security, Plugins, Site Content, Search, Files, Users, current-download monitoring, and related infrastructure pages so restricted staff can only reach assigned tools instead of merely having links hidden.
- Extended the notifications and remote-upload groundwork by bringing those schemas into the master/self-healing path, adding notification event-key dedupe and richer metadata, tracking remote-upload attempts and job timing more cleanly, sending default queued/completed/failed remote-upload notifications with a 30-day-friendly history model, and routing support, abuse, and DMCA staff notifications by assigned capabilities instead of assuming every queue belongs to every admin.

### Security Hardening
- Hardened install, upgrade, and recovery safety by making the installer run deep schema sync against its already-open setup PDO connection, rejecting hidden-config paths that only look safe until `..` segments are resolved, narrowing the lingering post-install self-test page to Super Admin, Configuration, or Support staff, and having installer diagnostics flag unsafe hidden-config locations before setup begins.
- Improved bootstrap and post-move recovery by giving the installer the same session-storage fallback as the main app, loading `config/database.php` through a shared hidden-config pointer validator in the web app, cron runner, and post-install checker, letting the installer recover when a stale pointer exists but the real hidden config file is gone, and replacing another hard-fail config/database bootstrap path so broken hidden-config credentials now produce controlled recovery messaging instead of a plain `Internal Server Error`.
- Improved operator safety around upgrades and cleanup by reporting the one-click updater as unavailable when it is not shipped instead of blaming GitHub repo setup, verifying that `install.php`, `post_install_check.php`, or the setup schema folder are actually gone before claiming cleanup success, preserving nested defaults like rate-limit and allowed-host settings when loading installer-generated secure config, and making scheduled `db_health` use normal non-invasive schema sync instead of silent Deep Repair.
- Added admin-configurable idle logout controls for admin, moderator, and normal-user sessions using common preset windows, plus an optional Remember Me feature that can be enabled for regular user accounts only, restores a fresh session from a rotating persistent token, and stays disabled for admin and moderator accounts even when they use the shared public login page.
- Hardened token, device, and rate-limit handling by hashing new email-verification, password-reset, and email-change bearer tokens at rest while keeping legacy lookup compatibility, moving login-device IP history into the encrypted-at-rest schema path with encrypted-safe helper-table sizing, normalizing credential-specific login rate-limit keys across casing/spacing variants, and adding dedicated brute-force protection plus adjustable Security controls for two-factor verification and recovery-code entry.
- Continued a broad integrity and abuse-resistance pass across staff delegation, internal review boundaries, moderation queues, API/account ownership, account-side upload and file tools, dashboard/file-manager ownership, download-metrics integrity, manual subscription actions, moderation-versus-financial spillover, built-in plan-policy boundaries, refund-sensitive affiliate attribution, diagnostics/log privacy, specialized-review destructive file actions, remote-completion proof quality, and high-risk rewards, withdrawals, payments, downloads, uploads, remote uploads, API tokens, sessions, CAPTCHA enforcement, proxy trust, and package policy enforcement so configured limits fail closed more often and behave more predictably under concurrency and retry pressure.
- Hardened more admin and billing edge cases by making package deletion fail closed when bonus-offer dependency checks cannot run safely on older or drifted installs, keeping hidden ticket assignment and reply visibility tighter, and improving cron/task diagnostics around failed stale-payment cleanup runs.

### Rewards Fraud Review
- Reworked `/admin/rewards-fraud` into a queue-first moderation workspace for busy sites with pagination, queue filters, bulk review actions, expandable row context, clearer separation between live review work and tuning settings, safer decrypted username/file-name search inside a bounded work slice without adding new searchable plaintext columns, and triage-vs-full view modes with quick-filter chips, sticky bulk actions, reusable note presets, keyboard shortcuts, and server-side `recommended` review actions.
- Expanded case review and high-volume controls with richer decision context for uploader/file patterns over the last 30 days, linked session proof and network details, repeat signals for recent visitor/browser clusters, downloader-account context, recent reward activity when the live queue is empty, the last 5 unique referring pages recently leading to rewarded sessions for the file, queue-level safe-vs-fraud summaries, cluster-first review panels for uploaders/files/referrer funnels/network pockets, uploader trust tiers, trust-aware risk scoring, and automatic decision lanes that can auto-clear routine low-risk traffic or auto-reverse blocked/hard-fraud traffic; older installs also self-heal the new referrer and trust-control storage on upgrade.

### Promotions and Bonus Offers
- Added a Bonus Offers system inside Monetization with a dedicated tab, self-healing offer/award tables, editable milestone, limited-time, and referral-style campaigns, custom thresholds and units, timezone-aware schedules, optional weekday windows, audience targeting, public visibility controls, and per-offer notification/email settings.
- Added admin review and award handling so bonus offers can default to pending staff approval, optionally auto-credit for safer campaigns, and write approved or auto-credited awards into the existing rewards ledger as `earnings.type = bonus`, letting bonus money flow into the same withdrawable balance users already cash out from.
- Preserved bonus-offer targeting integrity during destructive admin actions by blocking package deletion when selected-package bonus-offer dependency checks cannot be completed safely instead of ignoring that risk on damaged installs.
- Added a user-facing `/promotions` page plus conditional Promotions links in the public top navigation and logged-in account sidebar whenever relevant offers are active, expanded `/rewards` with bonus summary cards, active-promotion visibility, and bonus history, and added editable email-template support for bonus offer start, earned-pending-review, and credited events.

### Coupons and Premium Checkout
- Added a full premium coupon system with dedicated admin create/edit pages, clearer operator guidance, page-guide and `/admin/docs` coverage, transactional reservation/redemption tracking, install/upgrade-safe schema support for `coupons`, `coupon_redemptions`, and coupon-aware transaction/subscription history, plus premium checkout support for fixed-dollar and percent discounts, optional percent caps, package targeting, start/end windows, new-account and renewal eligibility rules, one-cycle / first-X-cycles / forever duration handling, total and per-user redemption limits, and safe zero-dollar checkout completion without forcing Stripe or PayPal.

### Storage and Upload Reliability
- Improved high-volume multipart upload performance by throttling read-only session lease writes, removing storage-provider lookups from successful part reports, and moving authoritative part verification to the pre-completion batch fence for local and S3-compatible storage.
- Optimized multipart dedup and completion cleanup by reducing unnecessary legacy checksum fallback queries, keeping canonical content hashes intact for future reuse, and adding stronger regression coverage for lease refresh, provider metadata mismatches, and completed-upload reuse paths.
- Widened `upload_sessions.multipart_upload_id` to `VARCHAR(512)` in the base schema, runtime schema builder, and multipart-upload upgrade self-heal path so longer provider-generated multipart upload IDs from multipart-capable storage backends do not get truncated on fresh installs or upgrades, and fixed the file manager's interrupted-upload banner so active multi-file uploads are no longer misclassified as interrupted while another file is still finalizing.
- Added drag-and-drop folder uploads in the file manager with recursive folder discovery, automatic nested destination-folder creation, support for empty folders, resumable queue/session preparation for queued folder contents, and skip-only handling for unsupported files so one blocked item no longer cancels the rest of the dropped folder.
- Added an optional `Replace File In Place` upload feature, disabled by default and controlled from `Admin > Configuration > Uploads`, so signed-in users can upload a new binary behind an existing file record while keeping the same public file URL, handling deduplication safely, and adjusting storage usage by the real size delta instead of treating it like a brand-new file.
- Reworked deduplication into a real on/off storage policy across classic uploads, remote uploads, multipart/API uploads, replace-file, and save-to-account, with safer cross-file-server reuse checks, server-verified chunked-upload hashing when dedup is enabled, per-hash concurrency locks to stop duplicate-object races, fresh-install and upgrade schema fixes for `stored_files` hash indexes, and cleanup of older shortcut paths that could misreport or weaken dedup behavior.
- Hardened multipart dedup after replace-in-place uploads by persisting the finalized `stored_file_id` in completed upload-session metadata and using that immutable pointer for checksum-based reuse and reconciliation, which prevents old completed-session checksums from resolving through a file row's later replacement target when content changed but the size stayed the same.
- Tightened multipart quota accounting so terminal completion failures now release active quota reservations immediately instead of leaving user quota and per-server reserved capacity stranded until expiry cleanup.
- Removed the classic upload fallback that rewrote an existing shared `stored_files` row in place after a hash-only collision/ghost lookup miss; uploads now create a fresh stored-file row instead of mutating a potentially shared canonical record.
- Aligned same-account copy behavior with the rest of dedup-aware quota accounting so file and folder copies now enforce package storage limits and update `storage_used` consistently instead of being corrected only later by reconciliation.
- Hardened dedup-adjacent maintenance flows so recursive folder copy cleans up partial destination branches on failure, and storage migration no longer frees source-node capacity until the source payload is actually gone.

## v0.2.1

### Upgrade and Migration Fixes
- Fixed the encryption migration path for `admin_activity_log.ip_address` by expanding the column to encrypted-safe length before rewriting legacy plaintext IP data, which prevents `SQLSTATE[22001]` / `1406 Data too long for column 'ip_address'` errors during Security > Migration on upgraded installs.
- Updated the admin activity log self-healing table definition to match the encrypted schema so fresh table creation and repaired installs stay aligned with the migration-safe column size.
- Expanded the same upgrade-path protection to encrypted API token usage IPs and the download rate-limit helper table so older installs do not keep narrow legacy IP column sizes after upgrading.

## v0.2.0

### Site Content Editor
- Added an admin-managed Site Content system for editing public copy without touching theme files, with markdown content blocks, safe link validation, live saves, reset-to-default behavior, preview links, JSON import/export, revision history with restore, locale variants, and automatic pruning to the newest 10 revisions per page and locale.
- Wired Site Content into Homepage, FAQ, Contact, DMCA, and Footer using shared rendering helpers with built-in defaults, locale-aware page links, locked footer attribution, and short-lived preview tokens for logged-in admin review on guest-facing routes like the homepage.
- Expanded the customer-facing copy cleanup around that system by moving public-facing Affiliate and API page copy into Site Content, refreshing Homepage and FAQ defaults to sound more like a real hosting service, and softening related public account, plans, rewards, and checkout language that previously read too much like installer or operator-facing text.
- Reworked `/faq` into a clearer help-center style page with grouped topic sections, jump chips, live search, tighter support handoff, additional practical questions, and Site Content-managed FAQ categories so the public help flow is easier to scan and maintain.
- Reworked `/affiliate` into a clearer Creator Rewards page with stronger sectioning, cleaner model comparison, separate reward-guidance cards, better guest and member call-to-action copy, and a more polished rates-and-payout presentation.
- Added theme compatibility checks and editor polish including custom-theme override warnings, revision detail summaries, grouped page actions, summary chips, section anchors, contextual markdown help, copyable token chips, denser revision history, locale-aware helper notes, unsaved-change cues, revision filters, responsive action layout, clearer "where this appears" guidance, plus docs and an implementation spec for theme authors.

### Admin Navigation
- Reworked the admin sidebar into clearer grouped sections for Overview, Moderation, Users & Revenue, Content & Files, and Infrastructure, with collapsible group headers, stronger active-state styling, quieter reference/footer links, and preserved badges for requests, withdrawals, Config Hub attention, and available updates.
- Added a new `/admin/scaling` Scaling Guide page for operators, with install-specific recommendations around object storage, delivery methods, reward-proof settings, package download pressure, and other high-concurrency tradeoffs, plus direct links back to the relevant admin settings areas.

### Admin Documentation
- Rebuilt /admin/docs into a task-oriented operator handbook with start-here cards, common task guides, browse-by-task grouping, searchable section cards, and cleaner long-form documentation organized around Getting Started, Daily Operations, Users & Billing, Content & Moderation, Storage & Delivery, Security & Infrastructure, and Troubleshooting & Reference.
- Refreshed the key page guides to match the current product surface, including Tickets, Packages, Site Content, Config Hub, Email, Security, Storage Nodes, Downloads & Delivery, Support Center, Resources, and the filtered Contact/Abuse/DMCA views, while adding clearer "when to use this page," related-page references, and more workflow-oriented copy.

### Package Management
- Rebuilt the package index into a clearer comparison workspace with summary cards, stronger at-a-glance plan columns, assigned-user counts, and one-click package cloning so new tiers can be created from a live plan without re-entering every field from scratch.
- Reworked package edit pages into grouped sections for Overview, Storage & Uploads, Downloads & Delivery, Rewards & Payout, Ads & Restrictions, and extension hooks, with left-side section navigation, top summary context, customer-preview notes, live-impact warnings for plans with assigned users, a sticky save bar, broader plan controls like accepted file types, PPD rates, PPS enablement and commission percent, adblock blocking, and VPN/proxy blocking, plus a fix so unchecked toggles now save as off instead of keeping prior values.
- Added a real package-creation flow in `/admin/package/create` so staff can create new free or paid plans from scratch instead of relying on clone-only workarounds, with protected singleton system plans left untouched, unique package-name checks, and the same package editor/save behavior used by existing plan management.

### Tickets
- Added a full shared ticket system with a logged-in user Tickets page, encrypted ticket/message/event storage, non-sequential public ticket IDs, reopen-on-user-reply behavior, admin/staff replies, internal notes, user/staff notifications, and core email events for ticket opened, replied, and closed actions.
- Added Config Hub > Tickets plus editable ticket email templates in the Email tab so operators can manage the support inbox address, master and per-trigger email toggles, waiting-on-user reminder settings, and configurable rate limits for support tickets, contact, abuse, and DMCA submissions.
- Reworked /admin/requests into a true ticket queue with summary stats, search, status/priority/stale filters, clearer row metadata, improved thread and workflow panels, preserved filter/search context after actions, correct latest-reply metadata, intake unification for new Contact/Abuse/DMCA submissions as encrypted ticket records, support for ticket-backed and legacy queue items side by side, abuse delete-file and DMCA file-removal actions, separated ticket-backed activity keys, verified ticket public IDs for ticket-backed admin actions, fail-closed behavior when those IDs are missing or invalid, and persisted DMCA processing in the visible activity trail.
- Expanded the ticket queue with staff assignment plus admin-only hidden visibility, then tightened the hidden-ticket model so reassignment cannot be used to widen access and later user replies no longer leak hidden-ticket notifications to unrelated staff.

### Security and Email Config
- Added a VPN/proxy enforcement scope in Config Hub > Security so operators can choose whether blocking applies to **all public pages** or **only download pages**, while keeping the existing download-page and download-action checks active where they matter most.
- Fixed the Email tab layout in Config Hub by separating the SMTP workspace, test tools column, and template-edit modal into the correct grid structure so the admin page no longer collapses awkwardly at normal widths.

### System Status
- Reworked /admin/status into a triage-first operations page with top-line summary cards, clear Critical / Warning / Healthy signals, a dedicated Action Center, regrouped sections for App Health, Updates, Upload Pipeline, Storage & Reservations, Download & Delivery, Support Diagnostics, and Logs, calmer collapsible low-priority detail, plainer operator notes, safer next-step links, and an updated Status page guide.

### Logged-In Workspace
- Reworked the logged-in file manager homepage into a clearer workspace with a stronger header, top-line file and folder context, a primary Upload Files action, cleaner secondary actions, calmer separation between upload, filtering, and file-list sections, a denser filter/search panel, Clear filters and Reset workspace, a file-list header that owns the view toggle, and a compact-by-default upload drop area that can expand on demand or automatically when files are dragged in.
- Tightened the account sidebar by removing redundant summary chrome, regrouping navigation into Files, Account, and Earnings, and adding useful count badges for Notifications, Tickets, and Trash without changing the underlying routes.
- Split Trash more clearly from permanent deletion history by keeping recoverable items in the Trash view above, moving deleted-file history into its own paginated list, and adding a separate admin-removal filter so DMCA, abuse, and moderation removals do not blur together with normal user deletions.

### Rewards Dashboard
- Reworked `/rewards` into a clearer payout-first earnings dashboard with stronger top summary cards, a dedicated payout-status panel, better separation between payout actions and analytics, clearer earnings-state breakdowns, cleaner performance and history sections, and more obvious guidance around what qualified, what is still under review, and when balance is ready for payout.
- Expanded the rewards experience with a clearer "what happens next" timeline, payout-readiness checklist, trend comparisons, active-promotion progress cards, friendlier rejection explanations, and more actionable guidance around held earnings, payout setup, and which files or traffic patterns deserve attention next.
- Added an admin-controlled `delete file earnings too` moderation option so staff can remove file-linked reward rows when deleting a file, cancel held or review-state rewards cleanly, reverse already-cleared file rewards with matching negative activity on the user ledger, keep referral child earnings in sync, and fail closed when older rolled-up reward history cannot be unwound safely by file.

### Account Settings
- Reworked /settings into a clearer account hub with grouped sections for Profile & Preferences, Security, Rewards & Payout, and API Tokens, plus a stronger summary header and cleaner section-level guidance.
- Added a verified email-change flow in account settings so users can update their email address, receive a confirmation link at the new address, and only switch the real login email after that link is opened, plus the editable confirm_email_change template in the admin Email Templates page.
- Cleaned up `/login` with clearer sign-in copy, better email-or-username field guidance, a more obvious forgot-password path, registration-aware footer messaging, and a calmer verification note when email confirmation is required.

## v0.1.5

### Deployment Hardening
- Hardened the Nginx example so normal PHP execution is limited to `index.php`, arbitrary `.php` paths return `404`, `install.php` is blocked after `config/database.php` exists, and `post_install_check.php` is only reachable after installation.
- Hardened multipart upload completion and plugin operations: assembled objects must exist, match expected size, keep an allowed extension, avoid executable server-side extensions, pass MIME normalization, and match the supplied SHA-256 checksum before final file records are created, while a new admin plugin upload policy switch can disable ZIP uploads outside planned install/update windows.
- Hardened the admin support bundle and dashboard paths: `/admin/support` now shows a lightweight summary preview, generates the full sanitized bundle only on explicit download/email actions, keeps full JSON out of the email body, sanitizes database exception text before export, and `/admin` dashboard loading now trusts cached `system_stats`, creates the fallback cache directory before lock acquisition, and removes an unused duplicate host-metrics fetch.
- Expanded the Security migration view so Enterprise Data Encryption shows which table and column references are still pending, and extended at-rest encryption coverage to additional safe metadata fields including payment transaction IPs, API token last-used IPs, and admin activity log details/IPs so new writes avoid leaving those values in plaintext while the migration can sweep older rows.

### Rewards and Referral Integrity
- Reworked referral-child earnings to use an explicit `parent_earning_id` link instead of human-readable descriptions so referral commission rows stay attached to the original PPD or PPS earning even if labels change, and child referral earnings now track their parent more reliably through held, cleared, cancelled, reversed, and paid states.
- Added runtime and fresh-install schema support for `parent_earning_id`, referral metadata, and the `pps_reward` earning type, then backfilled existing referral rows from stored metadata or legacy descriptions where possible so older referral earnings are not stranded when the new parent-link column is introduced.
- Tightened the referral dashboard metric so `Earning Referrals` counts only referred users with cleared or paid PPD/PPS earnings, not held earnings that may still be cancelled or reversed.

### File Manager Consistency
- Made the file manager list view the default and rebuilt it as a denser table view with columns for name, size, upload date, downloads, public visibility, and row actions.
- Expanded uploader tooling across bulk links, mass rename, and copy flows: selected files and folders can now generate plain, download-page, HTML, BBCode, and thumbnail embed links with grouped output, copy-all, and `.txt` export; preview-before-apply mass rename supports find/replace, prefix/suffix, remove-text, separator conversion, sequential numbering, and admin regex-lite; and copy workflows now cover duplicating files, copying selected items into another folder, recursively cloning folder structure, and creating alternate filenames that point at the same stored object instead of re-uploading data.
- Improved selection and trash behavior: the toolbar is now context-aware in Trash, clicking blank space clears selection, `Ctrl`/`Cmd` and `Shift` multi-select work in a desktop-style way on file and folder cards, Deleted File History now lives in Trash as cards with permanent deletion date, actor, and reason, admin deletes from `/admin/files` require and record a deletion reason, and recursive hard folder deletion now removes the full hierarchy after deleting its files instead of only the selected root folder row.
- Refined shared shell presentation by increasing the public site-name wordmark size by 15%, unifying the logged-in account sidebar across the main file manager, settings, rewards, notifications, affiliate, and 2FA setup pages while keeping the affiliate page separate for guests, and unifying the public auth/support form shell across login, register, forgot password, reset password, contact, DMCA, and 2FA verification pages.

### Blocked Page and Ad Layout
- Fixed the VPN/proxy enforcement blocked page so it renders through the normal website template with working CSP nonce injection instead of falling back to raw unstyled HTML, restored blocked-page styling, and updated the copy to explicitly mention blocking VPNs, proxies, Tor exit nodes, and similar relay services.
- Re-unified the download page family so the main file page and download-style state pages pull shared ad/layout data through the same download page service and shared state partial, including overlay ad support, which reduces drift between the normal download page, File Not Found, Private File, VPN blocked, and related pages while letting left, right, top, bottom, and overlay download ad placements render consistently when the viewer package is configured to show ads.
- Kept browser-based download starts on the file page by switching the normal JavaScript path to request the signed download URL in the background and launch the actual file download without leaving the visible `/file/{id}` page.

### Support and DMCA Workflow
- Expanded the unified admin Requests inbox with a DMCA file-removal card that resolves submitted `/file/{short_id}` targets into local files, supports processing selected matches or all matched files, and records the uploader-facing deletion reason `Removed due to DMCA report.` when those files are marked for removal.
- Tightened DMCA target handling so request URLs must belong to the local site host and file matching uses an exact `short_id` lookup instead of broader mixed numeric matching, then added no-refresh DMCA processing in the admin request detail view with inline success/error feedback, live file-row state updates, and immediate Activity-log updates without closing or reloading the open request panel.

### Admin Shell Consistency
- Added shared admin shell helpers for page headers and card framing, then moved the main admin workflow pages onto that layer, including Requests, Support, Contacts, Abuse Reports, DMCA, Rewards Fraud, Files, Current Downloads, Withdrawals, Subscriptions, Search Results, Packages, Users, Plugins, Resources, and the Configuration Hub.
- Moved the main admin edit and operations pages onto the same shared shell pattern, including package edit, user edit, storage-server add/edit/migrate, and the standalone admin Help & Docs index, while keeping the bespoke Dashboard, Admin Docs, and System Status layouts routed through the shared admin page-header helper so their specialized widgets and diagnostics do not drift at the shell level.
- Reworked Config Hub navigation into grouped clusters for Site, Security, Storage & Delivery, Revenue, and System while keeping existing `?tab=` routes and save flows intact, then rolled out a more structured workspace with shared section-shell styling, left-side in-tab subnavigation for heavier pages, collapsible "How this works" panels, standardized card spacing, smaller Cron status cards, sticky save bars, cleaner callouts, softer utility boxes, tighter anchor-nav behavior, per-tab summary chips, and clearer danger/utility zones across tabs like Security, Cron, Downloads, Uploads, Link Checker, Storage, SEO, Monetization, Email, General, and Storage Servers.
- Refined the General Config Hub experience for media setup by splitting the old video area into dedicated `Thumbnail Generation` and `Video Transcoding` sections, surfacing GD readiness for image thumbnails, clarifying FFmpeg-backed video thumbnail requirements and shared-hosting limitations, and reserving transcoding as a clear coming-soon area instead of implying it already works.
- Synced Config Hub security notices into the left admin sidebar badge and the main dashboard `Attention Needed` strip, expanded that strip with stale cron heartbeat, Cloudflare IP sync, lingering setup files, SMTP delivery failures, payout-affecting storage delivery warnings, aged pending withdrawals, and reward-fraud backlog, and reworked the Email tab so SMTP tools stay in the narrower working column while the System Email Templates table breaks out into its own full-width section to prevent template subjects and actions from being cut off at normal admin widths.
- Hardened the Cron Jobs tab so loading the page no longer risks pruning active plugin-backed cron rows, manual `Trigger All Tasks Now` runs now register and execute the full built-in task set instead of an incomplete subset, and saved task frequencies now enforce whole-minute values within a safe 1-minute to 7-day range with clearer in-form guidance.

### Uploader Earnings and API
- Expanded the uploader rewards dashboard with counted downloads, rejected downloads, rejection explanations, country/network breakdowns, earnings by file, conversion rate, pending/held/cleared/cancelled amount cards, and CSV export.
- Added uploader-focused API endpoints for listing files and folders, creating folders, renaming, moving, copying, deleting, remote upload create/status/cancel, bulk link generation, earnings stats, payout info, and `/api/v1/openapi.json`, then updated the public API reference with the new scopes (`files.write`, `stats.read`, `remote.upload`) plus curl, PHP, Python, and JavaScript examples.
- Cleaned up account-side uploader tooling by revealing newly created API tokens clearly with a one-time copy action, showing stored-token rows only as shortened previews, adding revocation confirmation, exposing the full supported scope set (`files.upload`, `files.read`, `files.write`, `stats.read`, and `remote.upload`) in account settings, normalizing remote upload cancellation to store `canceled` consistently on fresh and upgraded installs, preloading the rewards payout modal with each uploader's saved default payment method and payment details, tightening the cancel-button styling, and hardening uploader API file and folder operations so target folders belong to the authenticated account and automation endpoints only mutate active, non-trashed items.

### Payments and Billing
- Added a logged-in Payments history page that shows transaction history, subscription history, current package billing status, and purchase/refund totals using the same shared account shell as the rest of the user dashboard, and added a background billing cleanup task that marks package-purchase attempts still stuck in `pending` after 24 hours as `failed` so abandoned checkout attempts do not linger forever in account payment history.

### Public Link Checker
- Added a public footer-linked Link Checker page between `DMCA` and the powered-by credit so visitors and uploaders can batch-check local file links without digging through the account area.
- The checker supports local file links plus signed-in account folder links, deduplicates pasted URLs, shows clean `Available`, `Not Available`, and `Invalid` results, and includes summary chips plus bulk tools to copy or export available, not-available, or invalid link sets without manually cleaning pasted batches first.
- Added optional `Copy To Account` actions from Link Checker results, including per-link selection, select-all-eligible, and copy-all-available behavior for signed-in users, plus a new `/admin/configuration` `Link Checker` tab so operators can enable or disable the public checker, change the maximum links processed per batch, set a per-IP links-per-second limit, and control whether copy-to-account is available.

## v0.1.4

### Storage Server Reliability
- Fixed admin file-server delivery tests and related storage helper paths so file-server configs load correctly whether the `config` payload is still an encrypted JSON string from the database or has already been decoded into an array by the admin UI. This prevents `json_decode()` type errors during `/admin/file-server/test-delivery/{id}` and keeps nearby download and rewards storage checks using the same tolerant config handling.
- Fixed local-storage uploads in the modern browser uploader by adding app-routed multipart part handling for local file servers, so Local Storage installs no longer fail with the old multipart-support error just because global chunked uploads are enabled, and Apache/X-SendFile delivery mode is no longer a red herring for that path.

### Rewards and Affiliate Cleanup
- Removed legacy numeric internal referral attribution from the public `?ref=` flow so Fyuhls now only accepts non-guessable public user referral IDs for account-side affiliate tracking.
- Added a configurable affiliate commission hold window with a default of 5 days so referred package-sale commission can stay held long enough to absorb normal refund and chargeback risk before becoming withdrawable.
- Fixed payment status handling so completed transactions can still transition into `refunded` or `denied`, and those later gateway states now cancel or reverse related affiliate commission instead of leaving it cleared forever.
- Corrected affiliate and rewards messaging so PPD users are no longer told that sharing their referral link will earn package-sale commission unless they switch to a PPS-capable model first.
- Replaced the inflated raw-signup referral counter with a buyer-focused referral metric and stopped held affiliate commission clears from polluting download analytics.

### Installer and Post-Install Fixes
- Made the hidden config path editable again during install, with validation.
- Stopped `.htaccess` from blocking `post_install_check.php`.
- Bootstrapped sessions correctly in `post_install_check.php`.
- Softened and fixed the installer cleanup warning logic.

### Download Page Actions and Audit Logging
- Added the download-page action bar so eligible signed-in users can save a file into their account as a deduplicated logical copy without re-uploading it.
- Added admin Uploads settings to control whether the download-page save action is available for Free, Premium, and Admin users.
- Added uploader-facing deleted-file history in account settings, with encrypted-at-rest deletion reasons and actor labels.
- Required delete reasons for admin file removals and wired that requirement through the public download page and the normal file manager delete flow.
- Centralized file deletion history through the shared hard-delete path so single delete, bulk delete, trash empty, folder tree deletion, pending-purge jobs, and cleanup jobs all log consistently.
- Fixed save-to-account race conditions by moving dedupe and storage-quota enforcement under the same locked transaction, preventing parallel requests from creating duplicate logical copies or overshooting account storage limits.
- Stopped uploader-visible deletion history from exposing real admin usernames by using the fixed public label `Administrator`.
- Encrypted generic user activity log descriptions at rest.

## v0.1.3

### Packaging and PHP Compatibility
- Fixed Composer packaging drift so fyuhls installs cleanly on the intended `PHP 8.2+` target instead of inheriting an accidental `PHP 8.4` requirement from a newer lockfile resolution environment.
- Added a Composer platform target for PHP 8.2, re-resolved the lockfile against that floor, and downgraded the locked `symfony/filesystem` and PHPUnit dependency stack to PHP-8.2-compatible versions.
- Operators on normal PHP 8.2 and 8.3 VPS installs should no longer see Composer incorrectly report that fyuhls requires PHP 8.4 just to install dependencies.

### Admin Documentation
- Expanded the in-app admin documentation and page guides for the Dashboard, Cron Jobs, and Support Center so operational alerts, heartbeat health, support-bundle handling, and updater expectations are explained more clearly inside the admin area.
- Added new long-form admin docs coverage for File Manager support workflows and Downloads/Delivery troubleshooting so page guides and `/admin/docs` better reflect the current product surface.

## v0.1.2

### Security Hardening
- Generated unique per-install `app_key` values, added runtime warnings for older installs still using insecure defaults, and auto-rotated the key when the hidden config file is writable.
- Hardened installer and trust-boundary behavior by enforcing HTTPS outside local development, generating safe hidden config paths automatically, and restricting hidden config targets to absolute `.php` files outside the webroot and config directories.
- Tightened proxy, host, and URL trust handling so trusted base URLs, password reset links, verification links, payment/share links, secure-cookie behavior, and forwarded HTTPS detection no longer trust arbitrary request hosts or unsafe proxy headers.
- Revalidated authenticated users against the database on every request and moved maintenance-mode and VPN-block admin bypass checks onto that revalidated auth path.
- Strengthened plugin and upload safety by confining plugin autoload paths to the expected plugin base, requiring real MIME detection, and adding extra storage `.htaccess` defense-in-depth for legacy PHP handlers.
- Standardized CSRF, validation, and other security-sensitive error exits onto proper HTTP status codes and shared 4xx handling, rotated CSRF tokens after successful verification, and limited CSRF debug logging to debug mode.
- Added direct endpoint throttling for signed/public downloads, abuse reports, forgot-password requests, contact and DMCA forms, plus an extra IP-wide login spray limit on top of the username-specific login limiter.
- Hardened payment and transfer edges with fresher Stripe callback validation, replay tracking, safer transaction transitions, and cleaner remote-upload errors that keep sensitive transport details in logs instead of user-facing responses.
- Whitelisted admin ad-slot keys, required clean absolute `https://` CDN download origins, restricted configurable Nginx completion log paths to safe absolute log-style files with matching runtime validation, and limited updater downloads to trusted GitHub hosts.
- Expanded default Apache hardening headers with Permissions-Policy, COOP/CORP, and X-Permitted-Cross-Domain-Policies, and moved HSTS delivery into `.htaccess`.

### Updater Safety
- Reworked the one-click updater around a local manifest of core-owned release files, structured JSON preview/apply reports under `storage/cache/`, and guarded overwrite backups under `storage/update_backups/`.
- Added preview and apply flows that show pending updates, quarantine stale unchanged core files under `storage/update_quarantine/`, and leave locally modified stale files alone instead of blindly overwriting or deleting them.
- Tightened release archive handling by sticking to the latest release archive flow, validating ZIP entries before extraction, handling directory/file shape conflicts more safely during apply, and documenting an explicit `/storage/` deny block in the Nginx example config.

### Download Page Architecture
- Refactored the public download page and download state pages onto a shared internal rendering path while keeping existing routes, signed-link behavior, and package-driven gating compatible with live installs.
- Moved shared download-page data preparation into a dedicated service and reusable partials so countdown, captcha, share links, ads, streaming blocks, and state messages can evolve together without rewriting the controller each time.

### File Manager UX
- Expanded bulk workflows with bulk copy, selection summaries, single-click public/private actions, and toast notifications with undo for move and trash.
- Improved in-page discovery and control with search, type/visibility/status filter chips, largest-first sorting, visible-item selection shortcuts, and keyboard shortcuts for search, trash, permanent delete, move, rename, select-all, and clear selection.
- Reduced full-page refreshes by letting trash, move, folder creation, and permanent delete update the current view live instead of forcing a reload.
- Added double-click inline rename, unified dropdown/context/mobile action handling, and fixed asset cache-busting by switching file-manager CSS and JS versioning from `time()` to `filemtime()`.
- Added a sidebar storage quota bar with warning states near capacity, upgraded daily download bandwidth into a visual progress bar, and fixed trash handling so soft-deleted folders appear correctly and drag-out restore works as expected.

### Admin Dashboard
- Reworked the admin dashboard into a more action-focused control center with a new top-left default layout for Support and Diagnostics, cleaner widget spacing, and improved readability in dense cards like Top Content and System Automation.
- Added an Attention Needed strip and a What changed today summary for recent errors, overdue automation, moderation backlog, storage pressure, SMTP gaps, and daily movement.
- Made key operational metric chips clickable, added light healthy/warning/danger state styling, and introduced a Reset layout button to restore the default widget order and collapse state.


## v0.1.1

### Security
- Restricted the API download-link endpoint so it no longer issues signed public download URLs outside the normal protected browser flow. The route now requires authenticated `files.read` access and is limited to the file owner or an admin.
- Removed the public `/test` debug route from the production app surface.
- Hardened installer and post-install behavior so configured sites no longer expose useful installation state to normal visitors, and replaced raw setup error reflection with safer generic messages while keeping details in server logs.
- Switched CSRF verification to a session-authoritative flow instead of trusting the readable cookie token as the primary source of truth.
- Replaced deterministic AES IV generation with a fresh random IV for each encryption call so repeated encrypted values no longer produce identical ciphertext in the database.
- Tightened CSP with nonce-based inline handling, stronger default browser protections, and removal of inline event/style allowances across the live app and setup pages.
- Added proxy-aware HTTPS and secure-cookie handling so direct-server and Cloudflare-style deployments apply transport security consistently.
- Tightened trusted proxy handling so forwarded IP headers are not accepted from broad private-network ranges by default.
- Hardened plugin path and ZIP extraction handling to better prevent unsafe extraction targets and deletion outside the intended plugin area.
- Improved upload and media-processing safety by handling temp-file failures, malformed image thumbnail inputs, and ffmpeg path execution more defensively.

### Storage and Setup
- Improved the storage server add and edit pages with clearer setup guidance for keys, endpoints, regions, and bucket CORS.
- Added Wasabi bucket loading and Fyuhls CORS automation directly to the storage server forms.
- Updated Wasabi CORS automation so it preserves existing non-Fyuhls bucket rules instead of overwriting the full bucket policy.

### Frontend and CSP Cleanup
- Removed inline event handlers and source-level inline `style` attributes across the app so the stricter CSP rollout could be applied safely.

### Download Page UX
- Download limit responses now render in the normal website layout with download-page styling and ad placements instead of plain-text error pages.
- Public download pages now include click-to-copy share fields above the abuse section, with page, HTML, forum, and image embed code formats where applicable.
- Daily download limit pages now distinguish between users who have already used their daily allowance and files that are too large to fit within the package's total daily bandwidth limit.
- Dashboard-style account sidebars now show the remaining daily download allowance, including `Unlimited` for packages without a daily bandwidth cap.
- Referral link displays now consistently use the non-guessable public user ID instead of falling back to numeric account IDs, and the rewards payout toolbar layout was tightened so the action button fits cleanly.
- Storage migration batches now remember the previously selected source server, destination server, and batch limit between clicks so large moves can be processed in repeated batches without re-entering the form each time.
- The admin stored-files view now distinguishes unique stored objects from deduplicated logical file entries, with a quick summary count and per-file duplicate badges based on shared storage references.

### Upload Experience
- Improved upload session error responses so users now see clear package-limit, quota, and storage-capacity messages instead of only a generic upload failure.
- Replaced generic browser alert popups during upload failures with on-screen file manager notices so errors feel cleaner and less disruptive.
- Upload errors now feel much cleaner overall: users stay on the page, see the real reason, and do not get hit with the old generic browser popups anymore.
- Blocked file types are now rejected during multipart session creation, so disallowed uploads show the real file-type error instead of a misleading storage or CORS failure.
- Updated CSP so direct multipart uploads to configured storage providers are allowed by the browser, and improved fallback network error text so CSP-related upload blocks are not misreported as only bucket CORS issues.
- Fixed the public download countdown so it becomes visible correctly after captcha verification instead of staying hidden while the timer runs.
