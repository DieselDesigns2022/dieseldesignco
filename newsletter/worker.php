<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
require __DIR__ . '/common.php';
$cfg=dd_config();
$lock=fopen(dirname((string)$cfg['db_path']).'/worker.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);
try {
    $db=dd_db();
    // Explicitly skip people who unsubscribed after the campaign was queued.
    $db->exec("UPDATE deliveries SET status='skipped',updated_at=CURRENT_TIMESTAMP WHERE status='queued' AND subscriber_id IN (SELECT id FROM subscribers WHERE status!='active')");
    $slots=min(20,dd_remaining_quota($db));
    if ($slots>0) {
        $st=$db->prepare("SELECT d.id AS delivery_id,s.id,s.email,c.id AS campaign_id,c.subject,c.body FROM deliveries d JOIN subscribers s ON s.id=d.subscriber_id JOIN campaigns c ON c.id=d.campaign_id WHERE d.status='queued' AND s.status='active' AND c.status='sending' ORDER BY c.id,d.id LIMIT ?");
        $st->bindValue(1,$slots,PDO::PARAM_INT); $st->execute();
        foreach ($st->fetchAll() as $row) {
            $unsub=dd_unsub_url($row);
            $html=dd_email_html($row['subject'],dd_render_body($row['body']),$unsub);
            $status='sent'; $error=null;
            try { dd_send_logged($db,'campaign',$row['email'],$row['subject'],$html,$unsub); }
            catch (Throwable $e) {
                $status='failed'; $error=substr($e->getMessage(),0,200);
                error_log('Diesel newsletter sending: '.$error);
            }
            $db->prepare('UPDATE deliveries SET status=?,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=\'queued\'')
                ->execute([$status,$error,$row['delivery_id']]);
        }
    }
    $db->exec("UPDATE campaigns SET status='completed',completed_at=CURRENT_TIMESTAMP WHERE status='sending' AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.campaign_id=campaigns.id AND d.status='queued')");
    echo "Newsletter worker complete\n";
} catch (Throwable $e) { error_log('Diesel newsletter worker: '.$e->getMessage()); exit(1); }
finally { flock($lock,LOCK_UN); fclose($lock); }
