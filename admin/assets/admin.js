document.addEventListener('DOMContentLoaded', () => {
  const password = document.getElementById('pw');
  const toggle = document.getElementById('toggle-password');
  if (!password || !toggle) return;

  toggle.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    toggle.textContent = show ? 'Hide' : 'Show';
    toggle.setAttribute('aria-pressed', String(show));
  });
});
