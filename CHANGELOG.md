# Changelog

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
- Hardened installer and post-install behavior so configured sites do not keep exposing useful installation state to normal visitors, and replaced raw setup error reflection with safer generic messages while keeping details in server logs.
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
- Download limit responses now render in the normal website layout with the download-page styling and ad placements instead of plain text error pages.
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
- Updated CSP so direct multipart uploads to configured storage providers are allowed by the browser, and improved the fallback network error text so CSP-related upload blocks are not misreported as only bucket CORS issues.
- Fixed the public download countdown so it becomes visible correctly after captcha verification instead of staying hidden while the timer runs.
