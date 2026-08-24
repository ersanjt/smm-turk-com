(function () {
  var input = document.getElementById('mass-orders-input');
  var counter = document.getElementById('mass-line-count');
  var placeBtn = document.getElementById('mass-place-btn');
  var form = document.getElementById('mass-order-form');

  if (!input || !counter) {
    return;
  }

  function countLines() {
    var text = input.value || '';
    var n = 0;
    text.split(/\r\n|\r|\n/).forEach(function (line) {
      line = line.trim();
      if (line !== '' && line.charAt(0) !== '#') {
        n += 1;
      }
    });
    return n;
  }

  function refresh() {
    var n = countLines();
    counter.textContent = String(n);
    if (placeBtn) {
      placeBtn.disabled = n === 0 || placeBtn.hasAttribute('data-no-balance');
    }
  }

  input.addEventListener('input', refresh);
  refresh();

  if (form && placeBtn) {
    form.addEventListener('submit', function (e) {
      var submitter = e.submitter;
      if (!submitter || submitter.value !== 'place') {
        return;
      }
      if (countLines() === 0) {
        e.preventDefault();
        return;
      }
      if (!window.confirm('Place ' + countLines() + ' order(s)? Balance will be charged for each successful line.')) {
        e.preventDefault();
      }
    });
  }
})();
