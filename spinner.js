function showSpinner() {
  var s = document.getElementById('spinner');
  if (s) s.style.display = 'flex';
}
function hideSpinner() {
  var s = document.getElementById('spinner');
  if (s) s.style.display = 'none';
}
function updateSpinner(progress, message) {
  var bar = document.getElementById('progress-bar');
  var text = document.getElementById('progress-text');
  if (bar) bar.style.width = progress + '%';
  if (text) text.textContent = message || '';
}
document.addEventListener('DOMContentLoaded', hideSpinner);

window.showSpinner = showSpinner;
window.hideSpinner = hideSpinner;
window.updateSpinner = updateSpinner;
