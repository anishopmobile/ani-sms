/* No credentials, OTP or browser tokens are persisted in local/session storage. */
(() => {
  'use strict';
  document.querySelectorAll('.ani-auth').forEach(root => {
    const phoneForm = root.querySelector('.ani-auth-phone');
    if (!phoneForm) return;
    const codeForm = root.querySelector('.ani-auth-code');
    const status = root.querySelector('.ani-auth-status');
    const resend = root.querySelector('.ani-auth-resend');
    const timer = root.querySelector('.ani-auth-timer');
    const text = window.aniSmsText;
    let browser, challenge, number, password = '', request, busy = false, terminal = false, retryAt = 0, expiresAt = 0;
    const id = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    const say = message => { status.textContent = message; };
    const call = async payload => {
      const headers = {'Content-Type': 'application/json'};
      if (browser) { headers['X-Ani-CSRF'] = browser.csrf; headers['X-Ani-Nonce'] = browser.nonce; }
      let response;
      try {
        response = await fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers, body: JSON.stringify(payload)});
      } catch (_) { throw new Error(text.error); }
      let body;
      try { body = await response.json(); } catch (_) { throw new Error(text.unavailable); }
      if (!body || typeof body !== 'object' || typeof body.success !== 'boolean' || !body.data || typeof body.data !== 'object') throw new Error(text.unavailable);
      if (!body.success) {
        if (body.data?.retry_after) retryAt = Date.now() + body.data.retry_after * 1000;
        const error = new Error(body.data?.message || text.error);
        error.code = body.data?.code;
        throw error;
      }
      return body.data;
    };
    const run = async work => {
      if (busy) return;
      busy = true;
      root.setAttribute('aria-busy', 'true');
      root.querySelectorAll('button').forEach(b => { b.disabled = true; });
      try { await work(); } catch (error) {
        if (['otp_expired', 'otp_locked'].includes(error.code)) stopCode();
        else if (error.code === 'otp_invalid' && !codeForm.hidden) {
          codeForm.elements.code.setAttribute('aria-invalid', 'true');
          codeForm.elements.code.focus(); codeForm.elements.code.select();
        }
        say(error.message || text.error);
      }
      finally {
        busy = false;
        root.removeAttribute('aria-busy');
        root.querySelectorAll('button').forEach(b => { b.disabled = false; });
        tick();
      }
    };
    const stopCode = () => {
      terminal = true; expiresAt = 0;
      codeForm.elements.code.value = '';
      codeForm.elements.code.removeAttribute('aria-invalid');
      const testCode = root.querySelector('.ani-auth-test-code');
      testCode.textContent = ''; testCode.hidden = true;
      codeForm.querySelector('button[type=submit]').disabled = true;
    };
    const tick = () => {
      const wait = Math.max(0, Math.ceil((retryAt - Date.now()) / 1000));
      resend.disabled = busy || wait > 0;
      timer.textContent = wait ? `${text.wait} ${wait}` : '';
      if (expiresAt && Date.now() >= expiresAt && !codeForm.hidden) {
        stopCode();
        say(text.expired);
      }
      codeForm.querySelector('button[type=submit]').disabled = busy || terminal;
    };
    const issue = async () => {
      say(text.waiting);
      if (!browser) browser = await call({op: 'bootstrap'});
      // Retain request ID on transport failure; retry cannot send a duplicate SMS.
      if (!request) request = id();
      const result = await call({op: 'issue', phone: number, password, request});
      request = null;
      challenge = result.challenge;
      terminal = false;
      retryAt = Date.now() + result.retry_after * 1000;
      expiresAt = Date.now() + result.expires_in * 1000;
      root.querySelector('.ani-auth-number').textContent = result.phone;

      const testCode = root.querySelector('.ani-auth-test-code');
      testCode.hidden = !result.local_test_code;
      testCode.textContent = result.local_test_code ? `${text.localCode} ${result.local_test_code}` : '';
      phoneForm.hidden = true;
      codeForm.hidden = false;
      codeForm.elements.code.value = '';
      codeForm.elements.code.removeAttribute('aria-invalid');
      codeForm.elements.code.focus();
      say(result.local_test_code ? text.localReady : text.sent);
    };
    phoneForm.addEventListener('submit', event => {
      event.preventDefault();
      if (!phoneForm.reportValidity()) return;
      const next = phoneForm.elements.phone.value;
      if (next !== number) request = null;
      number = next;
      password = phoneForm.elements.password?.value || '';
      run(issue);
    });
    resend.addEventListener('click', () => { if (Date.now() >= retryAt) run(issue); });
    root.querySelector('.ani-auth-edit').addEventListener('click', () => {
      codeForm.hidden = true; phoneForm.hidden = false; challenge = null; expiresAt = 0; terminal = false;
      root.querySelector('.ani-auth-test-code').textContent = '';
      root.querySelector('.ani-auth-test-code').hidden = true;
      phoneForm.elements.phone.focus(); say('');
    });
    codeForm.addEventListener('submit', event => {
      event.preventDefault();
      if (terminal) return;
      if (!codeForm.reportValidity()) return;
      run(async () => {
        const result = await call({op: 'verify', challenge, code: codeForm.elements.code.value});
        codeForm.elements.code.value = ''; password = '';
        root.querySelector('.ani-auth-test-code').textContent = '';
        if (phoneForm.elements.password) phoneForm.elements.password.value = '';
        say(text.success);
        const destination = new URL(result.redirect, location.href);
        if (destination.origin === location.origin) location.assign(destination.href);
      });
    });
    codeForm.elements.code.addEventListener('input', () => codeForm.elements.code.removeAttribute('aria-invalid'));
    const interval = setInterval(() => { if (!root.isConnected) clearInterval(interval); else tick(); }, 1000);
  });
})();
