<script>
(function () {
  try {
    var q = new URLSearchParams(window.location.search).get('mode');
    var light = q === 'light' || q === 'light-mode' || ((q !== 'dark' && q !== 'dark-mode') && window.self !== window.top);
    if (!light || !document.body) {
      return;
    }
    document.body.classList.remove('dark-mode', 'accent-warning');
    document.body.classList.add('light-mode');
  } catch (err) {
    /* ignore */
  }
})();
</script>
