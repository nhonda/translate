function showSpinner(message) {
  var s = document.getElementById('spinner');
  if (s) {
    s.style.display = 'flex';
    updateSpinner(0, message || '翻訳実行中…');
  }
}
function hideSpinner() {
  var s = document.getElementById('spinner');
  if (s) s.style.display = 'none';
}
function updateSpinner(progress, message) {
  var bar = document.getElementById('progress-bar');
  var text = document.getElementById('progress-text');
  if (bar) bar.style.width = progress + '%';
  if (text) {
    var msg = message || '';
    text.textContent = msg + (msg ? ' ' : '') + progress + '%';
  }
}
document.addEventListener('DOMContentLoaded', hideSpinner);

window.showSpinner = showSpinner;
window.hideSpinner = hideSpinner;
window.updateSpinner = updateSpinner;
