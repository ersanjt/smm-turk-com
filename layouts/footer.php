    </div><!-- end content-inner -->
  </div><!-- end content -->
  <footer class="footer">
    <div class="footer-inner">
      <span class="footer-copy">© <?= date('Y') ?> <?= h(function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk')) ?>. All Rights Reserved.</span>
      <div class="footer-links">
      <?php
      $contactEmail = class_exists('Database') ? (Database::getInstance()->getSetting('contact_email') ?: '') : '';
      if ($contactEmail): ?>
      <a href="mailto:<?= h($contactEmail) ?>" class="footer-contact"><?= h($contactEmail) ?></a>
      <span class="footer-or">OR</span>
      <?php endif; ?>
      <a href="<?= h(path('tickets.php')) ?>" class="footer-ticket">Open a ticket</a>
      </div>
    </div>
  </footer>
  <!-- Mobile bottom navigation (visible only on small screens) -->
  <nav class="mob-bottom-nav" aria-label="Main navigation">
    <a href="<?= h(dashboard_path()) ?>" class="<?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>"><span class="mob-nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></span><span>Order</span></a>
    <a href="<?= h(path('orders.php')) ?>" class="<?= ($currentPage ?? '') === 'orders' ? 'active' : '' ?>"><span class="mob-nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span><span>Orders</span></a>
    <a href="<?= h(path('add-funds.php')) ?>" class="<?= ($currentPage ?? '') === 'add-funds' ? 'active' : '' ?>"><span class="mob-nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg></span><span>Funds</span></a>
    <a href="<?= h(path('tickets.php')) ?>" class="<?= ($currentPage ?? '') === 'tickets' ? 'active' : '' ?>"><span class="mob-nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span><span>Tickets</span></a>
    <button type="button" id="mobNavMenuBtn" aria-label="Open menu"><span class="mob-nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></span><span>Menu</span></button>
  </nav>
</main><!-- end main -->

<script src="<?= h(asset_url('assets/js/app.js')) ?>" defer></script>
<script src="<?= h(asset_url('assets/js/pwa.js')) ?>" defer></script>
<?php require __DIR__ . '/../partials/a11y.php'; ?>
</body>
</html>
