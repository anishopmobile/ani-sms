/* Disposable WordPress browser tests. All provider calls are mocked by prepare-browser.php. */
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
let browser;

(async () => {
  const runtime = process.env.ANI_SMS_BROWSER_RUNTIME;
  if (!runtime) throw new Error('Set ANI_SMS_BROWSER_RUNTIME to the disposable runtime folder.');
  const base = process.env.ANI_SMS_BROWSER_URL || 'http://127.0.0.1:18081';
  const artifacts = path.join(runtime, 'screenshots');
  fs.mkdirSync(artifacts, {recursive: true});
  browser = await chromium.launch({headless: true, executablePath: process.env.ANI_SMS_BROWSER_EXE || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
  let checks = 0;
  const check = (condition, label) => { assert.ok(condition, label); checks++; };
  const guest = await browser.newContext({viewport: {width: 1280, height: 900}});
  const page = await guest.newPage();
  const jsErrors = [];
  page.on('pageerror', error => jsErrors.push(error.message));
  await page.goto(base, {waitUntil: 'networkidle'});
  await page.locator('.ani-auth-phone').waitFor();
  check((await page.locator('.ani-auth h2').innerText()).includes('ورود'), 'Persian catalog loaded');
  check(await page.locator('.ani-auth-privacy').count() === 1, 'privacy link is available');
  await page.addScriptTag({path: require.resolve('axe-core/axe.min.js')});
  const frontAxe = await page.evaluate(async () => (await axe.run(document.querySelector('.ani-auth'), {runOnly: {type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa']}})).violations);
  check(frontAxe.length === 0, 'frontend WCAG checks: ' + frontAxe.map(v => v.id).join(','));
  await page.locator('[name=phone]').focus();
  await page.keyboard.press('Tab');
  check(await page.locator('.ani-auth-phone button').evaluate(el => el === document.activeElement), 'phone to submit keyboard order');
  for (const width of [360, 768, 1280]) {
    await page.setViewportSize({width, height: 900});
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `frontend no overflow ${width}`);
    await page.screenshot({path: path.join(artifacts, `auth-${width}.png`), fullPage: true});
  }
  // Cross-origin bootstrap must fail even when no cookie exists yet.
  const cross = await page.request.post(`${base}/wp-admin/admin-ajax.php?action=ani_sms_auth`, {headers: {Origin: 'https://attacker.example'}, data: {op: 'bootstrap'}});
  check(cross.status() === 503, 'cross origin rejected');
  const noToken = await page.request.post(`${base}/wp-admin/admin-ajax.php?action=ani_sms_auth`, {headers: {Origin: base}, data: {op: 'issue', phone: '09129998877', request: 'a'.repeat(32)}});
  check(noToken.status() === 503, 'missing browser CSRF rejected');
  const phone = '0912' + String(Date.now()).slice(-7);
  await page.locator('[name=phone]').fill(phone);
  await page.locator('.ani-auth-phone button').click();
  await page.locator('.ani-auth-code').waitFor({state: 'visible'});
  check(await page.locator('.ani-auth-resend').isDisabled(), 'resend cooldown enforced in UI');
  const sms = JSON.parse(fs.readFileSync(path.join(runtime, 'test-otp.json'), 'utf8'));
  check(sms.mobile === phone, 'mock provider receives normalized recipient');
  await page.locator('[name=code]').fill(sms.parameters[0].value);
  const verified = page.waitForResponse(r => r.url().includes('action=ani_sms_auth') && r.request().postData()?.includes('"op":"verify"'));
  await page.locator('.ani-auth-code button[type=submit]').click();
  const verifiedResponse = await verified;
  check(verifiedResponse.status() === 200, 'server verifies OTP');
  await page.waitForLoadState('networkidle');
  await page.locator('.ani-auth-linked').waitFor();
  check((await guest.cookies()).some(c => c.name.startsWith('wordpress_logged_in_')), 'WordPress authentication cookie issued');
  check((await page.locator('.ani-auth-linked').innerText()).includes('تأیید'), 'linked identity view has no number-change form');
  // Newly registered users never receive admin access.
  await page.goto(`${base}/wp-admin/admin.php?page=ani-sms`);
  check(await page.locator('.ani-admin').count() === 0, 'subscriber cannot view admin panel');
  check(jsErrors.length === 0, 'no frontend JavaScript exceptions');

  const adminContext = await browser.newContext({viewport: {width: 1415, height: 794}});
  const adminPage = await adminContext.newPage();
  const auth = JSON.parse(fs.readFileSync(path.join(runtime, 'test-auth.json'), 'utf8'));
  await adminPage.goto(`${base}/wp-login.php`);
  await adminPage.locator('#user_login').fill(auth.login);
  await adminPage.locator('#user_pass').fill(auth.password);
  await Promise.all([adminPage.waitForURL('**/wp-admin/**'), adminPage.locator('#wp-submit').click()]);
  await adminPage.goto(`${base}/wp-admin/admin.php?page=ani-sms&tab=connection`, {waitUntil: 'networkidle'});
  check(await adminPage.locator('.ani-stats .ani-stat').count() === 4, 'four honest statistic cards');
  check(await adminPage.locator('[name=api_key]').inputValue() === '', 'stored API key never echoed');
  check(!(await adminPage.content()).includes('fake-test-api-key'), 'API key absent from HTML');
  const mainBox = await adminPage.locator('.ani-main').boundingBox();
  const sideBox = await adminPage.locator('.ani-sidebar').boundingBox();
  check(sideBox.x > mainBox.x, 'reference sidebar on right');
  await adminPage.addScriptTag({path: require.resolve('axe-core/axe.min.js')});
  const adminAxe = await adminPage.evaluate(async () => (await axe.run(document.querySelector('.ani-admin'), {runOnly: {type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa']}})).violations);
  check(adminAxe.length === 0, 'admin WCAG checks: ' + adminAxe.map(v => v.id).join(','));
  await adminPage.screenshot({path: path.join(artifacts, 'admin-reference-1415.png'), fullPage: true});
  for (const width of [360, 768, 1280]) {
    await adminPage.setViewportSize({width, height: 900});
    check(await adminPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `admin no overflow ${width}`);
    await adminPage.screenshot({path: path.join(artifacts, `admin-${width}.png`), fullPage: true});
  }
  await adminPage.setViewportSize({width: 1280, height: 900});
  await adminPage.locator('form:has([name=ani_op][value=test_connection]) button').click();
  await adminPage.waitForLoadState('networkidle');
  check((await adminPage.locator('.ani-feedback').innerText()).includes('موفق'), 'non-sending connection diagnostics');
  await adminPage.goto(`${base}/wp-admin/admin.php?page=ani-sms&tab=auth`, {waitUntil: 'networkidle'});
  await adminPage.locator('[name=expiry]').fill('180');
  await adminPage.locator('.ani-settings button').click();
  await adminPage.waitForLoadState('networkidle');
  check(await adminPage.locator('[name=expiry]').inputValue() === '180', 'privileged nonce-protected settings saved');
  const forged = await adminPage.request.post(`${base}/wp-admin/admin.php?page=ani-sms&tab=auth`, {form: {ani_op: 'save_auth', expiry: '300'}});
  check(forged.status() === 403, 'missing admin nonce rejected');
  await adminPage.goto(`${base}/wp-admin/admin.php?page=ani-sms&tab=logs`);
  check(await adminPage.locator('.ani-table-scroll table').count() === 1, 'server-side event log table');
  await browser.close();
  console.log(`PASS browser: ${checks} checks; screenshots in ${artifacts}; all SMS mocked.`);
})().catch(async error => { console.error(error); if (browser) await browser.close(); process.exitCode = 1; });
