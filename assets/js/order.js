(function () {
  'use strict';

  function vipMultiplier() {
    var form = document.getElementById('order-form');
    var pct = form ? parseFloat(form.dataset.vipDiscount || '0') : 0;
    return 1 - (pct / 100);
  }

  function retailRate(rate, markup) {
    return rate * (1 + markup / 100) * vipMultiplier();
  }

  function updateDesc() {
    var sel = document.getElementById('service-select');
    var descEl = document.getElementById('desc-content');
    var rateEl = document.getElementById('rate-display');
    var qtyHint = document.getElementById('qty-hint');
    var orderQty = document.getElementById('order-qty');
    if (!sel || !descEl || !rateEl || !qtyHint || !orderQty || sel.disabled) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
      descEl.textContent = 'Select a service to see details.';
      rateEl.textContent = '—';
      qtyHint.textContent = '—';
      return;
    }
    var rate = parseFloat(opt.dataset.rate) || 0;
    var min = parseInt(opt.dataset.min, 10) || 0;
    var max = parseInt(opt.dataset.max, 10) || 0;
    var markup = parseFloat(opt.dataset.markup) || 0;
    var markupRate = retailRate(rate, markup);
    var refill = opt.dataset.refill || 'No';
    rateEl.textContent = '$' + markupRate.toFixed(5);
    qtyHint.textContent = 'Min: ' + min.toLocaleString() + ' — Max: ' + max.toLocaleString();
    orderQty.min = min;
    orderQty.max = max;
    orderQty.placeholder = 'Min: ' + min.toLocaleString() + ' — Max: ' + max.toLocaleString();
    var cat = (opt.dataset.category || '').toLowerCase();
    var exampleLinks = cat.indexOf('youtube') !== -1
      ? 'https://www.youtube.com/watch?v=xxxxxxx\nhttps://youtu.be/xxxxxx'
      : cat.indexOf('instagram') !== -1
      ? 'https://www.instagram.com/username/\nhttps://www.instagram.com/p/xxxxx/'
      : 'https://example.com/your-link';
    descEl.innerHTML = '<div class="desc-item"><strong>Quality:</strong> High Quality</div>' +
      '<div class="desc-item"><strong>Start:</strong> 0-6 Hours</div>' +
      '<div class="desc-item"><strong>Speed:</strong> Up to service limit</div>' +
      '<div class="desc-item"><strong>Refill:</strong> ' + (refill === 'Yes' ? 'Yes' : 'No') + '</div>' +
      '<div class="desc-item"><strong>Min:</strong> ' + min.toLocaleString() + ' — <strong>Max:</strong> ' + max.toLocaleString() + '</div>' +
      '<div class="desc-item"><strong>Rate per 1000:</strong> $' + markupRate.toFixed(5) + '</div>' +
      '<div style="margin-top:12px;"><strong style="color:var(--text);">Example links:</strong><pre style="font-size:11px;background:var(--bg);padding:10px;border-radius:8px;margin-top:6px;white-space:pre-wrap;word-break:break-all;">' + exampleLinks.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre></div>' +
      '<div class="desc-item" style="margin-top:8px;">Possible engagements depend on the service.</div>' +
      '<ul class="desc-notes" style="list-style:none;padding:0;margin:0;">' +
      '<li><span class="asterisk">*</span> Content must be PUBLIC and open for all countries.</li>' +
      '<li><span class="asterisk">*</span> Check the link format before placing the order.</li>' +
      '<li><span class="asterisk">*</span> Do not change your link or username after ordering.</li></ul>';
    calcPrice();
  }

  function applyServiceFilters() {
    var check = document.getElementById('newOnlyCheck');
    var input = document.getElementById('service-filter');
    var sel = document.getElementById('service-select');
    var meta = document.getElementById('service-filter-meta');
    if (!sel || sel.disabled) return;
    var q = input ? input.value.trim().toLowerCase() : '';
    var cutoff = (Date.now() / 1000) - (7 * 24 * 60 * 60);
    var visible = 0;
    for (var i = 1; i < sel.options.length; i++) {
      var opt = sel.options[i];
      var updated = parseInt(opt.dataset.updated || 0, 10);
      var tooOld = check && check.checked && updated < cutoff;
      var match = q === '' || opt.text.toLowerCase().indexOf(q) !== -1;
      opt.hidden = tooOld || !match;
      if (!opt.hidden) visible++;
    }
    if (meta) meta.textContent = visible + ' matching services';
  }

  function calcPrice() {
    var sel = document.getElementById('service-select');
    var priceEl = document.getElementById('price-display');
    var orderQty = document.getElementById('order-qty');
    if (!sel || !priceEl || !orderQty || sel.disabled) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { priceEl.textContent = '$0.0000'; return; }
    var rate = parseFloat(opt.dataset.rate) || 0;
    var markup = parseFloat(opt.dataset.markup) || 0;
    var markupRate = retailRate(rate, markup);
    var qty = parseFloat(orderQty.value, 10) || 0;
    priceEl.textContent = '$' + ((qty / 1000) * markupRate).toFixed(4);
  }

  window.updateDesc = updateDesc;
  window.filterNew = applyServiceFilters;
  window.calcPrice = calcPrice;

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('order-form');
    var preselect = form ? parseInt(form.dataset.preselectService || '0', 10) : 0;
    if (preselect) {
      var sel = document.getElementById('service-select');
      if (sel && !sel.disabled && sel.querySelector('option[value="' + preselect + '"]')) {
        sel.value = String(preselect);
        updateDesc();
      }
    }
    var input = document.getElementById('service-filter');
    if (input) input.addEventListener('input', applyServiceFilters);
    applyServiceFilters();
  });
})();
