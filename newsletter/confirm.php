<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$heading='Invalid or expired link';
$message='This confirmation link is not valid. Please subscribe again from the homepage.';
$token = (string)($_GET['token'] ?? '');
if (preg_match('/^[a-f0-9]{64}$/D',$token)) {
    try {
        $db=dd_db();
        $st=$db->prepare("SELECT * FROM subscribers WHERE token_hash=? AND status='pending' AND token_expires > CURRENT_TIMESTAMP");
        $st->execute([hash('sha256',$token)]);
        $row=$st->fetch();
        if ($row) {
            $db->prepare("UPDATE subscribers SET status='active',confirmed_at=CURRENT_TIMESTAMP,token_hash=NULL,token_expires=NULL WHERE id=? AND status='pending'")->execute([$row['id']]);
            $heading='You are subscribed!';
            $message='Welcome to the Diesel Designs list! You can unsubscribe anytime using the link in an email.';
            if (dd_remaining_quota($db)>0) {
                try {
                    $html=dd_email_html('Welcome to Diesel Designs!',
                        '<p>You are officially on the list! Watch your inbox for new digital designs, special offers, and exciting announcements.</p><p>Thanks for being here!</p>',
                        dd_unsub_url($row));
                    dd_send_logged($db,'welcome',$row['email'],'Welcome to Diesel Designs!',$html,dd_unsub_url($row));
                } catch (Throwable $e) { error_log('Diesel newsletter welcome: ' . $e->getMessage()); }
            }
        }
    } catch (Throwable $e) { error_log('Diesel newsletter confirmation: ' . $e->getMessage()); }
}
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Diesel Designs Subscription</title>'
  . '<body style="margin:0;background:#0b0910;color:white;font:16px Arial;min-height:100vh;display:grid;place-items:center"><main style="max-width:550px;margin:20px;padding:32px;border:1px solid #756083;border-radius:18px;background:#1c1326;text-align:center">'
  . '<h1 style="color:#fe8ed3">' . dd_e($heading) . '</h1><p style="line-height:1.6">'.dd_e($message).'</p><a style="color:#65e2e3" href="/">Back to Diesel Designs</a></main></body></html>';
