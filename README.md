# fyuhls v0.2.0: High-Performance File Hosting Platform

Does the project look interesting or has it helped you out at all? A star for the project helps a lot.

> Beta notice:
> fyuhls is still a beta release. You should expect errors, rough edges, and incomplete behavior.

> If you find bugs or broken flows, please send them through the built-in Bug Report area using the sanitized error log export so the issue can be reviewed safely and reproduced faster. You can also e-mail logs to **fyuhls.script@gmail.com** and I will support best I can when available. Keep in mind, this is a passion project, not a full time job. 

Note: This project may use affiliate links occasionally. Any revenue earned helps keep this script free and actively maintained, at no extra cost to you.

Welcome to the **Ultimate High-Performance File Hosting Script**. Built on a modern PHP 8.2+ MVC architecture, fyuhls is aimed at operators who want a self-hosted file hosting platform with real control over storage, packages, uploads, downloads, monetization, diagnostics, and admin operations.

Main Page: [https://privacyglance.com](https://privacyglance.com) (demo here with user/pass: `tester` / `tester`)

## Table of Contents
- [Advanced Features](#advanced-features)
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
- [Manual Updates](#manual-updates)
- [Troubleshooting](#troubleshooting)
- [Security Reminders](#security-reminders)

## Advanced Features
- **At-Rest Encryption for Core Sensitive Data**: Fyuhls encrypts core sensitive data such as emails, usernames, filenames, payment details, API keys, IP-bearing support and abuse records, and other high-risk metadata using AES-256 with a fresh random IV per value. Some fields are intentionally not encrypted: lookup helpers like username/email search columns and token hashes are hashed so the app can authenticate and search efficiently, while operational and billing fields such as statuses, counters, package IDs, timestamps, amounts, currencies, and gateway references remain plaintext where workflow, reconciliation, filtering, or indexing would be impractical or brittle with full encryption.
- **Multi-Server Object Storage Architecture**: Connect Local, Backblaze B2, Cloudflare R2, Wasabi, and generic S3-compatible nodes through one storage layer with setup guidance in the admin area.
- **Direct Multipart Upload Pipeline**: Large uploads use direct-to-storage multipart sessions instead of PHP-side chunk assembly, with resumable sessions, quota reservations, and signed part URLs.
- **Public API + Personal API Tokens**: Account-bound API tokens support multipart uploads, managed upload shortcuts, owner-scoped file metadata, and application-controlled download links.
- **Public Link Checker**: An optional footer-linked link checker can validate batches of local file links, summarize available vs unavailable results, and optionally support copy-to-account behavior for signed-in users.
- **Creator Rewards + Two-Factor Security**: Creator rewards (PPD/PPS/Hybrid plus referrals when enabled) and TOTP-based two-factor authentication are built into the script and can be enabled or disabled from the admin area.
- **Centralized Email System**: Professional transaction emails (Verification, Password Resets, Payments) with a built-in Mail Queue and Template Editor.
- **Site Content Editor**: Edit key public-facing pages like Homepage, FAQ, Creator Rewards, and API copy from the admin area without touching theme files.
- **Shared Tickets + Requests Queue**: Logged-in support tickets and unified Contact, Abuse, and DMCA handling flow into one admin queue with replies, notes, and moderation actions.
- **Smart Task Scheduler**: A centralized "Heartbeat" manager handles cleanup, security syncs, and maintenance from a single server cron.
- **Trusted Proxy + Security Controls**: Built-in proxy/IP hardening, Cloudflare trusted proxy syncing, and admin-controlled VPN/proxy protection modes (`None`, `Enforcement`, and `Intelligence`) so operators can choose between doing nothing, hard-blocking, or collecting proxy intelligence for fraud scoring without blocking the visitor.
- **High-Performance Delivery**: Signed download redirects, optional CDN redirects for public object-storage files, and native support for X-Accel-Redirect (Nginx), X-SendFile (Apache), and X-LiteSpeed-Location (LiteSpeed).
- **Ops-Focused Admin Tooling**: Sanitized support exports, a triage-first System Status page, and cleaner admin docs/resources help operators diagnose issues faster.

**Estimated installation time:** 15 minutes

---

## What You'll Need Before Starting

| What You Need | Where to Get It |
|---|---|
| Domain name | Your domain registrar (PorkBun, CloudFlare, etc.) |
| Web hosting | [Hostinger web hosting](https://www.hostinger.com?REFERRALCODE=PHXCORRECHKN "additional 20% off with the provided link") and an extra 20% off on top of current discounts with the provided link |
| MySQL | You'll create these in Step 3 |
| SMTP | [Hostinger Starter E-mail](https://www.hostinger.com?REFERRALCODE=PHXCORRECHKN "additional 20% off with the provided link") and an extra 20% off with the provided link |

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
| PHP Extensions | PDO, PDO MySQL, OpenSSL, JSON, cURL, Sockets |
| Apache Module | mod_rewrite (enabled by default on cPanel/DirectAdmin) |

Your database and database user must already exist before you run the installer. Create them first in cPanel, DirectAdmin, or your server control panel and grant the user access to the database.

Recommended or feature-dependent PHP extensions:
- `gd` for image thumbnails and related diagnostics.
- `zip` / `ZipArchive` for plugin ZIP uploads and the in-app updater.

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
3. You should see folders like `public`, `src`, `config`, `storage`, `themes`, and `vendor`.

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
2. Follow the installer to connect your database and create your Admin account.
3. Fyuhls now generates a hidden config path automatically outside the webroot when possible, so your database credentials, encryption key, and app key are stored away from the public site by default.
4. If the installer warns that it cannot create or write to the secure config directory, create that directory manually and grant the PHP user temporary write access so setup can finish cleanly.

---

## Step 5 - Post-Install Configuration

### Post-Install Self-Test
Right after installation:
1. Open `/post_install_check.php`.
2. Review the self-test results for writable paths, extensions, and basic environment health.
3. If the installer or self-test files still exist after setup, remove them manually once you are done.

### Config Hub
Most day-to-day setup now lives in **Admin > Config Hub**.
1. Open **General** to set the site name, registration behavior, and core public-site options.
2. Open **Security** to configure login protections, IP controls, captcha, email verification, built-in two-factor authentication rules, and the VPN/proxy protection mode used for enforcement or fraud-intelligence collection.
3. Open **Email** to configure SMTP, test outgoing mail, and edit your templates.
4. Open **Storage** to add local or external file servers, use the add/edit/migrate workflows, and follow the browser-upload CORS guidance for object storage.
5. Open **Uploads** and **Downloads** to set limits, chunking, wait times, direct-link behavior, guest/free-user rules, and the download-state behavior that controls how blocked or unavailable file pages are shown.
6. Open **Link Checker** to control whether the public footer tool is enabled, how many links it can process at once, how aggressively it is rate-limited, and whether signed-in users may copy eligible public files into their own account from the checker.
7. Open **SEO** to manage titles, metadata templates, sitemap/robots output, and verification codes.
8. Open **Requests** to manage Contact, Abuse, and DMCA requests from one inbox, including DMCA file-removal processing directly from the request detail view.
9. Open **Tickets** to configure support inbox behavior, reminder timing, rate limits, and support email notifications.
10. Open **Site Content** to edit public-facing Homepage, FAQ, Creator Rewards, and API copy without touching theme files.

### Public API
Fyuhls includes a public API with a dedicated frontend reference page, an OpenAPI document, and longer wiki documentation.

Key API capabilities:
1. Personal API tokens with per-scope access.
2. Multipart upload session creation and managed upload shortcuts.
3. Resume-friendly session inspection and part signing.
4. App-routed multipart part upload support alongside signed part URLs.
5. Owner-scoped file and folder metadata plus write operations.
6. Application-controlled download link generation.
7. Remote upload management and uploader earnings/payout stats.

Users create their personal API tokens from **/settings**. Current user-facing scopes include:
- `files.upload`
- `files.read`
- `files.write`
- `stats.read`
- `remote.upload`

Main references:
- Frontend API page: `/api`
- OpenAPI document: `/api/v1/openapi.json`
- Detailed wiki guide: `Public API` page in the fyuhls wiki

### Upload and Delivery Model
Large-file production deployments should use the current default architecture:
1. Client starts an upload session.
2. Fyuhls reserves quota and creates multipart state.
3. Client uploads parts directly to object storage.
4. Client reports parts and completes the upload.
5. Fyuhls issues signed download links and optionally redirects eligible public files through a configured CDN.

This keeps PHP out of the bulk file-transfer path for high-volume environments.

### Creator Rewards and Monetization
Creator rewards, referrals, and payout settings are now part of the core script.
1. Go to **Admin > Config Hub > Monetization**.
2. Enable the reward models you want to use (`PPD`, `PPS`, and/or `Hybrid`).
3. Set your payout methods, rates, thresholds, and anti-abuse rules.
4. Users can switch between the enabled earning models from the account-side Creator Rewards and rewards dashboard area, and referral behavior follows the active monetization setup for the install.
5. If you do not want creator rewards or referral features visible on the site, disable them there and the frontend options will be hidden.

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

Replace both the PHP binary and install path with the real values for your server. On shared hosting that may look more like `/usr/local/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php`, depending on how your host exposes PHP to cron.

### Support Bundles
If you need to hand logs to support or an automated agent, use **Admin > Support Center** or the Support Bundle card in **System Status**.

The export is:
- sanitized
- secret-redacted
- downloaded as a plain `.json` file, not a zip archive

### Public Copy and Help Content
Use **Admin > Site Content** when you want to update public-facing copy without editing theme files directly.

Good examples include:
- Homepage marketing copy
- FAQ categories and answers
- Creator Rewards page messaging
- Public API intro and helper text

### Admin Help
fyuhls now keeps operator help in two places:

- **Admin > Docs** for the built-in in-app documentation and page guides
- the **GitHub wiki** for longer setup and workflow guides

The admin sidebar now includes a dedicated **Help** section linking to both.

### Requests Inbox
Fyuhls includes a unified admin request workflow for support and legal moderation.

From **Admin > Requests** you can:
- review Contact, Abuse, and DMCA submissions in one inbox
- reply and add internal notes
- change request status without leaving the detail view
- process matched DMCA file removals directly from the request detail panel without reloading the page

### Link Checker
Fyuhls includes an optional public **Link Checker** that can be placed in the site footer.

When enabled, it can:
- check batches of local file links
- summarize available, unavailable, and invalid results
- support copy-to-account actions for signed-in users if the admin allows it

Its behavior is controlled from **Admin > Config Hub > Link Checker**.

---

## Safe Template Customization

If you want to modify any part of the website, follow these steps so your changes are **never overwritten** during updates:
1. Find the core view you want to override under `src/View/`.
2. Copy it into `themes/custom/` using the same relative path.
3. Example: copy `src/View/home/index.php` to `themes/custom/home/index.php`.
4. Edit the copied file. Fyuhls checks `themes/custom/` first, then the active theme, then the core views.

---

## Manual Updates

If you are updating Fyuhls manually instead of using the in-app updater, use this flow so you do not wipe config, runtime data, or custom theme overrides.

### Before you replace any files
1. Put the site into maintenance mode if possible.
2. Make a full backup of:
   - your database
   - your hidden config file / secure config path
   - the entire current Fyuhls application directory
3. If you have custom view overrides in `themes/custom/`, back those up separately so you can compare them after the update.

### Replace the application files
1. Upload the new Fyuhls release into a temporary folder on the server.
2. Overwrite the shipped application code and assets:
   - `src/`
   - `public/`
   - `config/` except your live `app.php` and `database.php`
   - `themes/default/`
   - shipped root files such as `README.md`, `CHANGELOG.md`, and `nginx.conf.example` if you wanted a copy of them
3. Do **not** overwrite:
   - `storage/`
   - `themes/custom/`
   - `vendor/`
   - `database/`
   - `config/app.php`
   - `config/database.php`
   - your hidden config file outside the webroot
4. If you are updating from a normal packaged release, you should not need to run Composer manually.
5. If you are updating from source rather than a packaged release, follow the separate **Installing From Source** section and run Composer there.

### Re-apply anything environment-specific
After the file replacement, review anything that may have local server-specific values or customizations:
- web-server config such as Nginx or Apache delivery rules
- cron path for `src/Cron/Run.php`
- storage node credentials and delivery settings
- custom theme overrides in `themes/custom/`

### Finish the update
1. Sign in to the admin area.
2. Open **Admin > Config Hub > Security** and review any database health, encryption, or key notices.
3. Open **Admin > Config Hub > Cron Jobs** and trigger the tasks once if you want cleanup, sync, and queue jobs to catch up immediately.
4. Open `/post_install_check.php` if you want a quick sanity pass on writable paths and environment health.
5. Test the most important flows for your install:
   - uploads
   - downloads
   - email sending
   - storage server delivery
   - payment flow if monetization is enabled

### Important note about local edits
If you edited core files directly instead of using `themes/custom/` or another safe override path, those edits may be overwritten during a manual update. Compare your old tree against the new release before deleting your backup.

---

## Installing From Source

If you are deploying directly from this repository instead of a packaged release:
1. Run `composer install --no-dev --optimize-autoloader` on the server so `vendor/` is present.
2. Make sure your writable runtime directories exist and are writable by the web server/PHP user, especially `storage/` and `src/Plugin/`.
3. Point your domain to `public/` exactly as shown above, then continue with the normal installer flow.
4. If you want to run the project test scripts in a development environment, use `composer install` and then run `php tests/run_all.php`.

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
- Confirm your site's CSP allows direct browser connections to the storage endpoint as well as the bucket CORS policy.
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

### Installer says it cannot create the secure config directory
- Create the secure directory shown by the installer manually.
- Grant the PHP user temporary write access to that directory.
- Re-run the installer so it can write the hidden config file outside the webroot.

### "System is already installed"
The installer detected an existing config. Only delete `config/database.php` and run `install.php` again if you are intentionally resetting the install and have already confirmed the impact and taken any needed backups.

---

## Security Reminders
- The installer (`public/install.php`) and `public/post_install_check.php` are blocked after setup, but you should still delete them manually if they remain on disk.
- Keep the project root outside the public web root whenever possible so only `public/` is web-accessible.
- Keep `storage/` protected from direct public access except through the app's intended delivery paths, especially when testing object-storage, CDN, or direct-download behavior.
- Never share your **encryption_key** found in your off-grid config. If lost, all encrypted data is permanently unrecoverable.
- Keep your PHP version up to date for security patches.

---

*Need more help? Start with **Admin > Docs**, then the fyuhls wiki, and then the built-in Bug Report area with the sanitized error log export if you need to send logs.*
