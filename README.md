# Diesel Designs — self-hosted newsletter & email admin

**Prepared for** `dieseldesigns.net`, its existing Ubuntu/Nginx/PHP 8.3 site, and the current homepage in `/var/www/dieseldesigns.net/html/index.php`.

## Features

- Brand-matched public signup form that is added to the current homepage **without replacing the homepage**.
- Double opt-in: confirmation link expires after 48 hours; a welcome email follows.
- Subscriber status/search/export; one-click unsubscribe links; admin unsubscribe.
- Password-protected admin at `/admin/`, secure login sessions, login throttle, CSRF protection.
- Save drafts, preview campaigns, test email, and queue sending to confirmed subscribers.
- A scheduled PHP worker sends no more than 20 messages a minute and 250 attempted emails in a rolling 24 hours.
- Email sending through **Brevo's API**; no direct mass mail from the VPS.
- Private SQLite data at `/var/lib/dieseldesigns-newsletter/subscribers.sqlite` and secrets at `/etc/dieseldesigns-newsletter/config.php` (outside public webroot).
- Version 2 adds **Rich Text / HTML** composing with a restricted, server-cleaned HTML body, inline text formatting, lists, links, images by HTTPS URL, test sends and one-step Send to All. Drag-and-drop design, analytics, scheduled campaigns, segmentation, and bounce webhooks are *not* included.

## Before installation

1. Create a [Brevo](https://www.brevo.com/) account and get an API key with transactional sending enabled. Verify your sending domain and set DNS SPF, DKIM and ideally DMARC as instructed in Brevo. Your sender email must use a verified domain and should be a monitored inbox.
2. Decide the admin username and a strong password. Have an email address to receive test messages.
3. Have your **business mailing address** ready for the footer. The FTC requires a valid physical mailing address for commercial emails. A registered USPS PO Box or properly registered private mailbox is acceptable.
4. Confirm your server has PHP 8.3 with `pdo_sqlite`, `curl`, and `dom` extensions. Check `php -m | grep -Ei 'pdo_sqlite|curl|dom'`. If missing, install `php8.3-sqlite3`, `php8.3-curl`, and/or `php8.3-xml` with apt, then restart PHP-FPM as appropriate.
5. This is a first-version app. Review it on a staging copy before putting it into production. Confirm DNS, HTTPS, server configuration, and proper backup access.

## Deploy with a dated backup

1. Upload/extract the contents of this ZIP to a temporary directory on your VPS, **not** the live webroot. Example: `/tmp/dieseldesigns-newsletter/`.
2. SSH to the VPS and run:

   ```bash
   cd /tmp/dieseldesigns-newsletter
   sudo bash install.sh
   ```

3. The installer performs checks, saves a verified timestamped site and Nginx backup to `/root/dieseldesigns-backups/`, copies newsletter and admin code, inserts a CSS link and form include in the existing homepage, writes private configuration, creates the SQLite tables, and adds a cron sender.
4. Visit `/admin/`, log in, send yourself a test, subscribe with a separate address, confirm, check the dashboard, and send a tiny test campaign before promoting the form.

**Important:** The script refuses to overwrite any existing `admin/index.php` or `newsletter/common.php`. It requires a homepage with exactly one `</main>` and one `</head>`, which matches the previously reviewed PHP homepage. No live deployment has been performed by this package's creation.

## Configuration

- `/etc/dieseldesigns-newsletter/config.php`: root-owned, readable by the `www-data` group. This is **never committed**.
- `sender_email` must be a verified Brevo sender. `test_email` is the admin recipient for test messages.
- `postal_address` must be a valid current business mailing address, not a placeholder.
- To reset admin password, replace `admin_password_hash` with a fresh `password_hash` from PHP. Never save plaintext passwords to this file.
- The installer reserves a safety buffer below the provider's Free plan limit. If your provider plan differs, update the configured send logic and confirm provider limits before sending.

## How to use

- `/admin/` → **New Email** → enter subject + formatted message (or HTML) → **Save Draft**, **Send Test**, or **Send to All Subscribers**.
- Open the saved draft → **Preview** → use **Send test** from the form to send a message to the configured test recipient.
- Saved draft → **Send to all subscribers**. Sends queue automatically and are processed by cron once a minute. The dashboard shows counts.
- Each subscriber has a cryptographically signed unsubscribe link in every marketing email. The one-click mail header supports POST while the visible link first displays a confirmation page so link previews cannot unsubscribe people accidentally.
- A subscriber who unsubscribes can only rejoin by requesting and confirming a fresh subscription.

## Limitations & operational notes

- Messages to thousands of people will take multiple days on the Free plan. Plan sending volume before launches. The dashboard records send submission, **not verified inbox delivery or opens**.
- Failed sends remain marked failed (not automatically retried) to avoid unwanted duplicates. Inspect PHP/Nginx logs and Brevo dashboard for the cause.
- The email API may reject messages until the account, sender and sending limits are approved. Test first.
- Protect your subscriber database, monitor error logs, keep OS/PHP updated, and back up both `/etc/dieseldesigns-newsletter/` and `/var/lib/dieseldesigns-newsletter/` securely. Never commit secrets, subscriber data, or personal information to GitHub.
- Cron must run (`/etc/cron.d/dieseldesigns-newsletter`) for queued campaigns to go out.
- The system does not yet sync bounce/spam reports from Brevo. Before large marketing campaigns, a suppression/bounce webhook integration is recommended.

## Version control

Only commit the `newsletter/` and `admin/` source files, plus `install.sh` and this README, to the existing code repository. Do not commit the generated `config.php` or SQLite database. The installer patches the live homepage; to keep source aligned, apply these two additions to the tracked `index.php` as well:

- Just before `</head>`: `<link rel="stylesheet" href="/newsletter/newsletter.css">`
- Just before `</main>`: `<?php require __DIR__ . '/newsletter/widget.php'; ?>`

## Rollback

Stop `/etc/cron.d/dieseldesigns-newsletter`, then restore the exact dated `/root/dieseldesigns-backups/index-before-newsletter-*.php` homepage backup. Remove the new `/admin/` and `/newsletter/` files only after confirming they did not preexist. Preserve the private SQLite database and configuration as a backup until you have verified all subscribers and messages are no longer needed.
