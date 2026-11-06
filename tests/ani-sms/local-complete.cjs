/* Real-time local walkthrough; no database resets, clock overrides or SMS. */
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
let browser;
(async()=>{
 const url=process.env.ANI_SMS_LOCAL_BROWSER_URL;
 if(!url || !['localhost','127.0.0.1','[::1]'].includes(new URL(url).hostname))throw new Error('Explicit loopback form URL required.');
 const origin=new URL(url).origin;
 const failures=[];let checks=0;
 const check=(ok,name)=>{checks++;if(!ok)failures.push(name);};
 browser=await chromium.launch({headless:true,executablePath:process.env.ANI_SMS_BROWSER_EXE||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 const nextPhone=(()=>{let suffix=Number(String(Date.now()).slice(-7));return()=> '0912'+String(++suffix).padStart(7,'0');})();
 const response=(page,op)=>page.waitForResponse(r=>r.url().includes('action=ani_sms_auth')&&r.request().postData()?.includes(`"op":"${op}"`));
 const idle=page=>page.waitForFunction(()=>!document.querySelector('.ani-auth')?.hasAttribute('aria-busy'));
 async function begin(){
   const context=await browser.newContext({viewport:{width:420,height:900}});
   const page=await context.newPage();await page.goto(url,{waitUntil:'networkidle'});
   const phone=nextPhone();await page.locator('[name=phone]').fill(phone);
   const bootstrap=response(page,'bootstrap');const issued=response(page,'issue');
   await page.locator('.ani-auth-phone button').click();
   const tokens=(await (await bootstrap).json()).data;
   const data=(await (await issued).json()).data;
   await page.locator('.ani-auth-test-code').waitFor({state:'visible'});await idle(page);
   check(/^[0-9]{6}$/.test(data.local_test_code),'six-digit local code');
   check((await page.locator('.ani-auth-test-code').innerText()).includes(data.local_test_code),'display matches issued code');
   const endpoint=await page.locator('.ani-auth').getAttribute('data-endpoint');
   return {context,page,phone,data,tokens,endpoint,issuedAt:Date.now()};
 }
 async function api(flow,payload){
   const result=await flow.page.evaluate(async ({endpoint,tokens,payload})=>{
     const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Ani-CSRF':tokens.csrf,'X-Ani-Nonce':tokens.nonce},body:JSON.stringify(payload)});
     return {status:r.status,body:await r.json()};
   },{endpoint:flow.endpoint,tokens:flow.tokens,payload});
   return result;
 }
 async function verify(flow,code){
   await flow.page.locator('[name=code]').fill(code);
   const r=response(flow.page,'verify');await flow.page.locator('.ani-auth-code button[type=submit]').click();
   const result=await r;await idle(flow.page).catch(()=>{});return result.status();
 }
 async function waitUntil(flow,seconds){await flow.page.waitForTimeout(Math.max(0,flow.issuedAt+seconds*1000+1500-Date.now()));}
 const scenarios={};
 async function scenario(name,work){try{await work();scenarios[name]='completed';}catch(e){failures.push(name+': '+e.message);scenarios[name]='failed';}}
 await Promise.all([
   scenario('wrong-and-lock',async()=>{
     const f=await begin();const wrong=f.data.local_test_code==='000000'?'999999':'000000';
     check(await verify(f,wrong)===400,'wrong code rejected');
     check((await f.page.locator('.ani-auth-status').innerText()).length>0,'wrong-code error visible');
     check(await f.page.locator('[name=code]').getAttribute('aria-invalid')==='true','wrong-code field marked invalid');
     for(let i=1;i<5;i++)check(await verify(f,wrong)===400,'wrong attempt rejected '+(i+1));
     check(await f.page.locator('.ani-auth-code button[type=submit]').isDisabled(),'locked challenge disables verify UI');
     const r=await api(f,{op:'verify',challenge:f.data.challenge,code:f.data.local_test_code});
     check(r.status===400,'correct code cannot bypass exhausted attempts');
     check((await f.context.cookies()).every(c=>!c.name.startsWith('wordpress_logged_in_')),'locked flow creates no login');
     await waitUntil(f,f.data.retry_after);
     const issued=response(f.page,'issue');await f.page.locator('.ani-auth-resend').click();
     const recovered=(await (await issued).json()).data;await idle(f.page);
     check(await verify(f,recovered.local_test_code)===200,'locked flow recovers with a newly issued code');
     await f.context.close();
   }),
   scenario('real-expiry-and-recovery',async()=>{
     const f=await begin();await waitUntil(f,f.data.expires_in);
     check((await f.page.locator('.ani-auth-status').innerText()).includes('منقضی'),'expiry feedback visible');
     check(await f.page.locator('.ani-auth-test-code').isHidden(),'expired test code removed from display');
     check(await f.page.locator('.ani-auth-code button[type=submit]').isDisabled(),'expired challenge disables verify UI');
     check(await f.page.locator('[name=code]').inputValue()==='','expired input cleared');
     const r=await api(f,{op:'verify',challenge:f.data.challenge,code:f.data.local_test_code});
     check(r.status===400,'server rejects genuinely expired code');
     check(!(await f.page.locator('.ani-auth-resend').isDisabled()),'resend available after expiration');
     const issued=response(f.page,'issue');await f.page.locator('.ani-auth-resend').click();
     const renewed=(await (await issued).json()).data;await idle(f.page);
     check(renewed.challenge!==f.data.challenge,'expired flow receives a fresh challenge');
     check(!(await f.page.locator('.ani-auth-code button[type=submit]').isDisabled()),'new challenge enables verify again');
     check((await f.page.locator('.ani-auth-test-code').innerText()).includes(renewed.local_test_code),'new test code visible after expiry');
     check(await f.page.locator('[name=code]').getAttribute('aria-invalid')===null,'new code resets validation state');
     check(await verify(f,renewed.local_test_code)===200,'registration succeeds after expiry and resend');
     await f.context.close();
   }),
   scenario('resend-register-and-login',async()=>{
     const f=await begin();check(await f.page.locator('.ani-auth-resend').isDisabled(),'immediate resend disabled');
     const early=await api(f,{op:'issue',phone:f.phone,request:'a'+String(Date.now()).padStart(31,'0')});
     check(early.status===429,'server blocks premature resend');
     await waitUntil(f,f.data.retry_after);
     const issued=response(f.page,'issue');await f.page.locator('.ani-auth-resend').click();
     const fresh=(await (await issued).json()).data;await idle(f.page);
     check(fresh.challenge!==f.data.challenge,'resend creates a new challenge');
     const old=await api(f,{op:'verify',challenge:f.data.challenge,code:f.data.local_test_code});
     check(old.status===400,'old challenge invalid after accepted resend');
     check(await verify(f,fresh.local_test_code)===200,'new code registers account');
     await f.page.waitForLoadState('networkidle');
     const cookie=(await f.context.cookies()).find(c=>c.name.startsWith('wordpress_logged_in_'));
     check(!!cookie,'registered account receives core login cookie');
     const guest=await browser.newContext();const page=await guest.newPage();await page.goto(url,{waitUntil:'networkidle'});
     await page.locator('[name=phone]').fill(f.phone);
     await page.waitForTimeout(fresh.retry_after*1000+1500);
     const issuedAgain=response(page,'issue');await page.locator('.ani-auth-phone button').click();
     const again=(await (await issuedAgain).json()).data;await idle(page);
     const logged=response(page,'verify');await page.locator('[name=code]').fill(again.local_test_code);await page.locator('.ani-auth-code button[type=submit]').click();
     check((await logged).status()===200,'existing account logs in again');await page.waitForLoadState('networkidle');
     const newCookie=(await guest.cookies()).find(c=>c.name.startsWith('wordpress_logged_in_'));
     const username=c=>decodeURIComponent(c.value).split('|')[0];
     check(newCookie&&cookie&&username(newCookie)===username(cookie),'repeat login uses the same account');
     await guest.close();await f.context.close();
   })
 ]);
 await browser.close();
 const report={checks,passed:checks-failures.length,failures,scenarios,sms_sent:false,real_time:true};
 if(process.env.ANI_SMS_LOCAL_BROWSER_ARTIFACTS){fs.mkdirSync(process.env.ANI_SMS_LOCAL_BROWSER_ARTIFACTS,{recursive:true});fs.writeFileSync(path.join(process.env.ANI_SMS_LOCAL_BROWSER_ARTIFACTS,'complete-report.json'),JSON.stringify(report,null,2));}
 console.log(JSON.stringify(report));if(failures.length)process.exitCode=1;
})().catch(async error=>{console.error(error.message);if(browser)await browser.close();process.exitCode=1;});
