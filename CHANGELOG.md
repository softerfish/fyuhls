# Changelog

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
- Synced Config Hub security notices into the left admin sidebar badge and the main dashboard `Attention Needed` strip, expanded that strip with stale cron heartbeat, Cloudflare IP sync, lingering setup files, SMTP delivery failures, payout-affecting storage delivery warnings, aged pending withdrawals, and reward-fraud backlog, and reworked the Email tab so SMTP tools stay in the narrower working column while the System Email Templates table breaks out into its own full-width section to prevent template subjects and actions from being cut off at normal admin widths.

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
