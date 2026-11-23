<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /#newsletter'); exit; }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
function dd_signup_result(string $heading, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Diesel Designs Newsletter</title>'
      . '<body style="margin:0;background:#0b0910;color:white;font:16px Arial;min-height:100vh;display:grid;place-items:center"><main style="max-width:550px;margin:20px;padding:32px;border:1px solid #756083;border-radius:18px;background:#1c1326;text-align:center">'
      . '<h1 style="color:#fe8ed3">' . dd_e($heading) . '</h1><p style="line-height:1.6">' . dd_e($message) . '</p><a style="color:#65e2e3" href="/">Back to Diesel Designs</a></main></body></html>';
    exit;
}
try {
    $db = dd_db();
    if ((string)($_POST['website'] ?? '') !== '') dd_signup_result('Thank you!', 'If your email is eligible, you will receive a confirmation message.');
    if (dd_rate_limited($db,'signup',6,60)) dd_signup_result('Please try later', 'We have received several requests. Try again in about an hour.');
    dd_record_rate($db,'signup');
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (strlen($email) > 254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
        dd_signup_result('Check your email', 'Enter a valid email address and try again.');
    }
    if (!isset($_POST['consent'])) dd_signup_result('One more step', 'Please check the box to agree to receive marketing emails.');
    $st = $db->prepare('SELECT * FROM subscribers WHERE email=?');
    $st->execute([$email]);
    $existing = $st->fetch();
    if ($existing && $existing['status'] === 'active') {
        dd_signup_result('You are on the list!', 'If your address is already subscribed, no further action is needed.');
    }
    if ($existing && $existing['confirmation_sent_at'] && strtotime((string)$existing['confirmation_sent_at']) > time()-900) {
        dd_signup_result('Check your inbox!', 'If eligible, you will receive a confirmation email. Please also check spam or promotions.');
    }
    if (dd_remaining_quota($db)<1) dd_signup_result('Email list temporarily busy', 'Confirmation emails are paused for the daily sending limit. Please try again tomorrow.');
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256',$token);
    $expires = gmdate('Y-m-d H:i:s',time()+48*3600);
    if ($existing) {
        $db->prepare("UPDATE subscribers SET status='pending',token_hash=?,token_expires=?,confirmation_sent_at=CURRENT_TIMESTAMP,unsubscribed_at=NULL WHERE id=?")
            ->execute([$hash,$expires,$existing['id']]);
    } else {
        $db->prepare('INSERT INTO subscribers(email,status,token_hash,token_expires,confirmation_sent_at) VALUES (?,\'pending\',?,?,CURRENT_TIMESTAMP)')
            ->execute([$email,$hash,$expires]);
    }
    {
        $link = dd_url('/newsletter/confirm.php?token=' . $token);
        $html = dd_email_html('Confirm your subscription',
          '<p>Thanks for joining the Diesel Designs list! Click below to confirm you want emails about new designs, special offers, and updates.</p>'
          . '<p><a href="'.dd_e($link).'" style="display:inline-block;background:#936aed;color:#fff;padding:13px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Confirm my email</a></p>'
          . '<p>This link expires in 48 hours. If you did not request this, you can ignore this email.</p>');
        dd_send_logged($db,'confirmation',$email,'Confirm your Diesel Designs subscription',$html);
    }
    dd_signup_result('Check your inbox!', 'Please click the confirmation link to finish subscribing. If it does not arrive, try again in 15 minutes or contact us.');
} catch (Throwable $e) {
    error_log('Diesel newsletter signup: ' . $e->getMessage());
    dd_signup_result('Something went wrong', 'We could not complete the request right now. Please try again later.');
}
