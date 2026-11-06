/* Run against an explicitly enabled loopback installation; creates only subscriber test users. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
let browser;
(async()=>{
 const base=process.env.ANI_SMS_LOCAL_BROWSER_URL;
 if(!base || !['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname))throw new Error('Explicit loopback login URL required.');
 browser=await chromium.launch({headless:true,executablePath:process.env.ANI_SMS_BROWSER_EXE||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 let checks=0;
 const check=(ok,label)=>{assert.ok(ok,label);checks++;};
 const context=await browser.newContext({viewport:{width:420,height:900}});
 const page=await context.newPage();
 await page.goto(base,{waitUntil:'networkidle'});
 check(await page.locator('.ani-auth-test-banner').count()===1,'explicit local banner');
 await page.locator('.ani-auth-phone').waitFor();
 const phone='0912'+String(Date.now()).slice(-7);
 await page.locator('[name=phone]').fill(phone);
 await page.locator('.ani-auth-phone button').click();
 await page.locator('.ani-auth-test-code').waitFor({state:'visible'});
 const visible=await page.locator('.ani-auth-test-code').innerText();
 const code=visible.match(/\b[0-9]{6}\b/)?.[0];
 check(!!code,'six-digit test code displayed without API');
 check(await page.locator('.ani-auth-resend').isDisabled(),'resend cooldown active');
 await page.locator('[name=code]').fill(code==='000000'?'999999':'000000');
 const rejected=page.waitForResponse(r=>r.url().includes('action=ani_sms_auth')&&r.request().postData()?.includes('"op":"verify"'));
 await page.locator('.ani-auth-code button[type=submit]').click();
 check((await rejected).status()===400,'incorrect code rejected');
 check(await page.locator('.ani-auth-code').isVisible(),'wrong code preserves form');
 await page.locator('[name=code]').fill(code);
 const accepted=page.waitForResponse(r=>r.url().includes('action=ani_sms_auth')&&r.request().postData()?.includes('"op":"verify"'));
 await page.locator('.ani-auth-code button[type=submit]').click();
 check((await accepted).status()===200,'correct code registers local account');
 await page.waitForLoadState('networkidle');
 check((await context.cookies()).some(c=>c.name.startsWith('wordpress_logged_in_')),'standard WordPress cookie');
 await page.goto(base,{waitUntil:'networkidle'});
 check(await page.locator('.ani-auth-linked').count()===1,'verified local identity retained');
 const cookies=await context.cookies();
 await context.clearCookies();
 await page.goto(base,{waitUntil:'networkidle'});
 await page.locator('[name=phone]').fill(phone);
 await page.locator('.ani-auth-phone button').click();
 // Same phone from a new browser must not reset the server cooldown.
 await page.waitForFunction(()=>!document.querySelector('.ani-auth').hasAttribute('aria-busy'));
 check(await page.locator('.ani-auth-code').isHidden(),'cooldown survives new browser binding');
 check(!(await page.locator('.ani-auth-status').innerText()).includes(code),'verification code not echoed by cooldown errors');
  if(process.env.ANI_SMS_LOCAL_BROWSER_EXISTING_LOGIN==='1'){
    await page.waitForTimeout(61000);
    await page.locator('.ani-auth-phone button').click();
    await page.locator('.ani-auth-test-code').waitFor({state:'visible'});
    const loginCode=(await page.locator('.ani-auth-test-code').innerText()).match(/\b[0-9]{6}\b/)[0];
    await page.locator('[name=code]').fill(loginCode);
    const loginResponse=page.waitForResponse(r=>r.url().includes('action=ani_sms_auth')&&r.request().postData()?.includes('"op":"verify"'));
    await page.locator('.ani-auth-code button[type=submit]').click();
    check((await loginResponse).status()===200,'existing account login after genuine cooldown');
    await page.waitForLoadState('networkidle');
    const user=c=>decodeURIComponent(c.value).split('|')[0];
    const before=cookies.find(c=>c.name.startsWith('wordpress_logged_in_'));
    const after=(await context.cookies()).find(c=>c.name.startsWith('wordpress_logged_in_'));
    check(after && user(before)===user(after),'existing login retains the same WordPress account');
  }
 if(process.env.ANI_SMS_LOCAL_BROWSER_ARTIFACTS){fs.mkdirSync(process.env.ANI_SMS_LOCAL_BROWSER_ARTIFACTS,{recursive:true});await page.screenshot({path:path.join(process.env.ANI_SMS_LOCAL_BROWSER_ARTIFACTS,'local-test.png'),fullPage:true});}
 await browser.close();
 console.log(JSON.stringify({passed:checks,local_account_created:true,phone_suffix:phone.slice(-2),sms_sent:false}));
})().catch(async error=>{console.error(error.message);if(browser)await browser.close();process.exitCode=1;});
