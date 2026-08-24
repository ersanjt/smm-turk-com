(function () {
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', function () {
    var swPath = document.body.getAttribute('data-sw');
    var scope = document.body.getAttribute('data-sw-scope') || '/';
    if (!swPath) return;
    navigator.serviceWorker.register(swPath, { scope: scope }).catch(function () {});
  });
})();
