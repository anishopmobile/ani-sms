/* Loopback browser fault injection; never adjusts the owner's database or sends SMS. */
const {chromium}=require('playwright');
let browser;
(async()=>{
 const url=process.env.ANI_SMS_LOCAL_BROWSER_URL;
 if(!url || !['localhost','127.0.0.1','[::1]'].includes(new URL(url).hostname))throw new Error('Loopback form URL required.');
 browser=await chromium.launch({headless:true,executablePath:process.env.ANI_SMS_BROWSER_EXE||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 let checks=0;const failures=[];const check=(ok,name)=>{checks++;if(!ok)failures.push(name);};
 const context=await browser.newContext();const page=await context.newPage();
 let lostIssue=false,lostVerify=false,firstChallenge;
 await page.route('**/admin-ajax.php?action=ani_sms_auth',async route=>{
   const op=route.request().postDataJSON()?.op;
   if(op==='issue'&&!lostIssue){lostIssue=true;const upstream=await route.fetch();firstChallenge=(await upstream.json()).data.challenge;await route.abort();return;}
   if(op==='verify'&&!lostVerify){lostVerify=true;await route.abort();return;}
   await route.continue();
 });
 await page.goto(url,{waitUntil:'networkidle'});
 await page.locator('[name=phone]').fill('0912'+String(Date.now()).slice(-7));
 await page.locator('.ani-auth-phone button').click();
 await page.waitForFunction(()=>!document.querySelector('.ani-auth').hasAttribute('aria-busy'));
 check((await page.locator('.ani-auth-status').innerText()).includes('ارتباط'),'issue transport failure is localized');
 check(await page.locator('.ani-auth-phone').isVisible(),'phone input preserved after lost issue reply');
 const issued=page.waitForResponse(r=>r.url().includes('action=ani_sms_auth')&&r.request().postData()?.includes('"op":"issue"'));
 await page.locator('.ani-auth-phone button').click();const result=(await (await issued).json()).data;
 await page.locator('.ani-auth-test-code').waitFor({state:'visible'});
 check(result.challenge===firstChallenge,'lost issue retry reuses the original challenge');
 await page.locator('[name=code]').fill(result.local_test_code);
 await page.locator('.ani-auth-code button[type=submit]').click();
 await page.waitForFunction(()=>!document.querySelector('.ani-auth').hasAttribute('aria-busy'));
 check((await page.locator('.ani-auth-status').innerText()).includes('ارتباط'),'verify transport failure is localized');
 check(await page.locator('[name=code]').getAttribute('aria-invalid')!=='true','network failure does not falsely mark a valid code incorrect');
 check(!(await page.locator('.ani-auth-code button[type=submit]').isDisabled()),'retry remains possible after a request never reached the server');
 const verified=page.waitForResponse(r=>r.url().includes('action=ani_sms_auth')&&r.request().postData()?.includes('"op":"verify"'));
 await page.locator('.ani-auth-code button[type=submit]').click();check((await verified).status()===200,'same valid code succeeds after transport recovers');
 await context.close();
 const invalid=await browser.newContext();const badPage=await invalid.newPage();
 await badPage.route('**/admin-ajax.php?action=ani_sms_auth',async route=>{if(route.request().postDataJSON()?.op==='issue'){await route.fulfill({status:200,contentType:'application/json',body:'null'});}else await route.continue();});
 await badPage.goto(url,{waitUntil:'networkidle'});await badPage.locator('[name=phone]').fill('0912'+String(Date.now()).slice(-7));await badPage.locator('.ani-auth-phone button').click();
 await badPage.waitForFunction(()=>!document.querySelector('.ani-auth').hasAttribute('aria-busy'));
 check((await badPage.locator('.ani-auth-status').innerText()).includes('در دسترس نیست'),'invalid response gets a safe localized message');
 check(await badPage.locator('.ani-auth-phone').isVisible(),'malformed response preserves recovery path');
 await browser.close();console.log(JSON.stringify({checks,failures,sms_sent:false,faults:'lost issue response, aborted verify request, malformed response'}));if(failures.length)process.exitCode=1;
})().catch(async e=>{console.error(e.message);if(browser)await browser.close();process.exitCode=1;});
