<?php
require_once __DIR__ . '/app/init.php';
$auth->requireLogin();
if (is_child_panel()) {
    redirect(dashboard_path());
}
$pageTitle = 'API Documentation';
$pageDescription = 'SMM Turk reseller API — place orders, check status, refill, cancel, and balance via JSON POST.';
$user = $auth->getCurrentUser();
$apiUrl = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/api/v2';
$apiKey = (string)($user['api_key'] ?? '');

/**
 * @param array<string, string> $fields
 */
function api_curl_example(string $apiUrl, array $fields): string
{
    $fields = array_merge(['key' => 'YOUR_API_KEY'], $fields);
    $lines = [
        "curl -X POST '" . $apiUrl . "' \\",
        "  -H 'Content-Type: application/x-www-form-urlencoded' \\",
    ];
    $keys = array_keys($fields);
    foreach ($keys as $i => $name) {
        $value = str_replace("'", "'\\''", (string)$fields[$name]);
        $suffix = $i < count($keys) - 1 ? ' \\' : '';
        $lines[] = "  -d '" . $name . '=' . $value . "'" . $suffix;
    }
    return implode("\n", $lines);
}

$extraCssHref = asset_url('assets/css/api-page.css');
require_once __DIR__ . '/layouts/header.php';
?>
<div class="api-hero card">
  <div class="api-hero-main">
    <div class="api-hero-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
    </div>
    <div>
      <h1 class="api-hero-title">Reseller API</h1>
      <p class="api-hero-sub">Integrate SMM Turk into your panel, bot, or website. All requests use <strong>POST</strong> with form fields or <code>X-API-Key</code> header. Responses are JSON.</p>
      <div class="api-meta-grid">
        <div class="api-meta-item"><span>HTTP method</span><strong>POST</strong></div>
        <div class="api-meta-item"><span>Endpoint</span><strong><?= h($apiUrl) ?></strong></div>
        <div class="api-meta-item"><span>Format</span><strong>JSON</strong></div>
        <div class="api-meta-item"><span>Rate limit</span><strong>120 / min</strong></div>
      </div>
    </div>
  </div>
  <div class="api-key-box" style="flex: 1; min-width: 260px; max-width: 420px;">
    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Your API key</span>
    <div class="api-key-row">
      <code class="api-key-value" id="apiKeyValue"><?= h($apiKey !== '' ? $apiKey : 'N/A') ?></code>
      <?php if ($apiKey !== ''): ?>
      <button type="button" class="btn btn-primary btn-sm api-copy-btn" data-copy="<?= h($apiKey) ?>">Copy</button>
      <?php endif; ?>
    </div>
    <p style="font-size: 12px; color: var(--text-muted); margin: 10px 0 0; line-height: 1.5;">
      Manage or regenerate on <a href="<?= h(route_path('account-settings.php')) ?>">Account Settings</a>.
      Never send the key in URL query strings — use POST body or <code>X-API-Key</code> header only.
    </p>
  </div>
</div>

<nav class="api-nav" aria-label="API sections">
  <a href="#services">Services</a>
  <a href="#add">Add order</a>
  <a href="#status">Status</a>
  <a href="#refill">Refill</a>
  <a href="#refill-status">Refill status</a>
  <a href="#cancel">Cancel</a>
  <a href="#balance">Balance</a>
  <a href="#errors">Errors</a>
</nav>

<div class="api-layout">
  <div class="api-main">

    <section class="card api-endpoint" id="services">
      <div class="api-endpoint-head">
        <h2>Service list</h2>
        <span class="api-action-badge">action=services</span>
      </div>
      <table class="api-params">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>key</code></td><td>Your API key</td></tr>
          <tr><td><code>action</code></td><td><code>services</code></td></tr>
        </tbody>
      </table>
      <p class="api-example-label">Example response</p>
      <pre class="api-example">[
  { "service": 1, "name": "Followers", "type": "Default", "category": "Instagram", "rate": "0.90000", "min": "50", "max": "10000", "refill": true, "cancel": true }
]</pre>
      <p class="api-example-label">cURL</p>
      <pre class="api-example"><?= h(api_curl_example($apiUrl, ['action' => 'services'])) ?></pre>
    </section>

    <section class="card api-endpoint" id="add">
      <div class="api-endpoint-head">
        <h2>Add order</h2>
        <span class="api-action-badge">action=add</span>
      </div>
      <table class="api-params">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>key</code></td><td>Your API key</td></tr>
          <tr><td><code>action</code></td><td><code>add</code></td></tr>
          <tr><td><code>service</code></td><td>Service ID from the service list</td></tr>
          <tr><td><code>link</code></td><td>Target URL (profile, post, video, etc.)</td></tr>
          <tr><td><code>quantity</code></td><td>Amount to order (within service min/max)</td></tr>
          <tr><td><code>runs</code> <span class="api-opt">optional</span></td><td>Number of delivery runs</td></tr>
          <tr><td><code>interval</code> <span class="api-opt">optional</span></td><td>Minutes between runs</td></tr>
        </tbody>
      </table>
      <p class="api-example-label">Example response</p>
      <pre class="api-example">{ "order": 23501 }</pre>
      <p class="api-example-label">cURL</p>
      <pre class="api-example"><?= h(api_curl_example($apiUrl, [
          'action' => 'add',
          'service' => '1',
          'link' => 'https://instagram.com/username',
          'quantity' => '1000',
      ])) ?></pre>
    </section>

    <section class="card api-endpoint" id="status">
      <div class="api-endpoint-head">
        <h2>Order status</h2>
        <span class="api-action-badge">action=status</span>
      </div>
      <table class="api-params">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>key</code></td><td>Your API key</td></tr>
          <tr><td><code>action</code></td><td><code>status</code></td></tr>
          <tr><td><code>order</code></td><td>Single order ID</td></tr>
          <tr><td><code>orders</code> <span class="api-opt">or</span></td><td>Comma-separated order IDs (max 100)</td></tr>
        </tbody>
      </table>
      <p class="api-example-label">Single order response</p>
      <pre class="api-example">{ "charge": "0.27819", "start_count": "3572", "status": "Partial", "remains": "157", "currency": "USD" }</pre>
      <p class="api-example-label">Multiple orders response</p>
      <pre class="api-example">{
  "1": { "charge": "0.27819", "start_count": "3572", "status": "Partial", "remains": "157", "currency": "USD" },
  "10": { "error": "Incorrect order ID" }
}</pre>
      <p class="api-example-label">cURL (single)</p>
      <pre class="api-example"><?= h(api_curl_example($apiUrl, ['action' => 'status', 'order' => '23501'])) ?></pre>
    </section>

    <section class="card api-endpoint" id="refill">
      <div class="api-endpoint-head">
        <h2>Create refill</h2>
        <span class="api-action-badge">action=refill</span>
      </div>
      <table class="api-params">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>key</code></td><td>Your API key</td></tr>
          <tr><td><code>action</code></td><td><code>refill</code></td></tr>
          <tr><td><code>order</code></td><td>Single order ID</td></tr>
          <tr><td><code>orders</code> <span class="api-opt">or</span></td><td>Comma-separated order IDs (max 100)</td></tr>
        </tbody>
      </table>
      <p class="api-example-label">Example response</p>
      <pre class="api-example">{ "refill": "1" }</pre>
      <p class="api-example-label">cURL</p>
      <pre class="api-example"><?= h(api_curl_example($apiUrl, ['action' => 'refill', 'order' => '23501'])) ?></pre>
    </section>

    <section class="card api-endpoint" id="refill-status">
      <div class="api-endpoint-head">
        <h2>Refill status</h2>
        <span class="api-action-badge">action=refill_status</span>
      </div>
      <table class="api-params">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>key</code></td><td>Your API key</td></tr>
          <tr><td><code>action</code></td><td><code>refill_status</code></td></tr>
          <tr><td><code>refill</code></td><td>Single refill ID</td></tr>
          <tr><td><code>refills</code> <span class="api-opt">or</span></td><td>Comma-separated refill IDs (max 100)</td></tr>
        </tbody>
      </table>
      <p class="api-example-label">Example response</p>
      <pre class="api-example">{ "status": "Completed" }</pre>
      <p class="api-example-label">cURL</p>
      <pre class="api-example"><?= h(api_curl_example($apiUrl, ['action' => 'refill_status', 'refill' => '1'])) ?></pre>
    </section>

    <section class="card api-endpoint" id="cancel">
      <div class="api-endpoint-head">
        <h2>Cancel orders</h2>
        <span class="api-action-badge">action=cancel</span>
      </div>
      <p class="api-note"><strong>Note:</strong> Only orders with status <strong>Pending</strong> can be cancelled. Balance is refunded automatically on success.</p>
      <table class="api-params">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>key</code></td><td>Your API key</td></tr>
          <tr><td><code>action</code></td><td><code>cancel</code></td></tr>
          <tr><td><code>orders</code></td><td>Comma-separated order IDs (max 100)</td></tr>
        </tbody>
      </table>
      <p class="api-example-label">Example response</p>
      <pre class="api-example">[
  { "order": 9, "cancel": { "error": "Cannot cancel" } },
  { "order": 2, "cancel": 1 }
]</pre>
      <p class="api-example-label">cURL</p>
      <pre class="api-example"><?= h(api_curl_example($apiUrl, ['action' => 'cancel', 'orders' => '23501,23502'])) ?></pre>
    </section>

    <section class="card api-endpoint" id="balance">
      <div class="api-endpoint-head">
        <h2>User balance</h2>
        <span class="api-action-badge">action=balance</span>
      </div>
      <table class="api-params">
        <thead><tr><th>Parameter</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td><code>key</code></td><td>Your API key</td></tr>
          <tr><td><code>action</code></td><td><code>balance</code></td></tr>
        </tbody>
      </table>
      <p class="api-example-label">Example response</p>
      <pre class="api-example">{ "balance": "100.84292", "currency": "USD" }</pre>
      <p class="api-example-label">cURL</p>
      <pre class="api-example"><?= h(api_curl_example($apiUrl, ['action' => 'balance'])) ?></pre>
    </section>

    <section class="card api-endpoint" id="errors">
      <div class="api-endpoint-head">
        <h2>HTTP status codes</h2>
      </div>
      <table class="api-params">
        <thead><tr><th>Code</th><th>Meaning</th></tr></thead>
        <tbody>
          <tr><td><code>400</code></td><td>Missing or invalid parameters, unknown action</td></tr>
          <tr><td><code>402</code></td><td>Insufficient balance (add order)</td></tr>
          <tr><td><code>403</code></td><td>Invalid or inactive API key</td></tr>
          <tr><td><code>404</code></td><td>Order or refill not found</td></tr>
          <tr><td><code>405</code></td><td>Method not allowed — use POST</td></tr>
          <tr><td><code>429</code></td><td>Rate limit exceeded (120 requests per minute)</td></tr>
          <tr><td><code>503</code></td><td>Provider temporarily unavailable</td></tr>
        </tbody>
      </table>
      <p class="api-note" style="margin-top: 12px;">
        Alternative auth: send <code>X-API-Key: YOUR_API_KEY</code> header instead of the <code>key</code> field.
        All error bodies include an <code>error</code> string, e.g. <code>{ "error": "Invalid API key" }</code>.
      </p>
    </section>

  </div>

  <aside class="api-side-card card">
    <div class="card-title">Quick tips</div>
    <ul class="api-side-list">
      <li>Prices in <code>services</code> include your account markup.</li>
      <li>Order IDs returned by <code>add</code> are panel order IDs, not provider IDs.</li>
      <li>Use <code>status</code> with <code>orders</code> for bulk checks (up to 100).</li>
      <li>Cancel refunds balance only while status is Pending.</li>
      <li>Need a new key? <a href="<?= h(route_path('account-settings.php')) ?>">Account Settings</a></li>
    </ul>
  </aside>
</div>

<script>
(function () {
  document.querySelectorAll('.api-copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy') || '';
      if (!text || !navigator.clipboard) return;
      navigator.clipboard.writeText(text).then(function () {
        var prev = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = prev; }, 2000);
      });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
