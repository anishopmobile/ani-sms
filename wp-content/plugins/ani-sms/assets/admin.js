(() => {
  'use strict';
  const root = document.querySelector('.ani-admin');
  if (!root) return;
  let dirty = false;
  root.querySelectorAll('form.ani-settings').forEach(form => {
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', event => {
      if (form.dataset.submitting) { event.preventDefault(); return; }
      form.dataset.submitting = 'true'; dirty = false;
      form.setAttribute('aria-busy', 'true');
      const button = form.querySelector('button');
      button.disabled = true; button.textContent = aniSmsAdminText.pending;
    });
  });
  addEventListener('beforeunload', event => {
    if (dirty) { event.preventDefault(); event.returnValue = aniSmsAdminText.unsaved; }
  });
  const navigation = root.querySelector('.ani-navigation');
  const media = matchMedia('(max-width:640px)');
  const update = () => { navigation.open = !media.matches; };
  media.addEventListener('change', update); update();
})();
