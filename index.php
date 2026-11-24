<?php
date_default_timezone_set('America/Detroit');
$counterFile = '/var/lib/dieseldesigns-counter/visits.json';
$currentDate = date('Y-m-d');
$now = time();
$visits = ['total' => 0, 'date' => $currentDate, 'today' => 0, 'visitors' => []];
$handle = fopen($counterFile, 'c+');
if ($handle && flock($handle, LOCK_EX)) {
    $stored = json_decode(stream_get_contents($handle), true);
    if (is_array($stored)) {
        $visits = array_merge($visits, $stored);
    }
    $changed = false;
    if (($visits['date'] ?? '') !== $currentDate) {
        $visits['date'] = $currentDate;
        $visits['today'] = 0;
        $changed = true;
    }
    if (!is_array($visits['visitors'] ?? null)) {
        $visits['visitors'] = [];
        $changed = true;
    }
    foreach ($visits['visitors'] as $key => $expires) {
        if ((int) $expires <= $now) {
            unset($visits['visitors'][$key]);
            $changed = true;
        }
    }
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isBot = preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget/i', $userAgent);
    if (!$isBot) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $fingerprint = 'fp:' . hash('sha256', 'diesel-designs-v1|' . $ip . '|' . $userAgent);
        $visitorId = $_COOKIE['dd_visitor_id'] ?? '';
        $visitorKey = $visitorId !== '' ? 'id:' . hash('sha256', $visitorId) : $fingerprint;
        $alreadyCounted = isset($visits['visitors'][$visitorKey]) || isset($visits['visitors'][$fingerprint]);
        if (!$alreadyCounted) {
            $visits['total'] = (int) $visits['total'] + 1;
            $visits['today'] = (int) $visits['today'] + 1;
        }
        if ($visitorId === '') {
            $visitorId = bin2hex(random_bytes(16));
            setcookie('dd_visitor_id', $visitorId, [
                'expires' => $now + 86400,
                'path' => '/',
                'domain' => '.dieseldesigns.net',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            $visitorKey = 'id:' . hash('sha256', $visitorId);
        }
        $visits['visitors'][$fingerprint] = $now + 86400;
        $visits['visitors'][$visitorKey] = $now + 86400;
        $changed = true;
    }
    if ($changed) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($visits));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
}
if ($handle) {
    fclose($handle);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diesel Designs | Digital Designs, Websites &amp; Branding</title>
<meta name="description" content="Diesel Designs digital products, custom websites, logos and branding services.">
<style>
*{box-sizing:border-box}:root{--pink:#fe8ed3;--purple:#936aed;--teal:#65e2e3;--blue:#936aed;--white:#fff;--muted:rgba(255,255,255,.78)}html,body{margin:0;min-height:100%;background:#000;color:var(--white);font-family:Arial,Helvetica,sans-serif}body{min-height:100vh;background:radial-gradient(circle at 8% 90%,rgba(147,106,237,.18),transparent 28%),radial-gradient(circle at 92% 85%,rgba(101,226,227,.15),transparent 28%),#000}main{width:min(1180px,calc(100% - 28px));min-height:calc(100vh - 38px);margin:auto;padding:14px 0;display:flex;flex-direction:column;justify-content:center;gap:14px}.hero{text-align:center}.hero img{display:block;width:100%;height:auto;object-fit:contain;margin:auto}.choices{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{position:relative;min-height:245px;padding:25px 28px;border-radius:24px;overflow:hidden;display:flex;flex-direction:column;background:linear-gradient(145deg,rgba(255,255,255,.09),rgba(255,255,255,.025));border:1px solid rgba(255,255,255,.16);box-shadow:0 18px 50px rgba(0,0,0,.42)}.card:before{content:"";position:absolute;inset:0 0 auto;height:5px}.shop:before{background:linear-gradient(90deg,var(--purple),var(--pink))}.services:before{background:linear-gradient(90deg,var(--teal),var(--blue))}.card:after{content:"";position:absolute;width:180px;height:180px;right:-80px;top:-80px;border-radius:50%;filter:blur(15px);opacity:.18}.shop:after{background:var(--pink)}.services:after{background:var(--teal)}.eyebrow{margin:0 0 9px;font-size:.72rem;font-weight:900;letter-spacing:.18em;text-transform:uppercase}.shop .eyebrow{color:var(--pink)}.services .eyebrow{color:var(--teal)}h1{margin:0 0 10px;font-size:clamp(1.65rem,3vw,2.35rem);line-height:1.05}.card p{margin:0 0 18px;color:var(--muted);font-size:.96rem;line-height:1.5}.action{position:relative;margin-top:auto;min-height:48px;width:100%;display:flex;align-items:center;justify-content:center;border-radius:13px;font-size:.78rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;text-decoration:none}.coming{color:#fff;background:linear-gradient(90deg,rgba(147,106,237,.2),rgba(254,142,211,.18));border:1px solid rgba(254,142,211,.46)}.button{color:#000;background:linear-gradient(90deg,var(--teal),var(--blue));box-shadow:0 9px 25px rgba(101,226,227,.2);transition:transform .2s,filter .2s}.button:hover{transform:translateY(-2px);filter:brightness(1.12)}footer{text-align:center;color:rgba(255,255,255,.46);font-size:.72rem;padding:0 0 10px}@media(max-width:700px){main{justify-content:flex-start;padding-top:8px}.hero img{max-height:none}.choices{grid-template-columns:1fr}.card{min-height:230px}}
.contact{text-align:center;color:var(--muted);font-size:.9rem}.contact a{color:var(--teal);font-weight:700;text-decoration:none}.contact a:hover{color:var(--pink);text-decoration:underline}.visitor-count{margin-bottom:4px;color:rgba(255,255,255,.58)}</style>
<link rel="stylesheet" href="/newsletter/newsletter.css">
</head>
<body>
<main>
<header class="hero"><img src="/welcome_banner.png" alt="Diesel Designs — Digital Designs, Logos and Branding"></header>
<section class="choices" aria-label="Choose a Diesel Designs service">
<article class="card shop"><p class="eyebrow">Digital Design Shop</p><h1>Digital Files by Diesel Designs</h1><p>Shop digital files by Diesel Designs in our new Creative Moth store.</p><a class="action button" href="https://creativemoth.com/store/diesel-designs">Shop Now</a></article>
<article class="card services"><p class="eyebrow">Creative Services</p><h1>Websites, Logos &amp; Branding</h1><p>Explore custom websites, memorable logos, branding packages, and business design services from Diesel Designs.</p><a class="action button" href="https://www.dieseldesigns.co/">Explore Services →</a></article>
</section>
<p class="contact">Need custom work or help with an existing order? Email <a href="mailto:angela@dieseldesigns.co">angela@dieseldesigns.co</a></p><?php require __DIR__ . '/newsletter/widget.php'; ?>
</main>
<footer><div class="visitor-count">Total Visitors: <?php echo number_format((int) $visits["total"]); ?> <span aria-hidden="true">•</span> Today: <?php echo number_format((int) $visits["today"]); ?></div><div>&copy; 2026 Diesel Designs. All rights reserved.</div></footer>
</body>
</html>
