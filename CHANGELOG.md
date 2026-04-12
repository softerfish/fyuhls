# Changelog

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
