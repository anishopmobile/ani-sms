/* Owner-authorized loopback UI test. Session files and token previews stay private. */
const {chromium}=require('playwright');const fs=require('node:fs');const path=require('node:path');
(async()=>{
 const base=process.env.ANI_SMS_MARKETING_URL;const out=process.env.ANI_SMS_MARKETING_OUTPUT;
 if(!base||!['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname)||!out)throw new Error('Explicit loopback URL and private output required');
 const state=JSON.parse(fs.readFileSync(process.env.ANI_SMS_MARKETING_STATE,'utf8'));
 fs.mkdirSync(out,{recursive:true});const browser=await chromium.launch({headless:true,executablePath:'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 const admin=await browser.newContext({viewport:{width:1440,height:1000}});const user=await browser.newContext({viewport:{width:420,height:900}});const guest=await browser.newContext();
 const errors=[];for(const c of [admin,user,guest]){await c.route('**/*',r=>new URL(r.request().url()).origin===new URL(base).origin?r.continue():r.abort());c.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));}
 await admin.addCookies(JSON.parse(fs.readFileSync(process.env.ANI_SMS_MARKETING_COOKIES,'utf8')));
 const a=await admin.newPage(),u=await user.newPage(),g=await guest.newPage();
 const checks=[];const check=(ok,name)=>{checks.push({name,passed:!!ok});if(!ok)throw new Error(name);};
 const url=tab=>`${base}/wp-admin/admin.php?page=ani-sms&tab=${tab}`;
 const form=(p,op)=>p.locator('form').filter({has:p.locator(`[name=ani_op][value="${op}"]`)});
 const submit=async(p,f)=>{await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),f.locator('button[type=submit],button:not([type])').last().click()]);await p.waitForLoadState('networkidle');};
 const response=(p,op)=>p.waitForResponse(r=>r.url().includes('action=ani_sms_auth')&&r.request().postData()?.includes(`"op":"${op}"`));
 const group=`local-${String(Date.now()).slice(-6)}`;const phone='0912'+String(Date.now()).slice(-7);
 const create=async(title,future=false)=>{
  await a.goto(url('marketing'),{waitUntil:'networkidle'});const f=form(a,'marketing_create');
  await f.locator('[name=title]').fill(title);await f.locator('[name=body]').fill('پیام آزمایشی {site_name}');await f.locator('[name=tag]').fill(group);
  if(future){const date=new Date(Date.now()+3600000+210*60000).toISOString().slice(0,16);await f.locator('[name=scheduled]').fill(date);}
  await submit(a,f);check(await form(a,'marketing_approve').count()===1,'draft has explicit approval form: '+title);
  check(await a.locator('.ani-audience-count').innerText()==='1','only the explicitly consented group member is eligible: '+title);
  const approval=form(a,'marketing_approve');const id=await approval.locator('[name=campaign]').inputValue();
  await a.reload({waitUntil:'networkidle'});check(await form(a,'marketing_approve').locator('[name=campaign]').inputValue()===id,'refresh does not create another draft');
  check(!await approval.locator('[name=confirm]').isChecked(),'campaign confirmation defaults unchecked');
  await approval.locator('[name=confirm]').check();await submit(a,approval);return id;
 };
 try{
  await a.goto(url('modules'),{waitUntil:'networkidle'});const auth=await a.locator('[name=auth_enabled]').isChecked(),woo=await a.locator('[name=woocommerce_enabled]').isChecked();
  await a.locator('[name=marketing_enabled]').check();await submit(a,form(a,'save_modules'));
  check(auth===await a.locator('[name=auth_enabled]').isChecked()&&woo===await a.locator('[name=woocommerce_enabled]').isChecked(),'enabling marketing preserves auth/order states');
  await u.goto(state.auth_url,{waitUntil:'networkidle'});await u.locator('[name=phone]').fill(phone);const issued=response(u,'issue');await u.locator('.ani-auth-phone button').click();const data=(await(await issued).json()).data;
  check(/^[0-9]{6}$/.test(data.local_test_code),'local OTP created without API');await u.locator('[name=code]').fill(data.local_test_code);const verified=response(u,'verify');await u.locator('.ani-auth-code button[type=submit]').click();check((await verified).status()===200,'test subscriber signs in through actual OTP UI');
  await a.goto(url('marketing'),{waitUntil:'networkidle'});const imp=form(a,'marketing_import');await imp.locator('[name=phones]').fill(phone+'\n+98'+phone.slice(1));await imp.locator('[name=tag]').fill(group);await submit(a,imp);
  check(await a.locator('.ani-marketing-contacts tbody tr').filter({hasText:group}).count()===1,'canonical import deduplicates contacts');
  check((await a.locator('.ani-marketing-contacts tbody tr').filter({hasText:group}).innerText()).includes('pending'),'imported contact remains pending');
  await u.goto(state.url,{waitUntil:'networkidle'});check(!await u.locator('[name=explicit_consent]').isChecked(),'marketing consent defaults unchecked');
  await u.locator('[name=explicit_consent]').check();await submit(u,u.locator('.ani-marketing form'));
  check(await u.locator('[name=preference][value=withdraw]').count()===1,'explicit consent persisted through account form');
  await u.screenshot({path:path.join(out,'subscription-mobile.png'),fullPage:true});
  await a.goto(url('marketing'),{waitUntil:'networkidle'});const bad=form(a,'marketing_create');await bad.locator('[name=title]').fill('تست متغیر نامعتبر');await bad.locator('[name=body]').fill('{unknown}');await submit(a,bad);
  check(await a.locator('.ani-feedback.ani-error').count()===1,'unknown campaign variable rejected');check(await form(a,'marketing_create').locator('[name=body]').inputValue()==='{unknown}','invalid draft input retained');
  const scheduled=await create('تست زمان‌بندی و توقف کمپین',true);
  await submit(a,form(a,'marketing_control').filter({has:a.locator('[name=control][value=pause]')}));check(await form(a,'marketing_control').filter({has:a.locator('[name=control][value=resume]')}).count()===1,'pause exposes resume control');
  await submit(a,form(a,'marketing_process'));check((await a.locator('.ani-campaign-stat').innerText()).includes('queued: 1'),'paused scheduled campaign stays unsent');
  await submit(a,form(a,'marketing_control').filter({has:a.locator('[name=control][value=resume]')}));check(await form(a,'marketing_control').filter({has:a.locator('[name=control][value=pause]')}).count()===1,'resume preserves future schedule');
  await submit(a,form(a,'marketing_control').filter({has:a.locator('[name=control][value=cancel]')}));check((await a.locator('.ani-campaign-stat').innerText()).includes('cancelled: 1'),'cancelled scheduled recipient not sent');
  const simulated=await create('تست ارسال شبیه‌سازی کمپین');await new Promise(r=>setTimeout(r,5500));await submit(a,form(a,'marketing_process'));
  check((await a.locator('.ani-campaign-stat').innerText()).includes('simulated: 1'),'approved local campaign explicitly simulated');
  await a.locator('details').filter({hasText:'پیش‌نمایش پیام لوکال'}).locator('summary').click();const text=await a.locator('details .ani-campaign-preview').innerText();
  check(!text.includes('{site_name}')&&text.includes('ani_sms_unsubscribe='),'local preview resolves variables and mandatory opt-out link');
  await a.screenshot({path:path.join(out,'campaign-desktop.png'),fullPage:true});await a.setViewportSize({width:390,height:844});
  check(await a.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'marketing admin has no mobile page overflow');await a.screenshot({path:path.join(out,'campaign-mobile.png'),fullPage:true});await a.setViewportSize({width:1440,height:1000});
  const cancelledByOptout=await create('تست انصراف پس از ورود به صف',true);
  await a.locator('details').filter({hasText:'پیش‌نمایش پیام لوکال'}).locator('summary').click();
  const preview=await a.locator('details .ani-campaign-preview').innerText();const link=preview.match(/https?:\/\/\S+/)[0];
  const get=await g.goto(link,{waitUntil:'domcontentloaded'});check(get.headers()['referrer-policy']==='no-referrer','token page prevents referrer leakage');
  await a.reload({waitUntil:'networkidle'});check((await a.locator('.ani-campaign-stat').innerText()).includes('queued: 1'),'GET unsubscribe link does not withdraw consent');
  await submit(g,g.locator('form'));check((await g.locator('body').innerText()).includes('درخواست انصراف پردازش شد'),'anonymous POST confirms unsubscribe');
  await a.goto(url('marketing')+'&campaign='+cancelledByOptout,{waitUntil:'networkidle'});check((await a.locator('.ani-campaign-stat').innerText()).includes('cancelled: 1'),'opt-out cancels already queued campaign recipient');
  const repeat=form(a,'marketing_import');await repeat.locator('[name=phones]').fill(phone);await repeat.locator('[name=tag]').fill(group);await submit(a,repeat);
  check((await a.locator('.ani-marketing-contacts tbody tr').filter({hasText:group}).innerText()).includes('suppressed'),'reimport does not erase suppression');
  await u.goto(state.url,{waitUntil:'networkidle'});await u.locator('[name=explicit_consent]').check();await submit(u,u.locator('.ani-marketing form'));
  const disabled=await create('تست خاموشی ماژول و لینک انصراف',true);await a.locator('details').filter({hasText:'پیش‌نمایش پیام لوکال'}).locator('summary').click();const fresh=(await a.locator('details .ani-campaign-preview').innerText()).match(/https?:\/\/\S+/)[0];
  await a.goto(url('modules'),{waitUntil:'networkidle'});await a.locator('[name=marketing_enabled]').uncheck();await submit(a,form(a,'save_modules'));
  await g.goto(fresh,{waitUntil:'domcontentloaded'});await submit(g,g.locator('form'));check((await g.locator('body').innerText()).includes('درخواست انصراف پردازش شد'),'unsubscribe works with marketing module disabled');
  await a.locator('[name=marketing_enabled]').check();await submit(a,form(a,'save_modules'));await a.goto(url('marketing')+'&campaign='+disabled,{waitUntil:'networkidle'});
  check((await a.locator('.ani-campaign-stat').innerText()).includes('cancelled: 1'),'module re-enable does not revive cancelled jobs');
  check((await a.locator('.ani-marketing-contacts tbody tr').filter({hasText:group}).innerText()).includes('suppressed'),'disabled-module unsubscribe retained suppression');
  const denied=await admin.request.post(url('marketing'),{form:{ani_op:'marketing_import',phones:phone}});check(denied.status()===403,'missing admin nonce rejected');
  await g.goto(url('marketing'),{waitUntil:'domcontentloaded'});check(g.url().includes('wp-login.php'),'guest cannot access campaign management');
  check(errors.length===0,'no JavaScript errors');
  fs.writeFileSync(path.join(out,'fixture.json'),JSON.stringify({subscription_page:state.page_id,group,campaign_ids:[scheduled,simulated,cancelledByOptout,disabled]},null,2));
 }catch(e){await a.screenshot({path:path.join(out,'admin-failure.png'),fullPage:true}).catch(()=>{});await u.screenshot({path:path.join(out,'user-failure.png'),fullPage:true}).catch(()=>{});fs.writeFileSync(path.join(out,'admin-failure.html'),await a.content().catch(()=>''));throw e;}
 finally{fs.writeFileSync(path.join(out,'report.json'),JSON.stringify({checks,errors},null,2));await browser.close();}
 console.log(`Marketing owner UI: ${checks.length} checks passed`);
})().catch(e=>{console.error(e);process.exitCode=1;});
