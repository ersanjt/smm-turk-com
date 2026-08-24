<?php
require_once __DIR__ . '/app/init.php';
$auth->requireLogin();
$pageTitle = 'Tickets';
$db  = Database::getInstance();
$uid = $auth->getUserId();

$categories = ['Order', 'Payments', 'Invoice', 'Child Panel', 'API', 'BUG', 'Redeem', 'Request', 'Other'];
$orderSubs  = ['Refill', 'Cancelation', 'Speed Up'];

$uploadDir = __DIR__ . '/uploads/tickets';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Handle new ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket']) && csrf_verify()) {
    $category    = trim($_POST['category'] ?? 'Other');
    $subcategory = trim($_POST['subcategory'] ?? '');
    $orderId     = trim($_POST['order_id'] ?? '');
    $message     = trim($_POST['message'] ?? '');

    if (!$message) {
        flash('error', 'Please enter your message.');
        redirect(url('tickets.php'));
    }

    $subject = $category . ($subcategory ? ' - ' . $subcategory : '');
    if ($orderId) $subject .= ' [Order: ' . mb_substr($orderId, 0, 100) . ']';

    $tid = $db->insert("INSERT INTO tickets (user_id, subject, status) VALUES (?, ?, 'open')", [$uid, $subject]);
    try {
        $db->execute("UPDATE tickets SET category = ?, subcategory = ?, order_id = ? WHERE id = ?", [$category, $subcategory, mb_substr($orderId, 0, 500), $tid]);
    } catch (Throwable $e) {
        /* optional columns may not exist */
    }

    $db->insert("INSERT INTO ticket_replies (ticket_id, user_id, message, is_staff) VALUES (?, ?, ?, 0)", [$tid, $uid, $message]);

    $user = $auth->getCurrentUser();
    $contactEmail = trim((string) ($db->getSetting('contact_email') ?? ''));
    if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $mail = new Mail();
        $mail->sendTicketNewToAdmin(
            $contactEmail,
            $user['username'] ?? 'User',
            $user['email'] ?? '',
            (int) $tid,
            $subject,
            $message
        );
    }

    // Attachments (extension allowlist + MIME validation)
    if (!empty($_FILES['attachments']['name'][0]) && is_dir($uploadDir)) {
        $ticketDir = $uploadDir . '/' . (int)$tid;
        @mkdir($ticketDir, 0755, true);
        $allowedExt = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']];
        $maxSize = 5 * 1024 * 1024; // 5MB
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        foreach ($_FILES['attachments']['name'] as $i => $name) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK || $_FILES['attachments']['size'][$i] > $maxSize) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset($allowedExt[$ext])) continue;
            $tmpPath = $_FILES['attachments']['tmp_name'][$i];
            $detectedMime = $finfo ? finfo_file($finfo, $tmpPath) : '';
            if (!in_array($detectedMime, $allowedExt[$ext], true)) continue;
            $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
            $path = $ticketDir . '/' . uniqid() . '_' . $safe;
            if (move_uploaded_file($tmpPath, $path)) {
                $rel = 'uploads/tickets/' . (int)$tid . '/' . basename($path);
                try {
                    $db->insert("INSERT INTO ticket_attachments (ticket_id, reply_id, file_path, original_name) VALUES (?, NULL, ?, ?)", [$tid, $rel, $name]);
                } catch (Throwable $e) {
                    // table might not exist
                }
            }
        }
        if ($finfo) finfo_close($finfo);
    }

    flash('success', 'Ticket #' . $tid . ' created. We will reply as soon as possible.');
        redirect(page_url('ticket.php', ['id' => (int) $tid]));
}

// List: search and pagination
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = "user_id = ?";
$params = [$uid];
if ($search !== '') {
    $where .= " AND (subject LIKE ? OR order_id LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$total = (int) $db->fetch("SELECT COUNT(*) c FROM tickets WHERE $where", $params)['c'];
$tickets = $db->fetchAll(
    "SELECT id, subject, status, updated_at FROM tickets WHERE $where ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset",
    $params
);

$totalPages = max(1, (int)ceil($total / $perPage));

require_once __DIR__ . '/layouts/header.php';
?>

<style>
.ticket-banner { display: flex; align-items: flex-start; gap: 12px; background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); color: #92400e; padding: 14px 18px; border-radius: 12px; margin-bottom: 24px; font-size: 13px; border: 1px solid #fde68a; box-shadow: 0 1px 3px rgba(245,158,11,.08); }
.ticket-banner-icon { flex-shrink: 0; width: 22px; height: 22px; margin-top: 1px; color: #d97706; }
.ticket-banner a { color: var(--primary); font-weight: 600; text-underline-offset: 2px; }
.ticket-card-title-wrap { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
.ticket-card-title-wrap .card-title { margin-bottom: 0; }
.ticket-card-title-wrap svg { width: 22px; height: 22px; color: var(--primary); flex-shrink: 0; }
.ticket-cats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.ticket-cats input { display: none; }
.ticket-cats label { display: inline-flex; align-items: center; padding: 10px 16px; border-radius: 10px; border: 2px solid var(--border); font-size: 12px; font-weight: 600; cursor: pointer; transition: border-color .2s, background .2s, color .2s; background: var(--white); color: var(--text); }
.ticket-cats label:hover { border-color: var(--primary-light); background: rgba(227,10,23,.06); color: var(--text); }
.ticket-cats input:checked + label { border-color: var(--primary); background: linear-gradient(180deg, rgba(227,10,23,.12) 0%, rgba(227,10,23,.06) 100%); color: var(--primary); box-shadow: 0 0 0 1px var(--primary); }
.ticket-subcats { display: none; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.ticket-subcats.visible { display: flex; }
.ticket-history-card { background: var(--white); border-radius: 16px; border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow); }
.ticket-history-card > .card-title { color: var(--text); }
.ticket-search-wrap { display: flex; gap: 10px; padding: 16px; border-bottom: 1px solid var(--border); background: var(--bg); }
.ticket-search-wrap input { flex: 1; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 13px; background: var(--white); color: var(--text); }
.ticket-search-wrap input::placeholder { color: var(--text-muted); opacity: .85; }
.ticket-search-wrap input:focus { border-color: var(--primary); outline: none; background: var(--white); }
.ticket-search-wrap button { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: var(--primary); color: #fff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 13px; transition: transform .2s, box-shadow .2s; }
.ticket-search-wrap button:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(227,10,23,.25); }
.ticket-search-wrap button svg { width: 16px; height: 16px; flex-shrink: 0; }
.ticket-item { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 16px 18px; border-bottom: 1px solid var(--border); color: var(--text); text-decoration: none; transition: background .15s; }
.ticket-item:hover { background: rgba(227,10,23,.06); }
.ticket-item:last-child { border-bottom: 0; }
.ticket-item-id { font-weight: 700; color: var(--text); flex: 1 1 100%; }
.ticket-item-meta { font-size: 12px; color: var(--text-muted); }
.ticket-item-status { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; margin-left: auto; }
.ticket-item-status.new, .ticket-item-status.open { background: #e0f2fe; color: #0369a1; }
.ticket-item-status.answered { background: #dcfce7; color: #166534; }
.ticket-item-status.closed { background: #f3f4f6; color: #6b7280; }
.ticket-pagination { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 16px; flex-wrap: wrap; }
.ticket-pagination a, .ticket-pagination span { padding: 8px 14px; border-radius: 10px; font-size: 13px; text-decoration: none; color: var(--text); border: 1.5px solid var(--border); transition: all .2s; background: var(--bg); }
.ticket-pagination a:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
.ticket-pagination .current { background: var(--primary); color: #fff; border-color: var(--primary); }
.attach-hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
.ticket-file-input { padding: 8px; border: 1px solid var(--border); border-radius: 10px; width: 100%; background: var(--white); color: var(--text); }

/* Dark mode */
body.theme-dark .ticket-banner {
  background: linear-gradient(135deg, rgba(245,158,11,.18) 0%, rgba(245,158,11,.08) 100%);
  border-color: rgba(251,191,36,.35);
  color: #fcd34d;
}
body.theme-dark .ticket-banner-icon { color: #fbbf24; }
body.theme-dark .ticket-cats label:hover {
  background: rgba(227,10,23,.14);
}
body.theme-dark .ticket-cats input:checked + label {
  background: linear-gradient(180deg, rgba(227,10,23,.22) 0%, rgba(227,10,23,.1) 100%);
  color: var(--primary-light);
}
body.theme-dark .ticket-history-card {
  box-shadow: 0 2px 16px rgba(0,0,0,.28);
}
body.theme-dark .ticket-item:hover {
  background: rgba(227,10,23,.1);
}
body.theme-dark .ticket-item-status.new,
body.theme-dark .ticket-item-status.open {
  background: rgba(56,189,248,.18);
  color: #7dd3fc;
}
body.theme-dark .ticket-item-status.answered {
  background: rgba(34,197,94,.18);
  color: #86efac;
}
body.theme-dark .ticket-item-status.closed {
  background: rgba(255,255,255,.08);
  color: var(--text-muted);
}
body.theme-dark .ticket-pagination a,
body.theme-dark .ticket-pagination span {
  background: var(--white);
  border-color: rgba(255,255,255,.1);
}
body.theme-dark .ticket-file-input {
  border-color: rgba(255,255,255,.12);
}
body.theme-dark .card .form-label {
  color: #c9b4b9;
}
</style>

<div class="ticket-banner">
  <span class="ticket-banner-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span>
  <span>To get a quicker response to your inquiries or issues, please refer to our <a href="<?= h(home_path()) ?>#faq">FAQs page</a> before submitting a ticket.</span>
</div>

<div class="grid2" style="align-items: start; gap: 24px;">
  <div class="card">
    <div class="card-title">New ticket</div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="submit_ticket" value="1">

      <div class="form-group">
        <label class="form-label">Category</label>
        <div class="ticket-cats">
          <?php foreach ($categories as $i => $cat): ?>
          <input type="radio" name="category" id="cat_<?= $i ?>" value="<?= h($cat) ?>" <?= $i === 0 ? 'checked' : '' ?>>
          <label for="cat_<?= $i ?>"><?= h($cat) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group ticket-subcats visible" id="orderSubcats">
        <label class="form-label">Type (for Order)</label>
        <div class="ticket-cats">
          <?php foreach ($orderSubs as $j => $sub): ?>
          <input type="radio" name="subcategory" id="sub_<?= $j ?>" value="<?= h($sub) ?>" <?= $j === 0 ? 'checked' : '' ?>>
          <label for="sub_<?= $j ?>"><?= h($sub) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Order ID</label>
        <input type="text" name="order_id" class="form-control" placeholder="ex. 2741705, 2741707815, 274170390" maxlength="500">
        <div class="form-label" style="margin-top:4px;font-weight:400;color:var(--text-muted);">(To receive a faster response, ensure that you assign the correct order IDs.)</div>
      </div>

      <div class="form-group">
        <label class="form-label">Message</label>
        <textarea name="message" class="form-control" rows="6" placeholder="ex. Hi there, I would like to check my second order status." required></textarea>
        <div class="form-label" style="margin-top:4px;font-weight:400;color:var(--text-muted);">How did you hear about our website? Please mention: Search engine, Social media, Referral, Forum, Other.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Attach files</label>
        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.doc,.docx" class="ticket-file-input">
        <div class="attach-hint">Max 5MB per file. Allowed: images, PDF, TXT, DOC.</div>
      </div>

      <button type="submit" class="btn btn-primary btn-block" style="padding:14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Submit ticket</button>
    </form>
  </div>

  <div class="ticket-history-card">
    <div class="card-title" style="margin:0;padding:18px 18px 0;">Tickets History</div>
    <form method="GET" class="ticket-search-wrap">
      <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search tickets…" aria-label="Search tickets">
      <button type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg> Search</button>
    </form>
    <?php if (empty($tickets)): ?>
    <div style="padding:40px 18px;text-align:center;color:var(--text-muted);">
      No tickets yet. Create one using the form on the left.
    </div>
    <?php else: ?>
    <?php foreach ($tickets as $t): ?>
    <a href="<?= h(path('ticket.php')) ?>?id=<?= (int)$t['id'] ?>" class="ticket-item">
      <span class="ticket-item-id"><?= (int)$t['id'] ?> — <?= h(mb_substr($t['subject'], 0, 60)) ?><?= mb_strlen($t['subject']) > 60 ? '…' : '' ?></span>
      <span class="ticket-item-meta"><?= h(date('Y-m-d h:i:s A', strtotime($t['updated_at'] ?? 'now'))) ?></span>
      <span class="ticket-item-status <?= ($t['status'] ?? 'open') === 'open' ? 'new' : h($t['status'] ?? 'open') ?>"><?= ($t['status'] ?? 'open') === 'open' ? 'New' : h(ucfirst($t['status'] ?? '')) ?></span>
    </a>
    <?php endforeach; ?>
    <div class="ticket-pagination">
      <?php if ($page > 1): ?>
      <a href="?p=1&q=<?= h(urlencode($search)) ?>">« First</a>
      <a href="?p=<?= $page - 1 ?>&q=<?= h(urlencode($search)) ?>">‹ Prev</a>
      <?php endif; ?>
      <span class="current"><?= $page ?></span>
      <?php if ($page < $totalPages): ?>
      <a href="?p=<?= $page + 1 ?>&q=<?= h(urlencode($search)) ?>">Next ›</a>
      <a href="?p=<?= $totalPages ?>&q=<?= h(urlencode($search)) ?>">Last »</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var orderCat = document.querySelector('input[name="category"][value="Order"]');
  var subBlock = document.getElementById('orderSubcats');
  if (!orderCat || !subBlock) return;
  function toggle() {
    subBlock.classList.toggle('visible', orderCat.checked);
  }
  document.querySelectorAll('input[name="category"]').forEach(function(r){
    r.addEventListener('change', toggle);
  });
  toggle();
})();
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
