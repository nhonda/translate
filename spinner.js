function showSpinner() {
  var s = document.getElementById('spinner');
  if (s) s.style.display = 'flex';
}
function hideSpinner() {
  var s = document.getElementById('spinner');
  if (s) s.style.display = 'none';
}
document.addEventListener('DOMContentLoaded', hideSpinner);
