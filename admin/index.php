<?php
declare(strict_types=1);
require __DIR__ . '/../newsletter/common.php';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
dd_session();
$db=dd_db();
$cfg=dd_config();
function dd_go(string $route=''): never { header('Location: /admin/' . $route, true, 303); exit; }
function dd_flash(string $message): void { $_SESSION['flash']=$message; }
function dd_is_logged_in(): bool { return ($_SESSION['dd_admin']??false)===true && (int)($_SESSION['last_active']??0)>time()-7200; }
function dd_admin_date(?string $date): string {
    if (!$date) return '—';
    try { return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Detroit'))->format('M j, Y g:i A'); }
    catch(Throwable $e) { return $date; }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    dd_check_csrf();
    $action=(string)($_POST['action']??'');
    if ($action==='login') {
        if (dd_rate_limited($db,'admin_login',5,15)) { dd_flash('Too many attempts. Try again in 15 minutes.'); dd_go(); }
        $user=(string)($_POST['username']??'');
        $pass=(string)($_POST['password']??'');
        if (hash_equals((string)$cfg['admin_username'],$user) && password_verify($pass,(string)$cfg['admin_password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['dd_admin']=true;
            $_SESSION['last_active']=time();
            unset($_SESSION['dd_csrf']);
            dd_go();
        }
        dd_record_rate($db,'admin_login');
        dd_flash('Invalid login.'); dd_go();
    }
    if (!dd_is_logged_in()) { http_response_code(403); exit('Your session expired. Please sign in.'); }
    $_SESSION['last_active']=time();
    if ($action==='logout') {
        $_SESSION=[]; session_regenerate_id(true); dd_go();
    }
    try {
        if ($action==='save' || $action==='save_launch') {
            $id=(int)($_POST['id']??0);
            $subject=trim((string)($_POST['subject']??''));
            $body=trim((string)($_POST['body']??''));
            if ($subject==='' || strlen($subject)>150 || $body==='' || strlen($body)>30000) throw new RuntimeException('Subject (up to 150 characters) and message (up to 30,000 characters) are required.');
            if (preg_match('/[\r\n]/',$subject)) throw new RuntimeException('Subject must be one line.');
            $body=dd_clean_html($body);
            if (trim(strip_tags($body))==='' && !str_contains($body,'<img')) throw new RuntimeException('Email message is empty.');
            if ($id>0) {
                $st=$db->prepare("UPDATE campaigns SET subject=?,body=? WHERE id=? AND status='draft'");
                $st->execute([$subject,$body,$id]);
                if (!$st->rowCount()) throw new RuntimeException('Only saved drafts can be edited.');
            } else {
                $db->prepare('INSERT INTO campaigns(subject,body) VALUES (?,?)')->execute([$subject,$body]);
                $id=(int)$db->lastInsertId();
            }
            if ($action==='save') { dd_flash('Draft saved.'); dd_go('?page=edit&id='.$id); }
            $action='launch';
            $_POST['id']=$id;
        }
        if ($action==='test') {
            $subject=trim((string)($_POST['subject']??'')); $body=trim((string)($_POST['body']??''));
            if ($subject===''||$body==='') throw new RuntimeException('Enter a subject and message before testing.');
            if (strlen($subject)>150||strlen($body)>30000||preg_match('/[\r\n]/',$subject)) throw new RuntimeException('Message is too long or the subject contains line breaks.');
            $body=dd_clean_html($body);
            if (trim(strip_tags($body))==='' && !str_contains($body,'<img')) throw new RuntimeException('Email message is empty.');
            if (dd_remaining_quota($db)<1) throw new RuntimeException('Sending limit reached. Try tomorrow.');
            $recipient=(string)$cfg['test_email'];
            if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure a valid test_email first.');
            $id=(int)($_POST['id']??0);
            if ($id>0) {
                $st=$db->prepare("UPDATE campaigns SET subject=?,body=? WHERE id=? AND status='draft'");
                $st->execute([$subject,$body,$id]);
                if (!$st->rowCount()) throw new RuntimeException('Only drafts can be tested.');
            } else {
                $db->prepare('INSERT INTO campaigns(subject,body) VALUES (?,?)')->execute([$subject,$body]);
                $id=(int)$db->lastInsertId();
            }
            dd_send_logged($db,'test',$recipient,'[TEST] '.$subject,dd_email_html($subject,dd_render_body($body)));
            dd_flash('Draft saved and test email submitted to '.$recipient.'.'); dd_go('?page=edit&id='.$id);
        }
        if ($action==='launch') {
            $id=(int)($_POST['id']??0);
            if (!filter_var((string)$cfg['sender_email'],FILTER_VALIDATE_EMAIL) || trim((string)$cfg['postal_address'])==='') throw new RuntimeException('Sender email and legal postal address must be configured.');
            if (empty($cfg['brevo_api_key'])) throw new RuntimeException('Configure the email provider API key before sending.');
            $db->exec('BEGIN IMMEDIATE');
            try {
                $st=$db->prepare("UPDATE campaigns SET status='sending',launched_at=CURRENT_TIMESTAMP WHERE id=? AND status='draft'");
                $st->execute([$id]);
                if (!$st->rowCount()) throw new RuntimeException('Campaign is not an unsent draft.');
                $st=$db->prepare("INSERT OR IGNORE INTO deliveries(campaign_id,subscriber_id) SELECT ?,id FROM subscribers WHERE status='active'");
                $st->execute([$id]);
                $count=(int)$st->rowCount();
                if ($count===0) throw new RuntimeException('No confirmed subscribers to send to.');
                $db->commit();
            } catch (Throwable $e) { $db->rollBack(); throw $e; }
            dd_flash('Campaign queued for '.$count.' confirmed subscriber(s). The scheduled sender will deliver it in batches.'); dd_go('?page=campaigns');
        }
        if ($action==='unsubscribe') {
            $id=(int)($_POST['id']??0);
            $db->prepare("UPDATE subscribers SET status='unsubscribed',unsubscribed_at=CURRENT_TIMESTAMP,token_hash=NULL,token_expires=NULL WHERE id=?")->execute([$id]);
            $db->prepare("UPDATE deliveries SET status='skipped',updated_at=CURRENT_TIMESTAMP WHERE subscriber_id=? AND status='queued'")->execute([$id]);
            dd_flash('Subscriber unsubscribed.'); dd_go('?page=subscribers');
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) { dd_flash('Error: '.$e->getMessage()); dd_go('?page=campaigns'); }
}
$logged=dd_is_logged_in();
if ($logged) $_SESSION['last_active']=time();
$page=(string)($_GET['page']??'home');
if ($logged && $page==='export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="diesel-subscribers-'.gmdate('Ymd').'.csv"');
    $out=fopen('php://output','wb');
    fputcsv($out,['Email','Status','Joined (UTC)','Confirmed (UTC)','Unsubscribed (UTC)']);
    $rows=$db->query('SELECT email,status,created_at,confirmed_at,unsubscribed_at FROM subscribers ORDER BY id DESC');
    foreach($rows as $row) {
        // Protect against formula execution when CSV opens in a spreadsheet.
        foreach ($row as $key=>$val) if (is_string($val) && preg_match('/^[\s]*[=+@\-]/u',$val)) $row[$key]="'".$val;
        fputcsv($out,array_values($row));
    }
    fclose($out); exit;
}
if ($logged && $page==='preview') {
    $st=$db->prepare('SELECT * FROM campaigns WHERE id=?'); $st->execute([(int)($_GET['id']??0)]);
    $campaign=$st->fetch();
    if (!$campaign) { http_response_code(404); exit('Not found'); }
    $example=['id'=>0,'email'=>'preview@example.com'];
    header('Content-Type: text/html; charset=utf-8');
    echo dd_email_html($campaign['subject'],dd_render_body($campaign['body']),null); exit;
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diesel Designs | Email Admin</title>
<style>
:root{color-scheme:dark;--bg:#0c0911;--panel:#191222;--line:#403047;--pink:#fe8ed3;--purple:#aa82f5;--teal:#65e2e3;--text:#f6f3f9;--muted:#b8aebf}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 90% 0%,#291738 0,transparent 38%),var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}a{color:var(--teal)}button,.btn{display:inline-block;border:0;border-radius:10px;padding:11px 16px;background:linear-gradient(90deg,var(--pink),var(--purple));font:700 14px Arial;color:#160d1c;text-decoration:none;cursor:pointer}button:focus-visible,input:focus-visible,textarea:focus-visible{outline:2px solid var(--teal);outline-offset:2px}button.secondary,.btn.secondary{background:#30263b;color:#fff;border:1px solid #594b65}button.danger{background:#50263c;color:#fff;border:1px solid #a04d76}.wrap{max-width:1150px;padding:24px 18px;margin:auto}header.top{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;border-bottom:1px solid var(--line);padding:18px max(18px,calc((100% - 1114px)/2))}.brand{font-size:19px;font-weight:900;letter-spacing:.04em}.brand span{color:var(--pink)}nav{display:flex;flex-wrap:wrap;gap:16px;align-items:center}nav a{color:#eee;text-decoration:none;font-size:14px}.panel{background:linear-gradient(150deg,#1c1527,#15101e);border:1px solid var(--line);border-radius:17px;padding:22px;margin-bottom:19px}.login{max-width:430px;margin:9vh auto}.login h1{font-size:25px}.muted{color:var(--muted)}h1{font-size:29px;margin:6px 0 18px}h2{font-size:20px;margin:4px 0 15px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin:0 0 18px}.stats .panel{margin:0}.number{font-size:31px;font-weight:900;margin-top:8px;color:var(--pink)}label{display:block;font-size:13px;font-weight:700;margin:15px 0 7px}input,textarea{width:100%;background:#0e0b15;color:#fff;border:1px solid #52445e;padding:12px;border-radius:9px;font:15px Arial}textarea{line-height:1.6;min-height:250px;resize:vertical}input[type=search]{max-width:330px}.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:20px 0}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:550px}td,th{text-align:left;padding:13px 10px;border-bottom:1px solid var(--line);font-size:13px}th{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}.status{display:inline-block;border:1px solid #6a5479;padding:5px 9px;border-radius:30px;font-size:11px;font-weight:800}.status.active,.status.completed{color:#82e6b5;border-color:#347f5b}.status.pending,.status.sending{color:#f6d17b;border-color:#92763b}.status.unsubscribed{color:#e6a1ab;border-color:#8f5360}.flash{border:1px solid #8c688e;background:#2e1a38;border-radius:10px;padding:15px 18px;margin-bottom:17px}.inline{display:inline}small{color:var(--muted)}.layout{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}.hint{border-left:3px solid var(--teal);padding-left:13px;color:var(--muted);line-height:1.5}.pill{color:var(--teal);font-size:12px;font-weight:bold}
@media(max-width:720px){.stats{grid-template-columns:repeat(2,1fr)}h1{font-size:24px}.wrap{padding:16px 12px}.panel{padding:17px}header.top{padding:16px}.actions .btn,.actions button{flex:1;text-align:center}}

.editor-tabs{display:flex;gap:8px;margin:15px 0 9px}.editor-tabs button{background:#30263b;color:#fff;border:1px solid #594b65}.editor-tabs button.active{background:var(--teal);color:#0b0911}.editor-tools{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0}.editor-tools button{background:#30263b;color:#fff;border:1px solid #594b65;padding:8px 11px;min-width:36px}.rich-editor{min-height:250px;border:1px solid #594b65;border-radius:10px;padding:17px;background:#fff;color:#29212f;line-height:1.6;overflow:auto}.rich-editor:focus{outline:2px solid var(--teal)}.rich-editor a{color:#936aed}.rich-editor img{max-width:100%;height:auto}.source-editor{min-height:250px;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}.editor-hint{font-size:12px;color:var(--muted)}
</style></head><body>
<?php if (!$logged): ?><main class="wrap"><div class="panel login"><div class="brand">DIESEL <span>DESIGNS</span></div><h1>Email Dashboard</h1><p class="muted">Admin access only.</p>
<?php if (!empty($_SESSION['flash'])): ?><div class="flash"><?=dd_e($_SESSION['flash'])?></div><?php unset($_SESSION['flash']); endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?=dd_e(dd_csrf())?>"><input type="hidden" name="action" value="login"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required autofocus><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required><div class="actions"><button type="submit">Log in</button></div></form></div></main>
<?php else: ?>
<header class="top"><div class="brand">DIESEL <span>DESIGNS</span> <small>EMAIL ADMIN</small></div><nav><a href="/admin/">Dashboard</a><a href="?page=subscribers">Subscribers</a><a href="?page=campaigns">Campaigns</a><a href="?page=compose">New Email</a><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=dd_e(dd_csrf())?>"><input type="hidden" name="action" value="logout"><button type="submit" class="secondary">Log out</button></form></nav></header>
<main class="wrap">
<?php if (!empty($_SESSION['flash'])): ?><div class="flash"><?=dd_e($_SESSION['flash'])?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if ($page==='home'):
    $statuses=$db->query("SELECT status,COUNT(*) total FROM subscribers GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $sent=(int)$db->query("SELECT COUNT(*) FROM deliveries WHERE status='sent'")->fetchColumn();
    $pending=(int)$db->query("SELECT COUNT(*) FROM deliveries WHERE status='queued'")->fetchColumn();
    ?>
<h1>Email Dashboard</h1><p class="muted">Your subscriber list and campaigns, all in one place.</p>
<div class="stats"><div class="panel"><small>Confirmed</small><div class="number"><?=(int)($statuses['active']??0)?></div></div><div class="panel"><small>Awaiting confirmation</small><div class="number"><?=(int)($statuses['pending']??0)?></div></div><div class="panel"><small>Messages sent</small><div class="number"><?=$sent?></div></div><div class="panel"><small>Queued messages</small><div class="number"><?=$pending?></div></div></div>
<div class="panel"><h2>Get started</h2><p class="muted">Only people who confirmed their email can receive campaigns. Sending happens in safe batches, not one giant request.</p><div class="actions"><a class="btn" href="?page=compose">Write a new email</a><a class="btn secondary" href="?page=subscribers">View subscribers</a><a class="btn secondary" href="?page=campaigns">View campaigns</a></div><p class="hint">Available sending allowance: <?=dd_remaining_quota($db)?> attempted emails within the configured rolling 24-hour limit. You can queue campaigns beyond the allowance; the scheduled sender continues as space becomes available.</p></div>
<?php elseif ($page==='subscribers'):
    $search=trim((string)($_GET['q']??'')); $limit=100; $offset=max(0,(int)($_GET['p']??0))*$limit;
    if ($search!=='') { $st=$db->prepare('SELECT * FROM subscribers WHERE email LIKE ? ORDER BY id DESC LIMIT 100 OFFSET ?'); $st->bindValue(1,'%'.str_replace(['%','_'],['\\%','\\_'],$search).'%'); $st->bindValue(2,$offset,PDO::PARAM_INT); $st->execute(); }
    else { $st=$db->prepare('SELECT * FROM subscribers ORDER BY id DESC LIMIT 100 OFFSET ?'); $st->bindValue(1,$offset,PDO::PARAM_INT); $st->execute(); }
    $people=$st->fetchAll(); ?>
<div class="layout"><h1>Subscribers</h1><a class="btn secondary" href="?page=export">Export CSV</a></div>
<div class="panel"><form method="get"><input type="hidden" name="page" value="subscribers"><label for="search">Find an email</label><div class="actions"><input type="search" id="search" name="q" value="<?=dd_e($search)?>" placeholder="Search subscribers"><button type="submit" class="secondary">Search</button></div></form>
<div class="tablewrap"><table><thead><tr><th>Email</th><th>Status</th><th>Joined</th><th>Confirmed</th><th>Action</th></tr></thead><tbody>
<?php foreach($people as $person): ?><tr><td><?=dd_e($person['email'])?></td><td><span class="status <?=dd_e($person['status'])?>"><?=dd_e(ucfirst($person['status']))?></span></td><td><?=dd_e(dd_admin_date($person['created_at']))?></td><td><?=dd_e(dd_admin_date($person['confirmed_at']))?></td><td><?php if($person['status']==='active'): ?><form method="post" onsubmit="return confirm('Unsubscribe this address?');"><input type="hidden" name="csrf" value="<?=dd_e(dd_csrf())?>"><input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="id" value="<?=(int)$person['id']?>"><button class="danger" type="submit">Unsubscribe</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$people): ?><tr><td colspan="5">No subscribers found.</td></tr><?php endif; ?>
</tbody></table></div><div class="actions"><?php if($offset>0): ?><a class="btn secondary" href="?page=subscribers&p=<?=max(0,(int)($_GET['p']??0)-1)?>&q=<?=urlencode($search)?>">Previous</a><?php endif; ?><?php if(count($people)===$limit): ?><a class="btn secondary" href="?page=subscribers&p=<?=((int)($_GET['p']??0)+1)?>&q=<?=urlencode($search)?>">Next</a><?php endif; ?></div></div>
<?php elseif ($page==='campaigns'):
    $campaigns=$db->query("SELECT c.*, (SELECT COUNT(*) FROM deliveries WHERE campaign_id=c.id) total,(SELECT COUNT(*) FROM deliveries WHERE campaign_id=c.id AND status='sent') sent,(SELECT COUNT(*) FROM deliveries WHERE campaign_id=c.id AND status='failed') failed,(SELECT COUNT(*) FROM deliveries WHERE campaign_id=c.id AND status='queued') queued FROM campaigns c ORDER BY c.id DESC LIMIT 100")->fetchAll(); ?>
<div class="layout"><h1>Email Campaigns</h1><a class="btn" href="?page=compose">Create email</a></div>
<div class="panel"><div class="tablewrap"><table><thead><tr><th>Subject</th><th>Status</th><th>Progress</th><th>Created</th><th>Action</th></tr></thead><tbody>
<?php foreach ($campaigns as $c): ?><tr><td><?=dd_e($c['subject'])?></td><td><span class="status <?=dd_e($c['status'])?>"><?=dd_e(ucfirst($c['status']))?></span></td><td><?=(int)$c['sent']?> sent / <?=(int)$c['total']?> selected<?php if($c['failed']): ?> · <?=(int)$c['failed']?> failed<?php endif; ?></td><td><?=dd_e(dd_admin_date($c['created_at']))?></td><td><a href="?page=edit&id=<?=(int)$c['id']?>"><?=($c['status']==='draft'?'Edit':'Details')?></a> · <a target="_blank" rel="noopener" href="?page=preview&id=<?=(int)$c['id']?>">Preview</a></td></tr><?php endforeach; ?>
<?php if (!$campaigns): ?><tr><td colspan="5">No emails yet. Create your first one!</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php elseif ($page==='compose'||$page==='edit'):
    $id=(int)($_GET['id']??0); $c=['id'=>0,'subject'=>'','body'=>'','status'=>'draft'];
    if ($page==='edit') { $st=$db->prepare('SELECT * FROM campaigns WHERE id=?');$st->execute([$id]); $c=$st->fetch(); if (!$c) {echo '<h1>Campaign not found</h1>'; $c=null;} }
    if ($c): $draft=$c['status']==='draft'; ?>
<div class="layout"><h1><?=($c['id']?'Email #'.(int)$c['id']:'Write a New Email')?></h1><a class="btn secondary" href="?page=campaigns">All campaigns</a></div>
<div class="panel"><p class="hint">Write your email using the formatting buttons or switch to HTML to paste or edit your own markup. Emails automatically include your Diesel Designs header, mailing address and unsubscribe link. External images must use secure HTTPS links.</p>
<form method="post" id="editor"><input type="hidden" name="csrf" value="<?=dd_e(dd_csrf())?>"><input type="hidden" name="action" id="email-action" value="save"><input type="hidden" name="id" value="<?=(int)$c['id']?>">
<label for="subject">Subject</label><input id="subject" name="subject" maxlength="150" value="<?=dd_e($c['subject'])?>" required <?=(!$draft?'readonly':'')?> placeholder="New designs just dropped!">
<label>Email message</label>
<div class="editor-tabs"><button type="button" id="tab-rich" class="active" aria-pressed="true">Rich Text</button><button type="button" id="tab-html" aria-pressed="false">HTML</button></div>
<?php if($draft): ?><div id="formatting-tools" class="editor-tools" aria-label="Formatting tools">
<button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button><button type="button" data-cmd="italic" title="Italic"><em>I</em></button><button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
<button type="button" data-cmd="insertUnorderedList" title="Bullet list">• List</button><button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
<button type="button" data-block="h2" title="Heading">Heading</button><button type="button" data-block="p" title="Paragraph">Paragraph</button>
<button type="button" id="add-link" title="Insert link">Link</button><button type="button" id="add-image" title="Insert image from HTTPS URL">Image URL</button>
<label for="text-color" class="editor-hint">Text color <input id="text-color" type="color" value="#fe8ed3" aria-label="Text color" style="width:42px;height:31px;padding:0;vertical-align:middle"></label>
</div><?php endif; ?>
<div id="visual-editor" class="rich-editor" contenteditable="<?=($draft?'true':'false')?>" role="textbox" aria-label="Rich text email body" aria-multiline="true"><?=dd_render_body((string)$c['body'])?></div>
<textarea id="html-editor" class="source-editor" maxlength="30000" spellcheck="false" style="display:none" aria-label="Email body HTML" <?=(!$draft?'readonly':'')?>></textarea>
<textarea id="body" name="body" hidden></textarea>
<p class="editor-hint">HTML mode accepts safe body markup (paragraphs, headings, lists, links, images, and basic text styling). Scripts, embedded forms, and unsafe code are removed.</p>
<?php if($draft): ?><div class="actions">
<button type="submit" id="btn-save" data-action="save">Save Draft</button>
<button type="submit" class="secondary" id="btn-test" data-action="test">Send Test to <?=dd_e((string)$cfg['test_email'])?></button>
<button type="submit" id="btn-send" data-action="save_launch">Send to All Subscribers</button>
</div><?php endif; ?>
</form>
<?php if($c['id']): ?><div class="actions"><a class="btn secondary" target="_blank" rel="noopener" href="?page=preview&id=<?=(int)$c['id']?>">Preview saved email</a></div><?php endif; ?>
<script>
(() => {
 const form=document.getElementById('editor'),rich=document.getElementById('visual-editor'),source=document.getElementById('html-editor'),body=document.getElementById('body');
 const tabRich=document.getElementById('tab-rich'),tabHtml=document.getElementById('tab-html'),tools=document.getElementById('formatting-tools');
 let mode='rich';
 const switchMode=(next)=>{
  if(next===mode)return;
  if(next==='html'){ source.value=rich.innerHTML;source.style.display='block';rich.style.display='none';if(tools)tools.style.display='none'; }
  else{ rich.innerHTML=source.value;rich.style.display='block';source.style.display='none';if(tools)tools.style.display='flex'; }
  mode=next;tabRich.classList.toggle('active',next==='rich');tabHtml.classList.toggle('active',next==='html');
  tabRich.setAttribute('aria-pressed',String(next==='rich'));tabHtml.setAttribute('aria-pressed',String(next==='html'));
 };
 tabRich.addEventListener('click',()=>switchMode('rich'));
 tabHtml.addEventListener('click',()=>switchMode('html'));
 <?php if($draft): ?>
 const edit=(command,value)=>{rich.focus();document.execCommand(command,false,value||null);};
 if(tools) tools.addEventListener('mousedown',e=>{if(e.target.closest('button'))e.preventDefault();});
 if(tools) tools.querySelectorAll('[data-cmd]').forEach(b=>b.addEventListener('click',()=>edit(b.dataset.cmd)));
 if(tools) tools.querySelectorAll('[data-block]').forEach(b=>b.addEventListener('click',()=>edit('formatBlock',b.dataset.block)));
 const link=document.getElementById('add-link');link.addEventListener('click',()=>{const url=prompt('Enter a full https:// link');if(url && /^https?:\/\//i.test(url.trim()))edit('createLink',url.trim());});
 const img=document.getElementById('add-image');img.addEventListener('click',()=>{const url=prompt('Enter a direct HTTPS image URL');if(url && /^https:\/\//i.test(url.trim()))edit('insertImage',url.trim());});
 document.getElementById('text-color').addEventListener('input',e=>edit('foreColor',e.target.value));
 form.querySelectorAll('[data-action]').forEach(b=>b.addEventListener('click',()=>document.getElementById('email-action').value=b.dataset.action));
 <?php endif; ?>
 form.addEventListener('submit',e=>{
   body.value=(mode==='rich'?rich.innerHTML:source.value).trim();
   if(!body.value || !body.value.replace(/<[^>]*>/g,'').trim() && !/<img\b/i.test(body.value)){e.preventDefault();alert('Please write your email before continuing.');return;}
   if(document.getElementById('email-action').value==='save_launch'){
     if(!confirm('Send this email to ALL confirmed subscribers? This cannot be undone.'))e.preventDefault();
   }
 });
})();
</script>
<?php if(!$draft): ?><p class="muted">This campaign is <?=dd_e($c['status'])?> and can no longer be edited. Create a new email to make changes.</p><?php endif; ?></div>
<?php endif; ?>
<?php else: ?><h1>Page not found</h1><?php endif; ?></main>
<?php endif; ?></body></html>
