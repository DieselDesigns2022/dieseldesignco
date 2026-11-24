#!/usr/bin/env bash
# Diesel Designs Newsletter installer (Ubuntu/Nginx/PHP 8.3)
# Run from the unpacked package: sudo bash install.sh
set -euo pipefail
umask 027
SITE='/var/www/dieseldesigns.net/html'
CONFIG_DIR='/etc/dieseldesigns-newsletter'
DATA_DIR='/var/lib/dieseldesigns-newsletter'
BACKUPS='/root/dieseldesigns-backups'
SOURCE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
[[ "$EUID" -eq 0 ]] || { echo 'Run with sudo: sudo bash install.sh'; exit 1; }
[[ -f "$SITE/index.php" ]] || { echo "Missing $SITE/index.php — no changes made."; exit 1; }
[[ -f "$SOURCE/newsletter/common.php" && -f "$SOURCE/admin/index.php" ]] || { echo 'Incomplete package'; exit 1; }
[[ ! -e "$SITE/admin" && ! -e "$SITE/newsletter" && ! -e "$CONFIG_DIR/config.php" ]] || { echo 'Admin/newsletter files already exist. Stopping to avoid overwriting them.'; exit 1; }
for extension in pdo_sqlite curl dom; do
  php -m | grep -iq "^${extension}$" || { echo "PHP extension $extension is missing. Install PHP 8.3 sqlite3/curl/xml extensions and retry."; exit 1; }
done
# Inspect the current Nginx configuration without modifying it.
nginx -t >/dev/null
for source in "$SOURCE"/newsletter/*.php "$SOURCE"/admin/index.php "$SITE/index.php"; do
  php -l "$source" >/dev/null || { echo "PHP syntax check failed: $source"; exit 1; }
done
python3 - "$SITE/index.php" <<'PY'
import sys
from pathlib import Path
contents = Path(sys.argv[1]).read_text()
if contents.count('</main>') != 1 or contents.count('</head>') != 1 or 'newsletter/widget.php' in contents:
    raise SystemExit('Homepage markup is different or newsletter is already installed — no changes made.')
PY

printf '\nDIESEL DESIGNS — NEWSLETTER INSTALL\n'
read -r -p 'Admin username [angela]: ' DD_ADMIN_USERNAME
DD_ADMIN_USERNAME="${DD_ADMIN_USERNAME:-angela}"
read -r -s -p 'Create admin password (12+ characters): ' DD_ADMIN_PASSWORD
printf '\n'
[[ "${#DD_ADMIN_PASSWORD}" -ge 12 ]] || { echo 'Password must be at least 12 characters. No changes made.'; exit 1; }
read -r -p 'Email to receive test messages [angela@dieseldesigns.co]: ' DD_TEST_EMAIL
DD_TEST_EMAIL="${DD_TEST_EMAIL:-angela@dieseldesigns.co}"
read -r -p 'Verified sender email [angela@dieseldesigns.co]: ' DD_SENDER_EMAIL
DD_SENDER_EMAIL="${DD_SENDER_EMAIL:-angela@dieseldesigns.co}"
read -r -p 'Sender display name [Diesel Designs]: ' DD_SENDER_NAME
DD_SENDER_NAME="${DD_SENDER_NAME:-Diesel Designs}"
read -r -p 'Business mailing address (PO Box works; blank disables sending): ' DD_POSTAL_ADDRESS
read -r -s -p 'Brevo API key (press Enter to configure later): ' DD_BREVO_API_KEY
printf '\n'
export DD_ADMIN_USERNAME DD_ADMIN_PASSWORD DD_TEST_EMAIL DD_SENDER_EMAIL DD_SENDER_NAME DD_POSTAL_ADDRESS DD_BREVO_API_KEY

# Full restore point before touching live files. Keep outside GitHub and webroot.
install -d -m 700 -o root -g root "$BACKUPS"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_FILE="$BACKUPS/index-before-newsletter-$STAMP.php"
BACKUP_TAR="$BACKUPS/full-site-before-newsletter-$STAMP.tar.gz"
BACKUP_SHA="$BACKUPS/full-site-before-newsletter-$STAMP.sha256"
cp -a -- "$SITE/index.php" "$BACKUP_FILE"
BACKUP_PATHS=("var/www/dieseldesigns.net/html")
[[ -f /etc/nginx/sites-available/dieseldesigns.net ]] && BACKUP_PATHS+=("etc/nginx/sites-available/dieseldesigns.net")
[[ -f /var/lib/dieseldesigns-counter/visits.json ]] && BACKUP_PATHS+=("var/lib/dieseldesigns-counter/visits.json")
tar -C / -czpf "$BACKUP_TAR" "${BACKUP_PATHS[@]}"
tar -tzf "$BACKUP_TAR" >/dev/null
sha256sum "$BACKUP_TAR" > "$BACKUP_SHA"
(cd "$BACKUPS" && sha256sum -c "$(basename "$BACKUP_SHA")")
echo "Verified full-site backup: $BACKUP_TAR"
echo "Homepage backup: $BACKUP_FILE"

COMPLETED=0
rollback_on_failure() {
  exit_code=$?
  if [[ "$exit_code" -ne 0 && "$COMPLETED" -eq 0 ]]; then
    echo "Install failed. Reverting all new newsletter files and original homepage."
    rm -f -- /etc/cron.d/dieseldesigns-newsletter
    rm -rf -- "$SITE/admin" "$SITE/newsletter"
    cp -a -- "$BACKUP_FILE" "$SITE/index.php"
    rm -f -- "$CONFIG_DIR/config.php"
    echo "Restore attempt finished. Verified full backup is retained: $BACKUP_TAR"
  fi
}
trap rollback_on_failure EXIT

install -d -m 750 -o root -g www-data "$CONFIG_DIR"
install -d -m 750 -o www-data -g www-data "$DATA_DIR"
install -d -m 755 -o root -g root "$SITE/admin" "$SITE/newsletter"
install -m 644 -o root -g root "$SOURCE/admin/index.php" "$SITE/admin/index.php"
for file in "$SOURCE"/newsletter/*; do install -m 644 -o root -g root "$file" "$SITE/newsletter/$(basename "$file")"; done

python3 - "$SITE/index.php" <<'PY'
import sys
from pathlib import Path
p=Path(sys.argv[1]); content=p.read_text()
content=content.replace('</head>', '<link rel="stylesheet" href="/newsletter/newsletter.css">\n</head>',1)
content=content.replace('</main>', "<?php require __DIR__ . '/newsletter/widget.php'; ?>\n</main>",1)
p.write_text(content)
PY
if ! php -l "$SITE/index.php"; then
  cp -a "$BACKUP_FILE" "$SITE/index.php"
  echo 'PHP validation failed. Original homepage restored.'
  exit 1
fi

DD_SECRET="$(openssl rand -hex 32)"
export DD_SECRET
php -r '
$cfg = [
    "base_url" => "https://dieseldesigns.net",
    "db_path" => "/var/lib/dieseldesigns-newsletter/subscribers.sqlite",
    "admin_username" => getenv("DD_ADMIN_USERNAME"),
    "admin_password_hash" => password_hash(getenv("DD_ADMIN_PASSWORD"), PASSWORD_DEFAULT),
    "test_email" => getenv("DD_TEST_EMAIL"),
    "sender_email" => getenv("DD_SENDER_EMAIL"),
    "sender_name" => getenv("DD_SENDER_NAME"),
    "postal_address" => getenv("DD_POSTAL_ADDRESS"),
    "brevo_api_key" => getenv("DD_BREVO_API_KEY"),
    "secret" => getenv("DD_SECRET"),
];
if (!filter_var($cfg["test_email"], FILTER_VALIDATE_EMAIL) || !filter_var($cfg["sender_email"], FILTER_VALIDATE_EMAIL)) { fwrite(STDERR,"Invalid email address.\n"); exit(1); }
file_put_contents("/etc/dieseldesigns-newsletter/config.php", "<?php\nreturn " . var_export($cfg, true) . ";\n");
'
unset DD_ADMIN_PASSWORD DD_BREVO_API_KEY DD_SECRET
chown root:www-data "$CONFIG_DIR/config.php"
chmod 640 "$CONFIG_DIR/config.php"
# Initialize data with the same system user as the PHP web worker.
runuser -u www-data -- php -r 'require "/var/www/dieseldesigns.net/html/newsletter/common.php"; dd_schema(dd_db()); echo "Database ready\n";'
# Cron will send a maximum of 20 messages per minute and 250 attempts in a rolling 24-hour window.
printf '%s\n' '* * * * * www-data /usr/bin/php /var/www/dieseldesigns.net/html/newsletter/worker.php >/dev/null 2>&1' > /etc/cron.d/dieseldesigns-newsletter
chmod 644 /etc/cron.d/dieseldesigns-newsletter
COMPLETED=1
printf '\nINSTALL COMPLETE!\nSite: https://dieseldesigns.net/\nAdmin: https://dieseldesigns.net/admin/\nFull backup: %s\n' "$BACKUP_TAR"
printf 'Test the signup and confirm email delivery before inviting subscribers.\n'
printf 'If your Brevo API key was blank, edit /etc/dieseldesigns-newsletter/config.php to add it.\n'
printf 'Keep the /etc configuration and /var/lib data out of GitHub.\n'
