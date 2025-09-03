(function (global) {
  function showSpinner(message) {
    const s = document.getElementById('spinner');
    if (s) {
      s.style.display = 'flex';
      updateSpinner(0, message || '翻訳実行中…');
    }
  }

  function hideSpinner() {
    const s = document.getElementById('spinner');
    if (s) s.style.display = 'none';
  }

  function updateSpinner(progress, message) {
    const bar = document.getElementById('progress-bar');
    const text = document.getElementById('progress-text');
    if (bar) bar.style.width = progress + '%';
    if (text) {
      const msg = message || '';
      text.textContent = msg + (msg ? ' ' : '') + progress + '%';
    }
  }

  document.addEventListener('DOMContentLoaded', hideSpinner);

  global.showSpinner = showSpinner;
  global.updateSpinner = updateSpinner;
  global.hideSpinner = hideSpinner;
})(window);

