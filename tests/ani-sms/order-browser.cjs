/* Authorized loopback owner-site UI checks. Cookie file stays outside Git. */
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
(async()=>{
 const base=process.env.ANI_SMS_ORDER_URL;
 if(!base || !['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname))throw new Error('Loopback site required');
 const out=process.env.ANI_SMS_ORDER_OUTPUT;
 if(!out || !process.env.ANI_SMS_ORDER_COOKIES)throw new Error('Private output and session file required');
 fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true,executablePath:process.env.ANI_SMS_BROWSER_EXE||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'});
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 await context.addCookies(JSON.parse(fs.readFileSync(process.env.ANI_SMS_ORDER_COOKIES,'utf8')));
 await context.route('**/*',route=>new URL(route.request().url()).origin===new URL(base).origin?route.continue():route.abort());
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const checks=[];const check=(v,name)=>{checks.push({name,passed:!!v});if(!v)throw new Error(name);};
 const url=tab=>`${base}/wp-admin/admin.php?page=ani-sms&tab=${tab}`;
 const submit=async form=>{await Promise.all([page.waitForURL(/page=ani-sms/,{waitUntil:'load'}),form.locator('button').click()]);await page.waitForLoadState('networkidle');};
 try{
  if(process.env.ANI_SMS_ORDER_PHASE==='configure'){
   await page.goto(url('modules'),{waitUntil:'networkidle'});
   check(await page.locator('[name=woocommerce_enabled]').count()===1,'module toggle exists');
   const auth=await page.locator('[name=auth_enabled]').isChecked();
   await page.locator('[name=woocommerce_enabled]').check();await submit(page.locator('form').filter({has:page.locator('[name=ani_op][value=save_modules]')}));
   check(await page.locator('[name=woocommerce_enabled]').isChecked(),'module state persisted');
   check(auth===await page.locator('[name=auth_enabled]').isChecked(),'auth state preserved');
   await page.goto(url('woocommerce'),{waitUntil:'networkidle'});
   check(await page.locator('input[name$="[template]"]').count()===4,'four independent template routes');
   if(process.env.ANI_SMS_ORDER_EXPECT_DEFAULTS==='1')check(await page.locator('[name=customer_enabled]').isChecked()===false,'customer defaults off');
   if(process.env.ANI_SMS_ORDER_EXPECT_DEFAULTS==='1')check(await page.locator('[name=admin_enabled]').isChecked()===false,'admin defaults off');
   await page.locator('[name=customer_enabled]').check();await page.locator('[name=admin_enabled]').check();
   await page.locator('[name=created_enabled]').check();await page.locator('[name=admin_phones]').fill('09120000002');
   for(const value of ['processing','completed','on-hold','cancelled'])await page.locator(`[name="statuses[]"][value="${value}"]`).check();
   for(const route of ['created_customer','created_admin','status_customer','status_admin']){
    await page.locator(`[name="${route}[template]"]`).fill('0');
    await page.locator(`[name="${route}[mapping]"]`).fill('OrderNumber=order_number\nStatus=status\nName=customer_name\nTotal=total\nSite=site_name');
   }
   await submit(page.locator('form').filter({has:page.locator('[name=ani_op][value=save_woocommerce]')}));
   check(await page.locator('.ani-feedback.ani-error').count()===0,'local zero-template settings saved');
   check(await page.locator('[name=customer_enabled]').isChecked() && await page.locator('[name=admin_enabled]').isChecked(),'independent recipients persisted');
   await page.reload({waitUntil:'networkidle'});
   check((await page.locator('[name="created_customer[mapping]"]').inputValue()).includes('Site=site_name'),'parameter mappings persisted');
   const savedMapping=await page.locator('[name="created_customer[mapping]"]').inputValue();
   await page.locator('[name="created_customer[mapping]"]').fill('Code=unknown');
   await page.locator('[name=admin_phones]').fill('09120000004');
   await submit(page.locator('form').filter({has:page.locator('[name=ani_op][value=save_woocommerce]')}));
   check(await page.locator('.ani-feedback.ani-error').count()===1,'invalid variable reports error');
   check(await page.locator('[name="created_customer[mapping]"]').inputValue()==='Code=unknown','invalid mapping retained for correction');
   check(await page.locator('[name=admin_phones]').inputValue()==='09120000004','other unsaved fields retained on error');
   await page.reload({waitUntil:'networkidle'});
   check(await page.locator('[name="created_customer[mapping]"]').inputValue()===savedMapping,'invalid submission did not overwrite stored mapping');
   check((await page.locator('[name=admin_phones]').inputValue()).includes('989120000002'),'invalid submission did not overwrite recipients');
   const denied=await context.request.post(url('woocommerce'),{form:{ani_op:'run_order_queue'}});
   check(denied.status()===403,'missing admin nonce rejected');
   await page.screenshot({path:path.join(out,'configuration-desktop.png'),fullPage:true});
   await page.setViewportSize({width:390,height:844});
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'mobile page has no horizontal overflow');
   await page.screenshot({path:path.join(out,'configuration-mobile.png'),fullPage:true});
   const guest=await browser.newContext();const guestPage=await guest.newPage();await guestPage.goto(url('woocommerce'),{waitUntil:'domcontentloaded'});
   check(guestPage.url().includes('wp-login.php'),'guest cannot access settings or previews');await guest.close();
   await page.goto(url('modules'),{waitUntil:'networkidle'});
   await page.locator('[name=woocommerce_enabled]').uncheck();await submit(page.locator('form').filter({has:page.locator('[name=ani_op][value=save_modules]')}));
   check(!await page.locator('[name=woocommerce_enabled]').isChecked(),'module disable persisted through UI');
   check(auth===await page.locator('[name=auth_enabled]').isChecked(),'module disable preserved auth state');
   await page.locator('[name=woocommerce_enabled]').check();await submit(page.locator('form').filter({has:page.locator('[name=ani_op][value=save_modules]')}));
   await page.goto(url('woocommerce'),{waitUntil:'networkidle'});
   check(await page.locator('[name=customer_enabled]').isChecked() && await page.locator('[name=admin_enabled]').isChecked(),'module cycle preserved recipient configuration');
  }else{
   const state=JSON.parse(fs.readFileSync(process.env.ANI_SMS_ORDER_STATE,'utf8'));
   await page.goto(url('woocommerce'),{waitUntil:'networkidle'});
   const subjectRow=id=>page.locator('.ani-table-scroll tbody tr').filter({has:page.locator(`td:first-child:text-is("${id}")`)});
   check(await subjectRow(state.order_id).count()===2,'new test order has two recipient jobs in UI');
   await new Promise(resolve=>setTimeout(resolve,5500));
   await submit(page.locator('form').filter({has:page.locator('[name=ani_op][value=run_order_queue]')}));
   check(await subjectRow(state.order_id).locator('td:text-is("simulated")').count()===2,'local queue explicitly simulated');
   check(await page.locator('details summary').filter({hasText:'پارامترها'}).count()>=2,'loopback parameter previews available');
   await page.locator('.ani-table-scroll details').first().locator('summary').click();
   check((await page.locator('.ani-table-scroll pre').first().innerText()).includes('OrderNumber'),'preview shows mapped parameter names');
   check(!(await page.locator('.ani-table-scroll').innerText()).includes('09120000001'),'recipient is masked in UI');
   await page.screenshot({path:path.join(out,'simulated-jobs.png'),fullPage:true});
   await page.goto(state.edit_url,{waitUntil:'networkidle'});
   const status=page.locator('#order_status');await status.selectOption('wc-completed');
   const update=page.locator('button.save_order');
   await update.click();await page.waitForLoadState('networkidle');
   check((await page.locator('#order_status').inputValue())==='wc-completed','WooCommerce order status saved through UI');
   await page.goto(url('woocommerce'),{waitUntil:'networkidle'});
   check(await subjectRow(state.order_id).count()===4,'status change created independent recipient jobs');
   await new Promise(resolve=>setTimeout(resolve,5500));
   await submit(page.locator('form').filter({has:page.locator('[name=ani_op][value=run_order_queue]')}));
   check(await subjectRow(state.order_id).locator('td:text-is("simulated")').count()===4,'UI status notifications simulated');
   const before=await page.locator('.ani-table-scroll tbody tr').count();await page.reload({waitUntil:'networkidle'});
   check(before===await page.locator('.ani-table-scroll tbody tr').count(),'reload does not duplicate events');
   await page.screenshot({path:path.join(out,'status-jobs.png'),fullPage:true});
   await page.goto(url('logs'),{waitUntil:'networkidle'});await page.locator('select[name=state]').selectOption('simulated');await page.locator('button').filter({hasText:'فیلتر'}).click();await page.waitForLoadState('networkidle');
   check((await page.locator('.ani-table-scroll').innerText()).includes('wc_local_test'),'simulated order log filter works');
  }
  check(errors.length===0,'no JavaScript errors');
 }catch(error){
  await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});
  fs.writeFileSync(path.join(out,'failure.html'),await page.content().catch(()=>''));
  throw error;
 }finally{
  fs.writeFileSync(path.join(out,`${process.env.ANI_SMS_ORDER_PHASE||'verify'}-report.json`),JSON.stringify({checks,errors},null,2));
  await browser.close();
 }
 console.log(`Order UI ${process.env.ANI_SMS_ORDER_PHASE||'verify'}: ${checks.length} checks passed`);
})().catch(e=>{console.error(e);process.exitCode=1;});
