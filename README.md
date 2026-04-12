# fyuhls v0.1: High-Performance File Hosting Platform (Beta)

> Beta notice:
> fyuhls is still a beta release. You should expect errors, rough edges, and incomplete behavior. It is **not** intended to be treated as a fully polished or fully functional production website at this time. 

> If you find bugs or broken flows, please send them through the built-in Bug Report area using the sanitized error log export so the issue can be reviewed safely and reproduced faster. You can also e-mail logs to **fyuhls.script@gmail.com** and I will support best I can when available. Keep in mind, this is a passion project, not a full time job. 

Welcome to the **Ultimate High-Performance File Hosting Script**. Built on a modern PHP 8.2+ MVC architecture, fyuhls is aimed at operators who want a self-hosted file hosting platform with real control over storage, packages, uploads, downloads, monetization, diagnostics, and admin operations.

## v0.1.1

- Security
Restricted the API download-link endpoint so it no longer issues signed public download URLs outside the normal protected browser flow. The route now requires authenticated `files.read` access and is limited to the file owner or an admin.
Removed the public `/test` debug route from the production app surface.
Hardened installer and post-install behavior so configured sites do not keep exposing useful installation state to normal visitors, and replaced raw setup error reflection with safer generic messages while keeping details in server logs.
Switched CSRF verification to a session-authoritative flow instead of trusting the readable cookie token as the primary source of truth.
Tightened CSP with nonce-based inline handling, stronger default browser protections, and removal of inline event/style allowances across the live app and setup pages.
Added proxy-aware HTTPS and secure-cookie handling so direct-server and Cloudflare-style deployments apply transport security consistently.
Tightened trusted proxy handling so forwarded IP headers are not accepted from broad private-network ranges by default.
Hardened plugin path and ZIP extraction handling to better prevent unsafe extraction targets and deletion outside the intended plugin area.
Improved upload and media-processing safety by handling temp-file failures and malformed image thumbnail inputs more defensively.

- Storage and Setup
Improved the storage server add and edit pages with clearer setup guidance for keys, endpoints, regions, and bucket CORS.
Added Wasabi bucket loading and Fyuhls CORS automation directly to the storage server forms.
Updated Wasabi CORS automation so it preserves existing non-Fyuhls bucket rules instead of overwriting the full bucket policy.

- Frontend and CSP Cleanup
Removed inline event handlers and source-level inline `style` attributes across the app so the stricter CSP rollout could be applied safely.

- Download Page UX
Download limit responses now render in the normal website layout with the download-page styling and ad placements instead of plain text error pages.
Public download pages now include click-to-copy share fields above the abuse section, with page, HTML, forum, and image embed code formats where applicable.
Daily download limit pages now distinguish between users who have already used their daily allowance and files that are too large to fit within the package's total daily bandwidth limit.
Dashboard-style account sidebars now show the remaining daily download allowance, including `Unlimited` for packages without a daily bandwidth cap.
Referral link displays now consistently use the non-guessable public user ID instead of falling back to numeric account IDs, and the rewards payout toolbar layout was tightened so the action button fits cleanly.
Storage migration batches now remember the previously selected source server, destination server, and batch limit between clicks so large moves can be processed in repeated batches without re-entering the form each time.
The admin stored-files view now distinguishes unique stored objects from deduplicated logical file entries, with a quick summary count and per-file duplicate badges based on shared storage references.

- Upload Experience
Improved upload session error responses so users now see clear package-limit, quota, and storage-capacity messages instead of only a generic upload failure.
Replaced generic browser alert popups during upload failures with on-screen file manager notices so errors feel cleaner and less disruptive.
Upload errors now feel much cleaner overall: users stay on the page, see the real reason, and do not get hit with the old generic browser popups anymore.
Blocked file types are now rejected during multipart session creation, so disallowed uploads show the real file-type error instead of a misleading storage or CORS failure.
Updated CSP so direct multipart uploads to configured storage providers are allowed by the browser, and improved the fallback network error text so CSP-related upload blocks are not misreported as only bucket CORS issues.
Fixed the public download countdown so it becomes visible correctly after captcha verification instead of staying hidden while the timer runs.

## Table of Contents
- [Advanced Features (Beta)](#advanced-features-beta)
- [What You'll Need Before Starting](#what-youll-need-before-starting)
- [Hosting Partnerships & Testing](#hosting-partnerships--testing)
- [Server Requirements](#server-requirements)
- [Configuring for Large Uploads (10GB+)](#configuring-for-large-uploads-10gb)
- [Step 1 - Extract and Upload the Files](#step-1---extract-and-upload-the-files)
- [Step 2 - Point Your Domain to the Application](#step-2---point-your-domain-to-the-application)
- [Step 3 - Create a Database](#step-3---create-a-database)
- [Step 4 - Run the Installer](#step-4---run-the-installer)
- [Step 5 - Post-Install Configuration](#step-5---post-install-configuration)
- [Safe Template Customization](#safe-template-customization)
- [Troubleshooting](#troubleshooting)
- [Security Reminders](#security-reminders)

## Advanced Features (Beta)
- **Full-Coverage AES-256 Encryption**: 100% of sensitive user data (IPs, Emails, Filenames, Payment Details) is stored using industrial-grade deterministic encryption.
- **Multi-Server Object Storage Architecture**: Connect Local, Backblaze B2, Cloudflare R2, Wasabi, and generic S3-compatible nodes through one storage layer with setup guidance in the admin area.
- **Direct Multipart Upload Pipeline**: Large uploads use direct-to-storage multipart sessions instead of PHP-side chunk assembly, with resumable sessions, quota reservations, and signed part URLs.
- **Public API + Personal API Tokens**: Account-bound API tokens support multipart uploads, managed upload shortcuts, owner-scoped file metadata, and application-controlled download links.
- **Core Rewards + Two-Factor Security**: Rewards (PPD/PPS/Affiliate) and TOTP-based two-factor authentication are built into the script and can be enabled or disabled from the admin area.
- **Centralized Email System**: Professional transaction emails (Verification, Password Resets, Payments) with a built-in Mail Queue and Template Editor.
- **Smart Task Scheduler**: A centralized "Heartbeat" manager handles cleanup, security syncs, and maintenance from a single server cron.
- **Trusted Proxy + Security Controls**: Built-in proxy/IP hardening, VPN/proxy blocking, Cloudflare trusted proxy syncing, and admin-controlled security policies.
- **High-Performance Delivery**: Signed download redirects, optional CDN redirects for public object-storage files, and native support for X-Accel-Redirect (Nginx), X-SendFile (Apache), and X-LiteSpeed-Location (LiteSpeed).
- **Sanitized Support Exports**: Admins can generate a plain JSON support bundle with secrets and sensitive values redacted before sharing.

**Estimated installation time:** 15 minutes

---

## What You'll Need Before Starting

| What You Need | Where to Get It |
|---|---|
| A domain name (e.g. `myfiles.com`) | Your domain registrar (PorkBun, CloudFlare, etc.) |
| A VPS or Shared hosting account | Your hosting provider |
| Your MySQL database details | You'll create these in Step 3 |
| **SMTP Details** (Host, Port, User) | Your mail provider (Postmark, Brevo, or cPanel) |

## Hosting Partnerships & Testing

Developing a robust multi-server architecture requires extensive environment testing. If you have a spare VPS or a small-time package (even with very limited bandwidth) you'd like to donate for research and development, we would greatly value the contribution.

**Are you an established hosting provider?** Let's collaborate. We are building a curated list of "Certified Great" file hosting providers for our community and upcoming documentation. Partner with us to help set the industry standard for performance and reliability.

### Server Requirements

Linux hosting only. This project is intended for Linux-based shared hosting, VPS, and dedicated servers. 

Your hosting account must support:

| Requirement | Minimum |
|---|---|
| PHP Version | **8.2 or higher** |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| PHP Extensions | PDO, PDO MySQL, OpenSSL (Required), cURL, Sockets |
| Apache Module | mod_rewrite (enabled by default on cPanel/DirectAdmin) |

Your database and database user must already exist before you run the installer. Create them first in cPanel, DirectAdmin, or your server control panel and grant the user access to the database.

---

### Configuring for Large Uploads (10GB+)

To support large file uploads, you still need sane PHP and web-server limits, but Fyuhls now uses a **multipart direct-to-storage** model for object-storage backends instead of rebuilding the full file inside PHP.

**Recommended baseline for 2GB+ uploads:**
- `upload_max_filesize = 256M`
- `post_max_size = 300M`
- `max_execution_time = 3600`
- `memory_limit = 512M`

**What these values do:**
- `upload_max_filesize`: the largest request PHP will accept for browser/session uploads and admin-side form actions.
- `post_max_size`: the maximum full POST request size PHP will accept. This should stay slightly larger than `upload_max_filesize`.
- `max_execution_time`: gives the app enough time for upload-session orchestration, metadata work, and slower maintenance tasks.
- `memory_limit`: keeps enough RAM available for request handling, metadata extraction, and admin tooling.

These PHP limits are no longer the real ceiling for large object-storage uploads. With multipart uploads, the file bytes go directly from the client to the storage backend, so the final file size can be much larger than a single PHP request as long as your package limits, storage quotas, and backend capacity allow it.

#### Important for B2, R2, Wasabi, and S3-compatible backends

For browser multipart uploads to work correctly, configure bucket CORS so your site origin can:
- `PUT`
- `GET`
- `HEAD`

And expose:
- `ETag`

Without that, direct multipart uploads and resume flows will fail even if the credentials are valid.

If you want a lighter starting point on smaller hosting plans, you can lower the chunk-related PHP limits, but for most real file-hosting installs a 2GB+ baseline is more practical.

#### How to apply these changes:

**1. Using php.ini (VPS/Dedicated):**
Find your `php.ini` file (run `php --ini` at the server command line to locate it) and update the values above. Restart your web server (Apache/Nginx/PHP-FPM) after saving.

**2. Using cPanel:**
1. Log in to **cPanel**.
2. Search for **Select PHP Version**.
3. Click the **Options** tab.
4. Find the settings in the list and click to update them.

---

## Step 1 - Extract and Upload the Files

### 1A - Extract the zip file on your computer
1. Find the `.zip` file you downloaded.
2. Right-click it and choose **Extract All**.
3. You should see folders like `public`, `src`, `config`, `storage`, `vendor`, and `main`.

### 1B - Create an Application Folder
> **Important:** Do NOT upload the files into `public_html` directly. The files need to go in a folder **above** `public_html` for maximum security.

In your server's home directory (e.g., `/home/yourusername/domain.com/`), create a new folder called `fyuhls` or whatever you want.

### 1C - Upload all the files
Upload the **entire contents** of the extracted folder into `/home/yourusername/domain.com/FOLDER MADE ABOVE/`. When done, your structure should look like this:
```
/home/yourusername/domain.com/fyuhls/
									public/   <-- this is the only folder your visitors should access
									src/
									database/
									config/
									storage/
									themes/
									vendor/
									README.md
									composer.json
									composer.lock
									LICENSE
									nginx.conf.example
									
```

---

## Step 2 - Point Your Domain to the Application

### On cPanel:
1. Log in to **cPanel** and go to **Domains**.
2. Find your domain and click **Manage**.
3. Change the **Document Root** to: `/home/yourusername/domain.com/fyuhls/public` Depending on your hosting setup, you may only need to enter `/domain.com/fyuhls/public` and it will update it to the full path for you. Check the final saved path in your file manager.
4. Click **Save**.

### On DirectAdmin:
1. Log in to **DirectAdmin** and go to **Domain Setup**.
2. Click on your domain name.
3. Find the **Document Root** (or Public HTML directory) and change it to: `/home/yourusername/domain.com/fyuhls/public` Depending on your hosting setup, you may only need to enter `/domain.com/fyuhls/public` and it will update it to the full path for you. Check the final saved path in your file manager.
4. Click **Save**.

---

## Step 3 - Create a Database

1. In your control panel (cPanel/DirectAdmin), go to **MySQL Databases**.
2. Create a new database (e.g., `user_files`).
3. Create a new database user with a **strong password**.
4. **Add the User to the Database** and grant **ALL PRIVILEGES**.

---

## Step 4 - Run the Installer

1. Open your browser and go to: `https://yourdomain.com/install.php`
2. Follow the 4-step walkthrough to connect your database and create your Admin account.
3. **Pro Tip:** In the **Absolute Config Path** field, enter a path completely outside of your public web directory (e.g., `/home/yourusername/fyuhls_secure/config.php`). This keeps your encryption keys off-grid.

---

## Step 5 - Post-Install Configuration

### Config Hub
Most day-to-day setup now lives in **Admin > Config Hub**.
1. Open **General** to set the site name, registration behavior, and core public-site options.
2. Open **Security** to configure login protections, IP controls, captcha, email verification, and built-in two-factor authentication rules.
3. Open **Email** to configure SMTP, test outgoing mail, and edit your templates.
4. Open **Storage** to add local or external file servers and choose your delivery method.
5. Open **Uploads** and **Downloads** to set limits, chunking, wait times, direct-link behavior, and guest/free-user rules.
6. Open **SEO** to manage titles, metadata templates, sitemap/robots output, and verification codes.

### Public API
Fyuhls includes a public API with a dedicated frontend reference page and a matching static API reference.

Key API capabilities:
1. Personal API tokens with per-scope access.
2. Multipart upload session creation and managed upload shortcuts.
3. Resume-friendly session inspection and part signing.
4. Owner-scoped file metadata.
5. Application-controlled download link generation.

Main references:
- Frontend API page: `/api`
- Static API reference: `main/api.html`
- Detailed wiki guide: `Public API` page in the fyuhls wiki

### Upload and Delivery Model
Large-file production deployments should use the current default architecture:
1. Client starts an upload session.
2. Fyuhls reserves quota and creates multipart state.
3. Client uploads parts directly to object storage.
4. Client reports parts and completes the upload.
5. Fyuhls issues signed download links and optionally redirects eligible public files through a configured CDN.

This keeps PHP out of the bulk file-transfer path for high-volume environments.

### Rewards and Monetization
Rewards, affiliate, and payout settings are now part of the core script.
1. Go to **Admin > Config Hub > Monetization**.
2. Enable the reward models you want to use.
3. Set your payout methods, rates, thresholds, and anti-abuse rules.
4. If you do not want rewards or affiliate features visible on the site, disable them there and the frontend options will be hidden.

### Email
Configure your SMTP settings to enable account verification, password resets, and user notifications.
1. Go to **Admin > Config Hub > Email**.
2. Enter your SMTP host, port, and credentials.
3. Use the **Test Connection** button to verify your setup.
4. Customize your email templates directly in the editor.

### Nginx "Complete Download" Mod
If you use Nginx `X-Accel-Redirect` but want to pay users only for 100% finished downloads, add this to your Nginx site config:
```nginx
location /protected_uploads/ {
    internal;
    post_action /api/callback/nginx-completed;
}
```

### Automation Heartbeat
To keep your site healthy and process scheduled jobs, cleanup, queue work, multipart session expiry, stale reservation release, checksum/reconciliation work, and maintenance, add this single entry to your server's **Crontab** (set to run every minute):
`* * * * * php /home/yourusername/fyuhls/src/Cron/Run.php`

### Support Bundles
If you need to hand logs to support or an automated agent, use **Admin > Support Center** or the Support Bundle card in **System Status**.

The export is:
- sanitized
- secret-redacted
- downloaded as a plain `.json` file, not a zip archive

---

## Safe Template Customization

If you want to modify any part of the website, follow these steps so your changes are **never overwritten** during updates:
1. Copy the file from `src/View/home/page.php` to `themes/custom/home/page.php`.
2. Edit your new file. The system will prioritize `themes/custom/` automatically.

---

## Troubleshooting

### The requirements page shows "FAIL" next to PDO MySQL
Your PHP installation is missing the `pdo_mysql` extension. Contact your host to enable it.

### SMTP Connection fails or emails aren't sending
- Ensure your SMTP port (usually 465 or 587) is open in your server's firewall.
- Check that your credentials are correct in **Admin > Config Hub > Email**.
- Verify that your **From Email** address matches the one authorized by your SMTP provider.

### Multipart uploads fail immediately on B2, R2, Wasabi, or S3
- Verify that the bucket CORS policy allows your Fyuhls origin.
- Make sure `PUT`, `GET`, and `HEAD` are allowed.
- Make sure `ETag` is exposed.
- Confirm the endpoint, region, access key, and secret key are correct in **Admin > Config Hub > Storage**.

### API client uploads fail or cannot resume
- Confirm the token has the correct scope, especially `files.upload`.
- Use `Idempotency-Key` on create and complete requests.
- Persist the upload session ID client-side so the tool can resume instead of starting over.
- Use the public API reference at `/api` for the current request and response format.

### "Internal Server Error"
- Double-check that the Document Root in Step 2 is pointing to the `public/` folder, not the project root.
- Ensure PHP 8.2+ is selected in your control panel.

### Every page shows "404 Not Found" (except homepage)
- First confirm the document root points to `fyuhls/public`.
- Then confirm Apache `mod_rewrite` or your host's clean-URL equivalent is enabled.

### "Could not connect to database" during install
- Make sure you're typing the database **name**, **username**, and **password** exactly as created in Step 3.
- On cPanel, the full username is often `yourusername_dbusername` - include the prefix.
- The installer does not create databases or database users for you. Create both first in your hosting panel and assign the user to the database with the required privileges.

### "System is already installed"
The installer detected an existing config. To reinstall, delete `config/database.php` and run `install.php` again.

---

## Security Reminders
- The installer (`public/install.php`) should not remain exposed after setup. Delete it manually if it remains.
- Remove `public/post_install_check.php` after you finish using it. It is a setup helper and should not stay accessible on a live site.
- Keep the project root outside the public web root whenever possible so only `public/` is web-accessible.
- Never share your **encryption_key** found in your off-grid config. If lost, all encrypted data is permanently unrecoverable.
- Keep your PHP version up to date for security patches.

---

*Need more help? Check the admin page guides, the fyuhls wiki, or the built-in Bug Report area with the sanitized error log export.*

