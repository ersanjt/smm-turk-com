<?php
/**
 * Admin sub-page header with back link.
 * Requires: $pageTitle. Optional: $pageSubtitle, $backUrl, $backLabel, $pageHeaderActions
 */
$backUrl = $backUrl ?? admin_path('index.php');
$backLabel = $backLabel ?? 'Admin Panel';
?>
<div class="admin-page-top">
  <div class="admin-page-top-row">
    <a href="<?= h($backUrl) ?>" class="admin-back-link" aria-label="<?= h($backLabel) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
      <span><?= h($backLabel) ?></span>
    </a>
    <?php if (!empty($pageHeaderActions)): ?>
    <div class="admin-page-top-actions"><?= $pageHeaderActions ?></div>
    <?php endif; ?>
  </div>
  <div class="page-header admin-page-header">
    <div>
      <h1 class="page-title"><?= h($pageTitle) ?></h1>
      <?php if (!empty($pageSubtitle)): ?>
      <p class="page-subtitle"><?= h($pageSubtitle) ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>
