<?php
/**
 * Public newsletter signup block for the blog. Progressive enhancement:
 * works as a plain POST form, upgraded to inline AJAX feedback via blog.js.
 */
$subStatus = isset($_GET['sub']) ? (string) $_GET['sub'] : '';
?>
<section class="blog-newsletter" id="newsletter" aria-labelledby="newsletter-title">
  <div class="blog-newsletter-inner">
    <div class="blog-newsletter-copy">
      <h3 id="newsletter-title"><?= h(__('newsletter_title')) ?></h3>
      <p><?= h(__('newsletter_desc')) ?></p>
    </div>
    <form class="blog-newsletter-form" method="post" action="<?= h(path('newsletter-subscribe.php')) ?>" data-newsletter>
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ajax" value="1">
      <input type="email" name="email" required maxlength="190" placeholder="<?= h(__('newsletter_ph')) ?>" aria-label="<?= h(__('newsletter_ph')) ?>" autocomplete="email">
      <button type="submit"><?= h(__('newsletter_btn')) ?></button>
    </form>
    <p class="blog-newsletter-msg<?= $subStatus === 'ok' ? ' ok' : ($subStatus === 'err' ? ' err' : '') ?>" data-newsletter-msg role="status" aria-live="polite">
      <?php if ($subStatus === 'ok'): ?><?= h(__('newsletter_ok')) ?><?php elseif ($subStatus === 'err'): ?><?= h(__('newsletter_err')) ?><?php endif; ?>
    </p>
  </div>
</section>
<script>
(function () {
  var form = document.querySelector('form[data-newsletter]');
  if (!form || form.dataset.bound) return;
  form.dataset.bound = '1';
  var msg = document.querySelector('[data-newsletter-msg]');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('button');
    var orig = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; }
    fetch(form.action, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new FormData(form)
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok && d.ok, d: d }; }); })
      .then(function (res) {
        if (msg) {
          msg.textContent = res.d.message || '';
          msg.classList.remove('ok', 'err');
          msg.classList.add(res.ok ? 'ok' : 'err');
        }
        if (res.ok) { form.reset(); }
      })
      .catch(function () {
        if (msg) { msg.classList.remove('ok'); msg.classList.add('err'); }
      })
      .finally(function () { if (btn) { btn.disabled = false; btn.textContent = orig; } });
  });
})();
</script>
