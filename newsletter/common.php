<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

function dd_config(): array {
    static $config = null;
    if ($config === null) {
        $path = '/etc/dieseldesigns-newsletter/config.php';
        if (!is_file($path)) {
            http_response_code(503);
            exit('The newsletter is not configured yet.');
        }
        $config = require $path;
        if (!is_array($config)) { throw new RuntimeException('Invalid newsletter configuration'); }
    }
    return $config;
}
function dd_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = dd_config();
        $pdo = new PDO('sqlite:' . $cfg['db_path'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }
    return $pdo;
}
function dd_schema(PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','unsubscribed')),
    token_hash TEXT,
    token_expires TEXT,
    confirmation_sent_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TEXT,
    unsubscribed_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_subscribers_status ON subscribers(status);
CREATE TABLE IF NOT EXISTS campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','sending','completed')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    launched_at TEXT,
    completed_at TEXT
);
CREATE TABLE IF NOT EXISTS deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    subscriber_id INTEGER NOT NULL REFERENCES subscribers(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','sent','failed','skipped')),
    last_error TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(campaign_id,subscriber_id)
);
CREATE INDEX IF NOT EXISTS idx_deliveries_status ON deliveries(status, campaign_id);
CREATE TABLE IF NOT EXISTS email_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    recipient TEXT NOT NULL,
    successful INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_email_log_date ON email_log(attempted_at);
CREATE TABLE IF NOT EXISTS rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rate_limits ON rate_limits(category,fingerprint,attempted_at);
SQL);
}
function dd_e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function dd_url(string $path): string { return rtrim((string)dd_config()['base_url'], '/') . $path; }
function dd_now(): string { return gmdate('Y-m-d H:i:s'); }
function dd_ip_fingerprint(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash_hmac('sha256', $ip, (string)dd_config()['secret']);
}
function dd_rate_limited(PDO $db, string $category, int $max, int $minutes): bool {
    $fingerprint = dd_ip_fingerprint();
    $cutoff = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
    $st = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE category=? AND fingerprint=? AND attempted_at>=?');
    $st->execute([$category, $fingerprint, $cutoff]);
    return (int)$st->fetchColumn() >= $max;
}
function dd_record_rate(PDO $db, string $category): void {
    $db->prepare('INSERT INTO rate_limits(category,fingerprint) VALUES (?,?)')->execute([$category,dd_ip_fingerprint()]);
    if (random_int(1,40) === 1) $db->exec("DELETE FROM rate_limits WHERE attempted_at < datetime('now','-2 days')");
}
function dd_unsub_token(array $subscriber): string {
    return hash_hmac('sha256', (string)$subscriber['id'] . '|' . strtolower((string)$subscriber['email']), (string)dd_config()['secret']);
}
function dd_unsub_url(array $subscriber): string {
    return dd_url('/newsletter/unsubscribe.php?id=' . (int)$subscriber['id'] . '&token=' . dd_unsub_token($subscriber));
}
function dd_email_html(string $title, string $bodyHtml, ?string $unsubscribeUrl = null): string {
    $footer = '<p style="margin:30px 0 0;color:#747484;font-size:12px;line-height:1.65">'
        . nl2br(dd_e((string)dd_config()['postal_address']))
        . '<br>Diesel Designs';
    if ($unsubscribeUrl !== null) $footer .= '<br><a style="color:#8e58d8" href="' . dd_e($unsubscribeUrl) . '">Unsubscribe</a>';
    $footer .= '</p>';
    return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;background:#0b0910;font:16px Arial,Helvetica,sans-serif;color:#29212f">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b0910"><tr><td align="center" style="padding:28px 12px">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#fff;border-radius:16px;overflow:hidden">'
      . '<tr><td style="padding:26px;background:#15101c;color:#fff;font-size:21px;font-weight:bold;border-bottom:5px solid #fe8ed3">DIESEL <span style="color:#fe8ed3">DESIGNS</span></td></tr>'
      . '<tr><td style="padding:30px"><h1 style="font-size:24px;margin:0 0 18px">' . dd_e($title) . '</h1>'
      . '<div style="font-size:16px;line-height:1.75">' . $bodyHtml . '</div>' . $footer . '</td></tr></table></td></tr></table></body></html>';
}
function dd_render_body(string $body): string {
    // Plain text is escaped to prevent scripts and unsafe HTML from reaching subscribers.
    $escaped = dd_e($body);
    $linked = preg_replace_callback('~https?://[^\s&lt;&gt;"\']+~i', static function(array $m): string {
        // htmlspecialchars has already escaped &, <, >, quotes. Keep links scheme-limited.
        $url = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<a style="color:#8e58d8" href="' . dd_e($url) . '">' . $m[0] . '</a>';
    }, $escaped);
    return nl2br($linked ?? $escaped, false);
}
function dd_send_mail(string $recipient, string $subject, string $html, ?string $unsubscribeUrl = null): void {
    $cfg = dd_config();
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension required.');
    if (empty($cfg['brevo_api_key'])) throw new RuntimeException('Email provider API key not configured.');
    $payload = [
        'sender' => ['name' => (string)$cfg['sender_name'], 'email' => (string)$cfg['sender_email']],
        'to' => [['email' => $recipient]],
        'subject' => $subject,
        'htmlContent' => $html,
    ];
    if ($unsubscribeUrl !== null) {
        $payload['headers'] = [
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['api-key: ' . $cfg['brevo_api_key'], 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    try {
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Email provider request failed (HTTP ' . $status . ').');
        }
    } finally { curl_close($ch); }
}
function dd_send_logged(PDO $db, string $kind, string $recipient, string $subject, string $html, ?string $unsubscribeUrl = null): void {
    // Count attempts before sending so intermittent failures never flood an API plan.
    $db->prepare('INSERT INTO email_log(kind,recipient) VALUES (?,?)')->execute([$kind,$recipient]);
    $id = (int)$db->lastInsertId();
    dd_send_mail($recipient,$subject,$html,$unsubscribeUrl);
    $db->prepare('UPDATE email_log SET successful=1 WHERE id=?')->execute([$id]);
}
function dd_remaining_quota(PDO $db): int {
    $st = $db->prepare('SELECT COUNT(*) FROM email_log WHERE attempted_at >= ?');
    $st->execute([gmdate('Y-m-d H:i:s', time()-86400)]);
    // 250 attempts within any rolling 24 hours, reserving headroom below Brevo's 300/day Free ceiling.
    return max(0,250-(int)$st->fetchColumn());
}
function dd_csrf(): string {
    if (empty($_SESSION['dd_csrf'])) $_SESSION['dd_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['dd_csrf'];
}
function dd_check_csrf(): void {
    if (!hash_equals(dd_csrf(),(string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Security check failed. Reload the page and try again.');
    }
}
function dd_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('DDNEWSADMIN');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/admin/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    ini_set('session.use_strict_mode','1');
    session_start();
}
