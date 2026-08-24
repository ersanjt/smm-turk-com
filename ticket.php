<?php
require_once __DIR__ . '/app/init.php';
$auth->requireLogin();
$db  = Database::getInstance();
$uid = $auth->getUserId();

$id = (int)($_GET['id'] ?? 0);
$ticket = $db->fetch("SELECT * FROM tickets WHERE id = ? AND user_id = ?", [$id, $uid]);
if (!$ticket) {
    flash('error', 'Ticket not found.');
    redirect(url('tickets.php'));
}

// Reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply']) && csrf_verify()) {
    $msg = trim($_POST['message'] ?? '');
    if ($msg !== '' && ($ticket['status'] === 'open' || $ticket['status'] === 'answered')) {
        $db->insert("INSERT INTO ticket_replies (ticket_id, user_id, message, is_staff) VALUES (?, ?, ?, 0)", [$id, $uid, $msg]);
        $db->execute("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?", [$id]);
        flash('success', 'Reply sent.');
        redirect(page_url('ticket.php', ['id' => (int) $id]));
    }
}

$replies = $db->fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC", [$id]);
$attachments = [];
try {
    $attachments = $db->fetchAll("SELECT * FROM ticket_attachments WHERE ticket_id = ? AND reply_id IS NULL", [$id]);
} catch (Throwable $e) { }

$pageTitle = 'Ticket #' . $id;
require_once __DIR__ . '/layouts/header.php';
?>

<div class="card" style="margin-bottom:20px;">
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:16px;">
    <span class="card-title" style="margin:0;">#<?= (int)$ticket['id'] ?> — <?= h($ticket['subject']) ?></span>
    <span class="badge <?= ($ticket['status'] ?? 'open') === 'answered' ? 'badge-green' : (($ticket['status'] ?? '') === 'closed' ? 'badge-gray' : 'badge-orange') ?>"><?= h(ucfirst($ticket['status'] ?? 'Open')) ?></span>
  </div>
  <p style="font-size:12px;color:var(--text-muted);">Created: <?= h(date('Y-m-d H:i', strtotime($ticket['created_at'] ?? $ticket['updated_at'] ?? 'now'))) ?></p>
</div>

<div class="card" style="margin-bottom:20px;">
  <?php foreach ($replies as $r): ?>
  <div class="ticket-reply<?= $r['is_staff'] ? ' ticket-reply-staff' : '' ?>">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">
      <?= $r['is_staff'] ? '🏷 Support' : '👤 You' ?> — <?= h(date('Y-m-d H:i', strtotime($r['created_at'] ?? 'now'))) ?>
    </div>
    <div style="white-space:pre-wrap;font-size:14px;"><?= h($r['message']) ?></div>
  </div>
  <?php endforeach; ?>
  <?php if (!empty($attachments)): ?>
  <div style="padding:14px 0;">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">Attachments</div>
    <?php foreach ($attachments as $a): ?>
    <a href="<?= h(path($a['file_path'])) ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin-right:12px;margin-bottom:8px;font-size:13px;">📎 <?= h($a['original_name']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (($ticket['status'] ?? '') !== 'closed'): ?>
<div class="card">
  <div class="card-title">Add reply</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="reply" value="1">
    <div class="form-group">
      <textarea name="message" class="form-control" rows="4" placeholder="Type your message…" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Send reply</button>
  </form>
</div>
<?php endif; ?>

<p style="margin-top:16px;"><a href="<?= h(path('tickets.php')) ?>">← Back to Tickets</a></p>

<style>
.ticket-reply { padding: 14px 0; border-bottom: 1px solid var(--border); }
.ticket-reply-staff { background: rgba(34,197,94,.1); margin: 0 -22px; padding: 14px 22px; border-bottom-color: rgba(34,197,94,.2); }
body.theme-dark .ticket-reply-staff { background: rgba(34,197,94,.14); }
</style>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
