<?php
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();

$id = (int)($_GET['id'] ?? 0);
$ticket = $db->fetch("SELECT t.*, u.username, u.email FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?", [$id]);
if (!$ticket) {
    flash('error', 'Ticket not found.');
    redirect(url('admin/admin-tickets.php'));
}

// Admin reply or close
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (isset($_POST['reply'])) {
        $msg = trim($_POST['message'] ?? '');
        if ($msg !== '') {
            $db->insert("INSERT INTO ticket_replies (ticket_id, user_id, message, is_staff) VALUES (?, ?, ?, 1)", [$id, $auth->getUserId(), $msg]);
            $db->execute("UPDATE tickets SET status = 'answered', updated_at = NOW() WHERE id = ?", [$id]);
            $mail = new Mail();
            $sent = $mail->sendTicketStaffReply(
                $ticket['email'],
                $ticket['username'],
                $id,
                $ticket['subject'],
                $msg
            );
            if ($sent) {
                flash('success', 'Reply sent and user notified by email.');
            } else {
                flash('success', 'Reply saved. Email notification failed — check Email settings.');
                Logger::log('Ticket #' . $id . ' reply email failed: ' . ($mail->getLastError() ?? 'unknown'), 'mail');
            }
            redirect(url('admin/admin-ticket.php') . '?id=' . (int)$id);
        }
    }
    if (isset($_POST['close'])) {
        $db->execute("UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = ?", [$id]);
        flash('success', 'Ticket closed.');
        redirect(url('admin/admin-ticket.php') . '?id=' . (int)$id);
    }
}

$replies = $db->fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC", [$id]);
$attachments = [];
try {
    $attachments = $db->fetchAll("SELECT * FROM ticket_attachments WHERE ticket_id = ? AND reply_id IS NULL", [$id]);
} catch (Throwable $e) { }

$pageTitle = 'Ticket #' . $id;
$backUrl = admin_path('admin-tickets.php');
$backLabel = 'All tickets';
$pageSubtitle = (string) ($ticket['subject'] ?? '');
require_once __DIR__ . '/../layouts/header.php';
?>

<div class="card" style="margin-bottom:20px;">
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:8px;">
    <span class="card-title" style="margin:0;">#<?= (int)$ticket['id'] ?> — <?= h($ticket['subject']) ?></span>
    <span class="badge <?= ($ticket['status'] ?? 'open') === 'answered' ? 'badge-green' : (($ticket['status'] ?? '') === 'closed' ? 'badge-gray' : 'badge-orange') ?>"><?= h(ucfirst($ticket['status'] ?? 'Open')) ?></span>
  </div>
  <p style="font-size:13px;color:var(--text-muted);">User: <strong><?= h($ticket['username']) ?></strong> (<?= h($ticket['email']) ?>) — Created: <?= h(date('Y-m-d H:i', strtotime($ticket['created_at'] ?? $ticket['updated_at'] ?? 'now'))) ?></p>
</div>

<div class="card" style="margin-bottom:20px;">
  <?php foreach ($replies as $r): ?>
  <div style="padding:14px 0;border-bottom:1px solid var(--border);<?= $r['is_staff'] ? ' background:#f0fdf4; margin:0 -22px; padding:14px 22px;' : '' ?>">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">
      <?= $r['is_staff'] ? '🏷 Support' : '👤 User' ?> — <?= h(date('Y-m-d H:i', strtotime($r['created_at'] ?? 'now'))) ?>
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
  <div class="card-title">Reply as support</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="reply" value="1">
    <div class="form-group">
      <textarea name="message" class="form-control" rows="4" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Send reply</button>
  </form>
  <form method="POST" style="margin-top:14px;" onsubmit="return confirm('Close this ticket?');">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="close" value="1">
    <button type="submit" class="btn btn-danger">Close ticket</button>
  </form>
</div>
<?php endif; ?>

<p style="margin-top:16px;"><a href="<?= h(path('admin/admin-tickets.php')) ?>">← Back to Tickets</a></p>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
