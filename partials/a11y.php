<?php
/**
 * Accessibility / ease-of-use widget loader.
 * Include once before </body> on public and panel pages.
 */
$a11yTr = function_exists('__') ? [
    'title' => __('a11y_ease'),
    'open' => __('a11y_open'),
    'close' => __('a11y_close'),
    'text_size' => __('a11y_text_size'),
    'bigger' => __('a11y_bigger'),
    'smaller' => __('a11y_smaller'),
    'reset_size' => __('a11y_reset_size'),
    'contrast' => __('a11y_contrast'),
    'dark' => __('a11y_dark'),
    'motion' => __('a11y_motion'),
    'reset' => __('a11y_reset'),
    'skip' => __('a11y_skip'),
] : [];
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/a11y.css')) ?>">
<script>
(function(){try{
  var r=document.documentElement,z=[1,1.15,1.3,1.5];
  var i=parseInt(localStorage.getItem('a11y_zoom')||'0',10);if(isNaN(i)||i<0)i=0;if(i>=z.length)i=z.length-1;
  if(z[i]!==1)r.style.zoom=String(z[i]);
  if(localStorage.getItem('a11y_contrast')==='1')r.classList.add('a11y-contrast');
  if(localStorage.getItem('a11y_motion')==='1')r.classList.add('a11y-reduce-motion');
}catch(e){}})();
window.A11Y_I18N = <?= json_encode($a11yTr, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= h(asset_url('assets/js/a11y.js')) ?>" defer></script>
