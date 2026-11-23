<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT);
$token=(string)($_GET['token']??'');
$valid=false;
$row=null;
try {
    if ($id && preg_match('/^[a-f0-9]{64}$/D',$token)) {
        $db=dd_db();
        $st=$db->prepare('SELECT * FROM subscribers WHERE id=?');
        $st->execute([$id]);
        $row=$st->fetch();
        $valid=(bool)($row && hash_equals(dd_unsub_token($row),$token));
    }
    if ($valid && $_SERVER['REQUEST_METHOD']==='POST') {
        $db->prepare("UPDATE subscribers SET status='unsubscribed',unsubscribed_at=CURRENT_TIMESTAMP,token_hash=NULL,token_expires=NULL WHERE id=?")->execute([$id]);
        $db->prepare("UPDATE deliveries SET status='skipped',updated_at=CURRENT_TIMESTAMP WHERE subscriber_id=? AND status='queued'")->execute([$id]);
    }
} catch(Throwable $e) { $valid=false; error_log('Diesel newsletter unsubscribe: '.$e->getMessage()); }
$done=$valid && ($row['status']==='unsubscribed' || $_SERVER['REQUEST_METHOD']==='POST');
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Unsubscribe — Diesel Designs</title>'
  . '<body style="margin:0;background:#0b0910;color:white;font:16px Arial;min-height:100vh;display:grid;place-items:center"><main style="max-width:550px;margin:20px;padding:32px;border:1px solid #756083;border-radius:18px;background:#1c1326;text-align:center">';
if (!$valid) echo '<h1>Invalid link</h1><p>This unsubscribe link is not valid.</p>';
elseif ($done) echo '<h1>You have been unsubscribed</h1><p>You will not receive more marketing emails from this list.</p>';
else echo '<h1>Unsubscribe from Diesel Designs?</h1><p>Click below to stop receiving marketing emails.</p><form method="post"><button style="border:0;border-radius:8px;padding:13px 22px;background:#fe8ed3;color:#1a1020;cursor:pointer;font-weight:bold" type="submit">Unsubscribe me</button></form>';
echo '<p><a style="color:#65e2e3" href="/">Back to Diesel Designs</a></p></main></body></html>';
