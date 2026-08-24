(function () {
  'use strict';

  var form = document.getElementById('addFundsForm');
  if (!form) return;

  var amountInput = document.getElementById('amountInput');
  var afterBalance = document.getElementById('afterBalance');
  var methodDetail = document.getElementById('methodDetail');
  var submitBtn = document.getElementById('addFundsSubmit');
  var minDeposit = parseFloat(form.dataset.minDeposit || '10') || 10;
  var currentBalance = parseFloat(form.dataset.currentBalance || '0') || 0;
  var zarinRate = parseFloat(form.dataset.zarinRate || '0') || 0;

  function selectedMethod() {
    var checked = form.querySelector('input[name="method"]:checked');
    return checked ? checked.value : '';
  }

  function updateBalancePreview() {
    if (!amountInput || !afterBalance) return;
    var amt = parseFloat(amountInput.value) || 0;
    afterBalance.textContent = '$' + (currentBalance + amt).toFixed(3);
  }

  function updateMethodDetail() {
    if (!methodDetail) return;
    var slug = selectedMethod();
    var card = form.querySelector('.coin-card[data-method="' + slug + '"]');
    if (!card) {
      methodDetail.hidden = true;
      return;
    }
    var label = card.dataset.label || '';
    var badge = card.dataset.badge || '';
    var type = card.dataset.type || '';
    var html = '<strong>' + label + '</strong>';
    if (badge) html += ' <span class="af-detail-badge">' + badge + '</span>';
    if (type === 'manual') {
      html += '<p>Send crypto to our wallet address, then paste your TxHash. Balance updates after on-chain verification (usually 1–15 min).</p>';
    } else if (slug === 'heleket') {
      html += '<p>Pay with crypto via Heleket. You get a unique address + QR; balance updates automatically when confirmed.</p>';
    } else if (slug === 'zarinpal' && zarinRate > 0 && amountInput) {
      var irr = Math.round((parseFloat(amountInput.value) || 0) * zarinRate);
      html += '<p>Redirect to ZarinPal checkout. Approx. <strong>' + irr.toLocaleString() + ' IRR</strong> at current rate.</p>';
    } else if (type === 'redirect') {
      html += '<p>You will be redirected to a secure checkout page. Balance credits automatically after payment.</p>';
    }
    methodDetail.innerHTML = html;
    methodDetail.hidden = false;
  }

  function validateForm() {
    if (!submitBtn || !amountInput) return;
    var amt = parseFloat(amountInput.value) || 0;
    var ok = selectedMethod() !== '' && amt >= minDeposit;
    submitBtn.disabled = !ok;
    submitBtn.classList.toggle('af-submit-ready', ok);
  }

  form.querySelectorAll('.coin-card').forEach(function (card) {
    card.addEventListener('click', function () {
      var radio = card.querySelector('input[type="radio"]');
      if (radio) {
        radio.checked = true;
        form.querySelectorAll('.coin-card').forEach(function (c) { c.classList.remove('selected'); });
        card.classList.add('selected');
        updateMethodDetail();
        validateForm();
      }
    });
  });

  document.querySelectorAll('.preset-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!amountInput) return;
      amountInput.value = this.dataset.amount;
      document.querySelectorAll('.preset-btn').forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
      updateBalancePreview();
      updateMethodDetail();
      validateForm();
    });
  });

  if (amountInput) {
    amountInput.addEventListener('input', function () {
      document.querySelectorAll('.preset-btn').forEach(function (b) { b.classList.remove('active'); });
      updateBalancePreview();
      updateMethodDetail();
      validateForm();
    });
  }

  var initial = form.querySelector('.coin-card.selected input[type="radio"]');
  if (initial) initial.checked = true;
  updateBalancePreview();
  updateMethodDetail();
  validateForm();
})();

(function () {
  var copyBtn = document.getElementById('copyAddressBtn');
  var walletAddr = document.getElementById('walletAddress');
  var toast = document.getElementById('copyToast');
  if (copyBtn && walletAddr) {
    copyBtn.addEventListener('click', function () {
      var text = walletAddr.textContent.trim();
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
        if (toast) {
          toast.classList.add('show');
          setTimeout(function () { toast.classList.remove('show'); }, 2000);
        }
      });
    });
  }

  var qrEl = document.getElementById('walletQr');
  if (qrEl && walletAddr && typeof QRCode !== 'undefined') {
    var addr = walletAddr.textContent.trim();
    if (addr) {
      qrEl.innerHTML = '';
      new QRCode(qrEl, {
        text: addr,
        width: 180,
        height: 180,
        colorDark: '#0f172a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }
  }

  var depositPoll = document.getElementById('depositLiveStatus');
  if (depositPoll) {
    var statusUrl = window.ADD_FUNDS_STATUS_URL || '';
    var balanceEl = document.getElementById('liveBalanceValue');
    function pollDeposit() {
      if (!statusUrl) return;
      fetch(statusUrl, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
        if (!data.ok) return;
        var live = document.getElementById('depositLiveStatus');
        if (live) live.textContent = data.status || 'pending';
        if (balanceEl && typeof data.balance === 'number') {
          balanceEl.textContent = '$' + data.balance.toFixed(3);
        }
        if (data.approved || data.status === 'confirmed') {
          var card = document.getElementById('depositStatusCard');
          if (card) card.classList.add('deposit-confirmed');
          var title = document.getElementById('depositStatusTitle');
          if (title) title.textContent = 'Payment confirmed!';
          var msg = document.getElementById('depositStatusMsg');
          if (msg) msg.textContent = data.message || 'Your balance is ready.';
          var confirmed = document.getElementById('depositConfirmedActions');
          if (confirmed) confirmed.style.display = 'flex';
          return;
        }
        setTimeout(pollDeposit, 6000);
      }).catch(function () { setTimeout(pollDeposit, 10000); });
    }
    setTimeout(pollDeposit, 2500);
  }
})();
