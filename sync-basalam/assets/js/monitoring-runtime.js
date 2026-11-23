/* Authenticated WordPress runtime. The standalone prototype is not loaded here. */
(() => {
  'use strict';
  const root=document.querySelector('.basalam-monitoring-page'),config=window.woosalamMonitoring;
  if(!root||!config||!window.createWoosalamMonitoringView||!window.createWoosalamCoverage||!window.createWoosalamPaymentFeedback)return;
  const $=s=>root.querySelector(s),$$=s=>[...root.querySelectorAll(s)],tabs=['overview','history','coverage','settings','subscription'],params=new URLSearchParams(location.search);
  const uuid=s=>/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(s||'');
  const state={tab:'overview',scenario:'loading',selected:'',planTouched:false,terms:false,repairAccepted:false,repairDialog:false,settingsUnknown:false,settingsBusy:false,checkoutBusy:false,coverageStartBusy:false,manualBusy:false,manualItem:null,paymentUnknown:true,paymentNoticeHidden:false,payment:null,quote:null,checkoutMessage:'',paymentMessage:'',paymentChecking:false,paymentDialog:false,lastPaymentShown:''};
  let plans=[],data,epoch=0,statusBusy=false,paymentBusy=false,plansBusy=false,dialogTrigger,toastTimer,tourTimer;
  let paymentLoaded=false,paymentRequest=null;
  let paymentKey=uuid(params.get('idempotency_key'))?params.get('idempotency_key'):null;
  const pages={issues:{cursors:[null],index:0,next:null,rows:[],loaded:false,busy:false,error:''},history:{cursors:[null],index:0,next:null,rows:[],loaded:false,busy:false,error:''},changes:{cursors:[null],index:0,next:null,rows:[],busy:false,error:'',scan:null,serial:0}};
  const reasons={
    unknown:['بررسی کامل نشده','پایشگر نتوانست وضعیت این محصول را کامل بررسی کند.','اتصال محصول را بازبینی کنید؛ اگر مشکل ادامه داشت، شناسهٔ محصول را برای پشتیبانی بفرستید.'],
    values_differ:['اختلاف قیمت یا موجودی','قیمت یا موجودی این محصول در باسلام با مقدار مرجع ووکامرس متفاوت است.','مقدار مرجع را بررسی کنید و برای هماهنگ‌سازی، «اعمال مقدار ووکامرس» را بزنید.'],
    mapping_incomplete:['اتصال تنوع','اتصال بعضی از تنوع‌ها کامل نیست.','اتصال تنوع‌های این محصول به باسلام را در ووسلام تکمیل کنید.'],
    mapping_missing:['اتصال تنوع','شناسهٔ تنوع متصل تغییر کرده است.','اتصال تنوع محصول به باسلام را بازبینی کنید.'],
    variant_limit_exceeded:['تعداد زیاد تنوع‌ها','این محصول بیش از ۵۰۰ تنوع قابل پایش دارد؛ بررسی کامل آن فعلاً انجام نشده است.','پایش کامل این محصول به پردازش مرحله‌ای تنوع‌ها نیاز دارد؛ برای پیگیری با پشتیبانی تماس بگیرید.'],
    coverage_source_unavailable:['دریافت فهرست محصولات','فهرست محصولات از فروشگاه دریافت نشد؛ آماده‌سازی پوشش کامل نشده است.','اتصال فروشگاه را بررسی و گزارش را دوباره دریافت کنید.'],
    coverage_upgrade_required:['به‌روزرسانی افزونه','نسخهٔ افزونهٔ فروشگاه از انتخاب محصولات در حال پایش پشتیبانی نمی‌کند.','افزونهٔ ووسلام را به‌روز کنید.'],
    coverage_changed:['تغییر محصولات در حال پایش','انتخاب محصولات حین اجرای این دور تغییر کرده است.','دور تازه با فهرست جدید محصولات اجرا می‌شود.'],
    source_changed:['تغییر اطلاعات محصول','اطلاعات محصول حین پایش تغییر کرده است.','پایشگر در دور بعد مقادیر تازه را بررسی می‌کند.'],
    source_removed:['محصول حذف‌شده','محصول حذف شده یا اتصال آن قطع شده است.','وضعیت انتشار و اتصال محصول را بررسی کنید.'],
    timeout:['در انتظار بررسی مجدد','پاسخ باسلام در مهلت این بررسی دریافت نشد.','نتیجهٔ بررسی مجدد را در دور بعد دنبال کنید.'],
    verification_mismatch:['اختلاف پس از اصلاح','پس از ارسال تغییر، باسلام هنوز مقدار متفاوتی برگردانده است؛ اصلاح تأیید نشده است.','مقدار مرجع را بررسی کنید؛ سپس اعمال مقدار ووکامرس را دوباره انجام دهید.'],
    manual_repair_unconfirmed:['اصلاح تأیید نشده','درخواست هماهنگ‌سازی ارسال شده، اما نتیجهٔ نهایی هنوز تأیید نشده است.','ابتدا نتیجه را تازه کنید؛ اگر اختلاف باقی بود، دوباره هماهنگ‌سازی کنید.'],
    price_invalid:['بررسی قیمت','قیمت محاسبه‌شده معتبر نیست.','قیمت محصول و تنظیمات محاسبهٔ ووسلام را بازبینی کنید.'],
    rate_limited:['محدودیت درخواست','سرویس موقتاً درخواست‌های کمتری می‌پذیرد.','پایشگر بررسی را با فاصله ادامه می‌دهد.'],
    repair_batch_pending:['ادامهٔ بررسی تنوع‌ها','بررسی بخشی از تنوع‌ها باقی مانده است.','نتیجه را در گزارش دور بعد پیگیری کنید.'],
    repair_already_attempted:['بررسی نتیجهٔ اصلاح','فرمان اصلاح این دور قبلاً ارسال شده است.','نتیجهٔ تأیید را در گزارش بعدی دنبال کنید.'],
    wordpress_upgrade_required:['نسخهٔ افزونه','نسخهٔ نصب‌شده از اصلاح خودکار پشتیبانی نمی‌کند.','افزونهٔ ووسلام را به‌روز کنید.'],
    wordpress_repair_failed:['اصلاح کامل نشده','افزونه نتوانسته اصلاح را کامل کند.','اتصال غرفه و گزارش خطاهای ووسلام را بررسی کنید.'],
    capacity_exceeded:['ظرفیت پایش','این محصول در ظرفیت فعلی پایش قرار نگرفته است.','در محصولات در حال پایش، آن را جایگزین یکی از انتخاب‌ها کنید.'],
    source_updates_blocked:['تنظیمات به‌روزرسانی','تنظیمات فعلی اجازهٔ به‌روزرسانی محصول را نمی‌دهد.','تنظیمات ارسال تغییرات را در ووسلام بررسی کنید.'],
    permission_denied:['مجوز اتصال','مجوز لازم برای اصلاح معتبر نیست.','در خانهٔ ووسلام اتصال غرفه را به‌روز کنید.'],
    remote_owner_mismatch:['غرفهٔ محصول','محصول باسلام متعلق به غرفهٔ متصل نیست.','اتصال محصول به غرفهٔ درست را بررسی کنید.']
  };
  const obj=x=>x&&typeof x==='object'&&!Array.isArray(x)?x:{},num=x=>typeof x==='number'&&Number.isFinite(x)?x:null;
  function normalize(model){
    const s=obj(model.subscription),p=obj(s.products),h=obj(p.history),summary=obj(p.summary),aggregate=obj(h.aggregate),benefits=obj(p.benefits);
    for(const k of ['checked_products','mismatched_products','updated_products','needs_attention','processed_products','healthy_products','failed_products','skipped_products'])summary[k]=num(summary[k]);
    for(const k of ['total_scans','completed_scans','running_scans','failed_scans','checked_products','mismatched_products','updated_products','comparison_products','before_match_percent','after_match_percent'])aggregate[k]=num(aggregate[k]);
    for(const k of ['underpriced_gap_irr','overpriced_gap_irr','restored_products','price_corrections','stock_corrections','correction_events'])benefits[k]=num(benefits[k]);
    return {...model,available:model.available===true,stale:model.stale===true,empty:!p.scan_started_at&&!p.scan_completed_at,subscription:{...s,connected_units:num(s.connected_units),plan:obj(s.plan),products:{...p,summary,benefits,history:{aggregate},recent_issues:Array.isArray(p.recent_issues)?p.recent_issues:[]}}};
  }
  data=normalize(config.initial||{});
  const view=window.createWoosalamMonitoringView({root,state,data,reasons,timezone:config.timezone,supportUrl:safeUrl(config.supportUrl),unavailable,plansUnavailable});
  const {esc,n,money,date,time,icon,badge,button,notice,heading,empty,icons,paymentDetails}=view;
  const coverage=window.createWoosalamCoverage({view,render:()=>renderPanel('coverage'),toast,searchInput:modal=>$(modal?'#coverage-replacement-search':'#coverage-search')?.value,focusSearch:modal=>$(modal?'#coverage-replacement-search':'#coverage-search')?.focus(),
    openReplacement,renderReplacement,closeReplacement,focusCoverageProduct,
    guide:trigger=>window.MonitoringTour?.start({section:'coverage',selectTab,trigger}),
    request:async(operation,values)=>{const version=epoch;const result=await request(operation,values);return current(result,version)?result:null;},
    onSummary:summary=>{data.subscription.coverage=summary;}});
  const paymentFeedback=window.createWoosalamPaymentFeedback({state,getScope:()=>data.onboarding_scope||data.scope||location.host,
    shouldShow:payment=>view.paymentToastAllowed(payment),
    isVisible:()=>!$('#payment-toast').hidden&&!document.hidden,
    isInteracting:()=>!!$('#payment-toast:hover, #payment-toast:focus-within'),
    onChange:renderPaymentToast});
  const statusText=(text='')=>{$('#request-status').textContent=text;};
  function scenario(){state.scenario=!data.available?!data.connected?'disconnected':['authorization','connection_changed'].includes(data.error)?'unauthorized':'unavailable':data.subscription.products.scan_error?'failed':'ready';}
  function safeUrl(value,payment=false){try{const u=new URL(value);if(u.username||u.password)return '';return payment?u.protocol==='https:'&&u.hostname==='apps.basalam.com'&&(!u.port||u.port==='443')&&u.pathname.startsWith('/pay/')?u.href:'':u.origin===location.origin?u.href:'';}catch{return '';}}
  async function request(operation,values={}){
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),operation.startsWith('purchase_')||operation==='manual_repair'?45000:8000);
    try{const response=await fetch(config.ajaxUrl,{method:'POST',credentials:'same-origin',signal:controller.signal,body:new URLSearchParams({action:'sync_basalam_monitoring',nonce:config.nonce,operation,scope:data.scope||'',...values})});const body=await response.json();if(!response.ok||!body.success){const error=new Error(body.data?.message||'اعتبار صفحه را با بارگذاری دوباره به‌روز کنید.');error.code=body.data?.code;throw error;}return body.data;}
    catch(error){if(['AbortError','SyntaxError','TypeError'].includes(error.name))throw new Error('پاسخ سرویس دریافت نشد. دوباره نتیجه را بررسی کنید.');throw error;}finally{clearTimeout(timer);}
  }
  function current(result,version){if(version!==epoch)return false;if(result.scope&&data.scope&&result.scope!==data.scope){clearPrivate();void refresh();return false;}return true;}
  function clearPrivate(){epoch++;paymentPoller.reset();paymentLoaded=false;coverage.reset();state.payment=null;paymentFeedback.reset();plans=[];paymentKey=null;state.planTouched=false;state.quote=null;state.checkoutMessage='';state.paymentMessage='';state.lastPaymentShown='';state.paymentUnknown=true;expectedActivation=null;activationObserved=false;state.terms=state.repairAccepted=false;state.settingsUnknown=false;for(const p of Object.values(pages))Object.assign(p,{cursors:[null],index:0,next:null,rows:[],loaded:false,error:'',busy:false});pages.changes.serial++;window.MonitoringTour?.stop();$('#detail-dialog').close();}
  function unavailable(){if(state.scenario==='loading')return '<div aria-busy="true"><p role="status">در حال دریافت وضعیت فروشگاه…</p><div class="skeleton large"></div></div>';const connection=['disconnected','unauthorized'].includes(state.scenario);return empty(connection?'اتصال غرفه را تکمیل کنید':'گزارش پایش در دسترس نیست',connection?'اتصال و مجوز غرفه را در خانهٔ ووسلام بررسی کنید.':'برای دریافت آخرین گزارش، ارتباط با سرویس را دوباره بررسی کنید.',`<div class="button-row">${button(connection?'بررسی اتصال غرفه':'دریافت دوبارهٔ گزارش',connection?'connection':'refresh','')}${button('راهنمای رفع مشکل','troubleshoot')}</div>`,'info');}
  function plansUnavailable(){return view.paymentFollowup()+empty('دریافت پلن‌های اشتراک',plansBusy?'در حال دریافت قیمت‌ها و ظرفیت‌ها…':'قیمت‌ها از سرویس اشتراک دریافت می‌شوند.',button('دریافت پلن‌ها','plans-refresh'),'wallet');}
  let coverageTrigger=null;
  function coverageFocus(rootNode){
    const el=document.activeElement;if(!rootNode.contains(el))return null;
    return {id:el.id,action:el.dataset.action,product:el.dataset.product,name:el.name,value:el.value,start:el.selectionStart,end:el.selectionEnd};
  }
  function restoreCoverageFocus(rootNode,key){
    if(!key)return;
    const el=[...rootNode.querySelectorAll('input,button,summary')].find(el=>key.id?el.id===key.id:key.action?el.dataset.action===key.action&&el.dataset.product===key.product:key.name?el.name===key.name&&el.value===key.value:false);
    if(el&&!el.disabled){el.focus({preventScroll:true});if(key.start!==null&&key.start!==undefined)try{el.setSelectionRange(key.start,key.end);}catch{}}
  }
  function openReplacement(html,trigger){
    coverageTrigger=trigger;$('#coverage-dialog-body').innerHTML=html;icons();
    $('#coverage-dialog').showModal();$('#coverage-replacement-search')?.focus({preventScroll:true});
  }
  function renderReplacement(html){
    const body=$('#coverage-dialog-body'),key=coverageFocus(body),list=body.querySelector('.coverage-candidate-list'),scroll=list?.scrollTop||0;
    body.innerHTML=html;icons();const next=body.querySelector('.coverage-candidate-list');if(next)next.scrollTop=scroll;
    restoreCoverageFocus(body,key);$('#coverage-dialog [data-action=coverage-cancel]').disabled=coverage.saving;
  }
  function closeReplacement(){
    $('#coverage-dialog').close();$('#coverage-dialog-body').innerHTML='';
    if(coverageTrigger?.isConnected)coverageTrigger.focus({preventScroll:true});
    else $('#tab-coverage').focus({preventScroll:true});
  }
  function focusCoverageProduct(){
    const id=coverageTrigger?.dataset.product;
    const el=[...$('#panel-coverage').querySelectorAll('button[data-product]')].find(el=>el.dataset.product===id&&!el.disabled);
    (el||$('#coverage-search'))?.focus({preventScroll:true});
  }
  const panelMarkup=new WeakMap();
  function replacePanel(id,html){
    const panel=$(id);
    // Compare generated markup: icons and user interaction can change the live DOM.
    if(id==='#panel-subscription'&&panelMarkup.get(panel)===html)return;
    const open=[...panel.querySelectorAll('details[open]')].filter(el=>!el.classList.contains('coverage-more')).map(el=>el.dataset.key||[...panel.querySelectorAll('details')].indexOf(el));
    const focus=id==='#panel-coverage'?coverageFocus(panel):null;
    const focused=panel.contains(document.activeElement)?document.activeElement:null,key=focused?.id||focused?.dataset.action;
    panel.innerHTML=html;panelMarkup.set(panel,html);panel.querySelectorAll('details').forEach((el,i)=>{if(open.includes(el.dataset.key||i))el.open=true;});
    if(focus)restoreCoverageFocus(panel,focus);
    else if(key)[...panel.querySelectorAll('[id],[data-action]')].find(el=>el.id===key||el.dataset.action===key)?.focus({preventScroll:true});
  }
  function shell(){const activation=data.available&&!data.subscription.expires_at;$('.tabs').hidden=activation;$('.report-toolbar').hidden=activation;$('#freshness').innerHTML=data.available?`${data.stale?badge('گزارش ذخیره‌شده','warning','time'):icon('time')}<span>آخرین دریافت: ${time(data.fetched_at)}</span>`:'<span>منتظر دریافت گزارش</span>';$('#page-notice').innerHTML=data.stale?notice('آخرین گزارش ذخیره‌شده','برای دریافت وضعیت تازه، دوباره گزارش را دریافت کنید.','warning'):'';}
  function renderPanel(tab=state.tab){view.update(data,plans);replacePanel(`#panel-${tab}`,tab==='overview'?view.overview():tab==='settings'?view.settingsPanel():tab==='history'?historyPanel():tab==='coverage'?(data.available?coverage.render():unavailable()):view.subscriptionPanel());renderPaymentToast();icons();if(tab==='subscription')warmPurchase();}
  function render(){shell();renderPanel();void selectTab(state.tab,false,false);}
  async function selectTab(tab,focus=false,load=true){if(!tabs.includes(tab))return;if(data.available&&!data.subscription.expires_at&&!['overview','subscription'].includes(tab))tab='overview';state.tab=tab;paymentPoller.sync();root.dataset.section=tab;$('#monitoring-help').hidden=!data.available||!data.subscription.expires_at||tab==='coverage'||(tab==='subscription'&&!data.subscription.active);if(tab!=='coverage')coverage.cancel();$('.report-toolbar').hidden=tab!=='overview'||(data.available&&!data.subscription.expires_at);$$('.tabs [role=tab]').forEach(el=>{const chosen=el.dataset.tab===tab;el.setAttribute('aria-selected',String(chosen));el.tabIndex=chosen?0:-1;if(focus&&chosen)el.focus();});$$('[role=tabpanel]').forEach(el=>el.hidden=el.id!==`panel-${tab}`);const url=new URL(location.href);url.searchParams.set('tab',tab);url.hash=tab;history.replaceState(null,'',url);if(load)renderPanel();else renderPaymentToast();if(tab==='history'&&!pages.issues.loaded&&!pages.issues.busy&&data.available)await loadPage('issues');if(tab==='history'&&!pages.history.loaded&&!pages.history.busy&&data.available)await loadPage('history');if(tab==='coverage'&&!coverage.loaded&&!coverage.busy&&data.available)await coverage.load();if(tab==='subscription'&&data.available&&load){await Promise.allSettled([loadPlans(),paymentStatus(params.has('payment_return'),{background:paymentLoaded})]);if(state.tab===tab&&!$('#detail-dialog').open)$('.subscription-offers')?.scrollIntoView({block:'start',behavior:'instant'});}maybeTour();}
  const tourKey=()=>`woosalam-monitoring-tour-v3:${data.onboarding_scope||data.scope||location.host}`;
  function startTour(trigger){if(!data.available||data.stale||!data.subscription.active)return;clearTimeout(tourTimer);$('#detail-dialog').close();window.MonitoringTour?.start({selectTab,trigger,hasReport:!data.empty||data.subscription.products.history.aggregate.total_scans>0||data.subscription.products.benefits.correction_events>0,needsSubscription:!data.subscription.expires_at,onFinish(result){if(['completed','skipped'].includes(result.reason))try{localStorage.setItem(tourKey(),result.reason);}catch{}}});}
  function maybeTour(){clearTimeout(tourTimer);let seen=false;try{seen=!!localStorage.getItem(tourKey());}catch{}if(seen||!data.available||data.stale||!data.subscription.active||data.subscription.capacity_exceeded||state.tab!=='overview'||document.hidden||window.MonitoringTour?.isOpen||$('#detail-dialog').open)return;tourTimer=setTimeout(()=>startTour($('#monitoring-help')),700);}
  function renderPaymentToast(){
    const el=$('#payment-toast');
    const visible=view.paymentToastAllowed()&&!state.paymentNoticeHidden&&state.tab==='subscription'&&!$('#detail-dialog').open;
    if(!visible){const focused=el.contains(document.activeElement);el.hidden=true;if(focused)$('[data-action=payment-open]')?.focus({preventScroll:true});return;}
    hideToast();
    const html=view.paymentToast();
    if(el.dataset.content!==html){el.innerHTML=html;el.dataset.content=html;icons();}
    el.className=`feedback-toast ${paymentDetails().tone}`;
    el.hidden=false;
  }
  function hideToast(){clearTimeout(toastTimer);$('#toast').hidden=true;}
  function toast(message,tone='success'){paymentFeedback.dismiss();const el=$('#toast');clearTimeout(toastTimer);el.className=`feedback-toast ${tone}`;el.innerHTML=`<span class="toast-symbol">${icon(tone==='success'?'circle-checkmark':'info')}</span><div class="toast-copy" role="${tone==='error'?'alert':'status'}"><strong>${tone==='error'?'نیاز به پیگیری':'وضعیت پایشگر'}</strong><p>${esc(message)}</p></div><button type="button" class="toast-close" data-action="close-toast" aria-label="بستن پیام">${icon('close')}</button><span class="toast-progress" aria-hidden="true"></span>`;($('#detail-dialog').open?$('#detail-dialog'):root).append(el);el.hidden=false;icons();const resume=()=>{clearTimeout(toastTimer);toastTimer=setTimeout(hideToast,6500);};el.onmouseenter=el.onfocusin=()=>clearTimeout(toastTimer);el.onmouseleave=el.onfocusout=resume;resume();}
  function openDialog(title,body,trigger){state.paymentDialog=false;const dialog=$('#detail-dialog');if(!dialog.open)dialogTrigger=trigger||document.activeElement;$('#dialog-title').textContent=title;$('#dialog-kicker').textContent='';$('#dialog-body').innerHTML=body;icons();if(!dialog.open)dialog.showModal();$('#detail-dialog [data-action=close-dialog]').focus();$('#dialog-body').scrollTop=0;dialog.scrollTop=0;}
  async function refresh(manual=false,{duringCheckout=false}={}){if(statusBusy||(state.checkoutBusy&&!duringCheckout)||state.settingsBusy)return;statusBusy=true;const control=$('.report-toolbar [data-action=refresh]');control.disabled=true;try{const result=await request('status',{refresh:manual?'1':'0'});if(data.scope&&result.model.scope!==data.scope)clearPrivate();if(['connection_changed','authorization'].includes(result.model.error)||!result.model.connected)clearPrivate();data=normalize(result.model);scenario();if(data.available&&!data.stale)state.settingsUnknown=false;render();if(manual)toast(data.available&&!data.stale?'آخرین گزارش دریافت شد.':'برای دریافت گزارش، اتصال سرویس را بررسی کنید.',data.available&&!data.stale?'success':'error');maybeTour();}catch(error){data.stale=data.available;if(!data.available)state.scenario='unavailable';render();if(manual)toast(error.message,'error');}finally{statusBusy=false;control.disabled=false;paymentPoller.sync();}}
  function pagination(kind){const p=pages[kind];return `<div class="pagination">${button('جدیدتر',`${kind}-prev`,'secondary small',p.index===0||p.busy?'disabled':'')}<span>صفحهٔ ${n(p.index+1)}</span>${button('قدیمی‌تر',`${kind}-next`,'secondary small',!p.next||p.busy?'disabled':'')}</div>`;}
  function historyPanel(){if(!data.available)return unavailable();const p=pages.history;return `<section class="history-attention card" id="issues-section" data-tour="issues"><div class="section-heading"><div><h2>موارد فعلی نیازمند رسیدگی</h2><p>آخرین وضعیت محصولات؛ آمار دورهای قبلی در ادامه حفظ می‌شود.</p></div>${button('تازه‌سازی موارد','issues-refresh','secondary small')}</div>${pages.issues.error?notice('فهرست رسیدگی دریافت نشد',esc(pages.issues.error),'warning'):''}${view.issuesList(pages.issues.loaded?pages.issues.rows:undefined)}${pages.issues.next||pages.issues.index?pagination('issues'):''}</section>`+heading('تاریخچهٔ دورهای پایش','',button('تازه‌سازی تاریخچه','history-refresh','secondary small'))+(p.error?notice('تاریخچه دریافت نشد',esc(p.error),'warning',button('تلاش دوباره','history-retry','secondary small')):'')+(p.rows.length?`<div class="history-list" data-tour="history" aria-busy="${p.busy}">${p.rows.map(row=>view.scanRow(row).replace('<details class="scan-row"','<details class="scan-row" data-key="scan-'+Number(row.id)+'"')).join('')}</div>${pagination('history')}`:p.busy?'<p role="status">در حال دریافت تاریخچه…</p>':empty('هنوز دوری ثبت نشده','نتیجه پس از اولین پایش نمایش داده می‌شود.','','time'));}
  async function loadPage(kind,index=pages[kind].index){const p=pages[kind];if(p.busy)return;p.busy=true;p.error='';const version=epoch,serial=p.serial;if(['history','issues'].includes(kind))renderPanel('history');try{const result=await request(kind,{...(p.cursors[index]?{cursor:String(p.cursors[index])}:{}),...(kind==='changes'?{scan:String(p.scan.id)}:{})});if(!current(result,version)||(kind==='changes'&&serial!==p.serial))return;p.rows=result.data;p.next=result.next_cursor;p.index=index;p.loaded=true;p.meta=result;}catch(error){if(version===epoch)p.error=error.message;}finally{if(version===epoch&&(kind!=='changes'||serial===p.serial)){p.busy=false;if(['history','issues'].includes(kind))renderPanel('history');else if($('#detail-dialog').open)renderChanges();}}}
  function renderChanges(){const p=pages.changes,scan=p.scan,total=p.meta?.total_products;let body=p.error?notice('جزئیات دریافت نشد',esc(p.error),'warning',button('دریافت دوباره','changes-retry')):'';if(p.meta)body+=`<div class="dialog-summary-stats"><div><strong>${n(total)}</strong><span>محصول اصلاح‌شده</span></div><div><strong>${n(p.meta.total_changes)}</strong><span>اصلاح تأییدشده</span></div></div>`;if(total!=null&&scan.summary.updated_products!=null&&total!==scan.summary.updated_products&&scan.status==='completed')body+=notice('جزئیات موجود',`از ${n(scan.summary.updated_products)} محصول در خلاصه، جزئیات ${n(total)} محصول ثبت شده است.`);body+=p.rows.map(c=>`<article class="change-item"><div class="change-title"><span class="product-symbol">${icon('box')}</span><div><h3>${esc(c.title||'محصول بدون عنوان')}</h3><p>ووکامرس ${n(c.woo_product_id)}${c.variation_id?` · تنوع ${n(c.variation_id)}`:''} · باسلام ${n(c.basalam_product_id)}</p>${!c.title_is_snapshot?'<p>عنوان فعلی محصول</p>':''}</div></div><div class="change-fields">${(c.fields||[]).map(f=>`<div class="field-change"><span class="field-label">${f.field==='primary_price'?'قیمت · تومان':'موجودی · عدد'}</span><span class="field-value"><small>قبل در باسلام</small>${n(f.before==null?null:f.before/(f.field==='primary_price'?10:1),1)}</span>${icon('arrow-left')}<span class="field-value after"><small>بعد از تأیید</small>${n(f.after==null?null:f.after/(f.field==='primary_price'?10:1),1)}</span></div>`).join('')}</div><details class="inline-details"><summary>مرجع ووسلام در زمان اصلاح</summary>${(c.fields||[]).map(f=>`<p>${f.field==='primary_price'?'قیمت':'موجودی'}: ${f.field==='primary_price'?money(f.source):n(f.source)}</p>`).join('')}</details><p class="change-time">تأیید در ${date(c.verified_at)}</p></article>`).join('');if(!p.rows.length&&!p.error)body+=p.busy?'<p role="status">در حال دریافت اصلاح‌ها…</p>':empty('اصلاحی برای این دور ثبت نشده','وضعیت محصولات را در جزئیات دور ببینید.','','info');if(p.rows.length)body+=pagination('changes');$('#dialog-title').textContent=`اصلاح‌های دور ${n(scan.id)}`;$('#dialog-body').innerHTML=body;icons();}
  async function loadPlans(force=false){if(plansBusy||(plans.length&&!force))return;plansBusy=true;const version=epoch;try{const result=await request('plans');if(!current(result,version))return;plans=result.data.filter(p=>typeof p.code==='string'&&p.product_limit>0&&p.price_toman>=0&&p.period_days>0);if(!plans.some(p=>p.code===state.selected))state.selected=plans.find(p=>p.code===state.payment?.plan_code)?.code||plans.find(p=>p.code===data.subscription.plan.code)?.code||plans[0]?.code||'';}catch(error){if(version===epoch)statusText(error.message);}finally{plansBusy=false;if(version===epoch&&state.tab==='subscription')renderPanel();}}
  function adoptPayment(result,notify=true){if(!state.planTouched&&plans.some(p=>p.code===result.plan_code))state.selected=result.plan_code;paymentKey=uuid(result.idempotency_key)?result.idempotency_key:null;state.paymentUnknown=false;state.payment=['none','not_started'].includes(result.status)?null:{...result,key:paymentKey,status:result.scheduled?'scheduled':result.activated?'success':result.needs_review?'review':result.status==='succeeded'?'activation':result.status==='failed'?'failed':result.superseded?'superseded':result.processing?'processing':result.expired?'expired':safeUrl(result.payment_url,true)?'pending':'unknown'};state.terms=false;paymentFeedback.sync(state.payment,{notify});}
  function continuePayment(){
    const payment=state.payment,url=safeUrl(payment?.payment_url,true);
    if(payment?.status!=='pending'||!url)return;
    const navigation=new CustomEvent('woosalam:payment-redirect',{cancelable:true,detail:{url,payment}});
    if(root.dispatchEvent(navigation))location.assign(url);
  }
  async function paymentStatus(check=true,{background=false}={}){
    if(paymentBusy||state.checkoutBusy||!data.available)return;
    const wasPaymentDialog=state.paymentDialog,version=epoch,previousMessage=state.paymentMessage;
    let finishRequest;
    paymentRequest=new Promise(resolve=>{finishRequest=resolve;});
    paymentBusy=true;paymentPoller.sync();
    if(!background){
      state.paymentChecking=true;state.paymentMessage='در حال بررسی پرداخت…';
      if(state.tab==='subscription')renderPanel();
      if(wasPaymentDialog&&$('#detail-dialog').open)showPaymentDialog(true);
    }
    const previousDetails=wasPaymentDialog?view.paymentSummary():'';
    try{
      const result=await request(check?'purchase_check':'purchase_status',paymentKey?{key:paymentKey}:{});
      if(!current(result,version))return;
      adoptPayment(result,check||!!state.payment);
      state.paymentMessage='';
      if(result.activated||result.scheduled)await refresh();
      celebrateActivation(result);
    }catch(error){if(version===epoch){state.paymentUnknown=true;state.paymentMessage=error.message;if(!background&&previousMessage!==error.message)toast(error.message,'error');}}
    finally{
      paymentBusy=false;paymentRequest=null;state.paymentChecking=false;
      if(version===epoch){
        paymentLoaded=true;
        if(state.tab==='subscription'){
          renderPanel();
          if(wasPaymentDialog&&state.paymentDialog&&$('#detail-dialog').open&&(!background||previousDetails!==view.paymentSummary()||previousMessage!==state.paymentMessage))showPaymentDialog(true);
        }
      }
      paymentPoller.sync();finishRequest();
    }
  }
  const celebrated=new Set();
  let expectedActivation=paymentKey,activationObserved=false;
  function celebrateActivation(result){
    if(result.status==='pending'&&uuid(result.idempotency_key))expectedActivation=result.idempotency_key;
    if(!result.activated||result.status!=='succeeded'||result.scheduled||result.needs_review||!data.subscription.active||data.stale||state.tab!=='subscription')return;
    const key=result.idempotency_key;
    if(!uuid(key)||key!==expectedActivation)return;
    const marker=`woosalam-activated:${data.onboarding_scope||data.scope||location.host}:${key}`;
    let seen=celebrated.has(marker);try{seen=seen||localStorage.getItem(marker)==='1';}catch{}
    if(seen){activationObserved=true;return;}
    celebrated.add(marker);try{localStorage.setItem(marker,'1');}catch{}
    activationObserved=true;
    paymentFeedback.dismiss();
    openDialog('اشتراک شما فعال شد',view.activationSuccess(),$('[data-action=checkout]'));
  }
  function showPaymentDialog(update=false,trigger){
    const body=state.payment?view.paymentSummary():notice('بررسی پرداخت',esc(state.paymentMessage||'برای دریافت نتیجهٔ آخرین پرداخت، دوباره بررسی کنید.'),'info',button('بررسی دوباره','payment-check','secondary',state.paymentChecking?'disabled':''));
    if(update){$('#dialog-body').innerHTML=body;icons();return;}
    const returnFocus=$('#payment-toast').contains(trigger)?$('#panel-subscription [data-action=payment-open]'):trigger;
    paymentFeedback.dismiss();
    openDialog('پیگیری پرداخت',body,returnFocus);
    state.paymentDialog=true;
  }
  function quoteMatches(a,b){return ['code','product_limit','price_toman','period_days','terms_version'].every(k=>a[k]===b[k]);}
  async function checkedPlan(plan){
    const pre=await request('purchase_preflight',{plan});
    if(!pre.eligible)throw new Error([pre.message,pre.guidance].filter(Boolean).join(' ')||'اتصال فروشگاه را بررسی کنید.');
    if(pre.plan?.code!==plan||!(pre.plan.price_toman>=0)||!(pre.plan.product_limit>0)||!(pre.plan.period_days>0))throw new Error('اطلاعات پلن را دوباره دریافت کنید.');
    return pre.plan;
  }
  function canChangePlan(){return !state.payment||state.payment.replaceable===true||['failed','success'].includes(state.payment.status);}
  function canCheckout(){return !state.paymentUnknown&&!!state.selected&&!data.stale&&!data.subscription.active&&!(Date.parse(data.subscription.expires_at)>Date.now())&&!data.subscription.next_plan&&canChangePlan();}
  function checkoutStatus(message='',tone='info'){state.checkoutMessage=message;if(message)toast(message,tone);}
  // Ownership and plan checks move no money, and Gateway keeps its approval for
  // ten minutes. Resolving them while the seller reads the plans leaves only the
  // invoice for the click. The click trusts one fresh result for the same plan;
  // otherwise it runs the same checks itself.
  const READY_MS=480000,READY_RETRY_MS=60000;
  let readiness=null,readinessTimer=0,readinessQueued='';
  async function purchaseReadiness(code){
    const ready=await request('purchase_prepare');
    if(!ready.ready)throw new Error('اتصال و مجوز غرفه را در ووسلام بررسی کنید.');
    return checkedPlan(code);
  }
  const readinessKnown=code=>!!readiness&&readiness.code===code&&readiness.version===epoch&&Date.now()-readiness.at<(readiness.failed?READY_RETRY_MS:READY_MS);
  function warmPurchase(){
    const code=state.selected,allowed=()=>state.selected===code&&state.tab==='subscription'&&!document.hidden&&!state.checkoutBusy&&canCheckout()&&plans.some(p=>p.code===code);
    if(!allowed()){clearTimeout(readinessTimer);readinessQueued='';return;}
    if(readinessQueued===code||readinessKnown(code))return;
    clearTimeout(readinessTimer);readinessQueued=code;
    // A short pause lets a seller move between plans without checking each one.
    readinessTimer=setTimeout(()=>{
      readinessQueued='';
      if(!allowed()||readinessKnown(code))return;
      const entry={code,version:epoch,at:Date.now(),failed:false};
      entry.plan=purchaseReadiness(code).then(plan=>{entry.at=Date.now();return plan;},()=>{entry.failed=true;entry.at=Date.now();return null;});
      readiness=entry;
    },400);
  }
  async function checkout(){
    if(state.checkoutBusy||(paymentBusy&&!paymentRequest)||!canCheckout())return;
    const plan=plans.find(p=>p.code===state.selected),version=epoch;if(!plan)return;
    const pendingCheck=paymentRequest;
    state.checkoutBusy=true;paymentPoller.sync();checkoutStatus('در حال آماده‌سازی پرداخت…');renderPanel();
    let refreshSubscription=false;
    try{
      // A click during a silent check waits for its result, then rechecks eligibility.
      if(pendingCheck){
        await pendingCheck;
        if(version!==epoch)return;
        if(['success','scheduled'].includes(state.payment?.status))await refresh(false,{duringCheckout:true});
        if(version!==epoch||!canCheckout())return;
      }
      // Used once: a second click checks again instead of trusting this result.
      const prepared=readiness;readiness=null;
      let fresh=prepared&&!prepared.failed&&prepared.code===plan.code&&prepared.version===version&&Date.now()-prepared.at<READY_MS?await prepared.plan:null;
      if(version!==epoch)return;
      if(!fresh){
        // Remember a failure the seller has just been told about, so it is not repeated silently.
        try{fresh=await purchaseReadiness(plan.code);}
        catch(error){readiness={code:plan.code,version,at:Date.now(),failed:true,plan:Promise.resolve(null)};throw error;}
        if(version!==epoch)return;
      }
      if(!quoteMatches(plan,fresh)){
        plans=plans.map(p=>p.code===fresh.code?fresh:p);
        checkoutStatus('مشخصات پلن به‌روز شد؛ مبلغ تازه را ببینید و دوباره خرید را بزنید.');return;
      }
      if(state.payment?.status==='pending'&&state.payment.plan_code===plan.code&&state.payment.amount===plan.price_toman*10&&safeUrl(state.payment.payment_url,true)){
        checkoutStatus('در حال رفتن به پرداخت باسلام…');continuePayment();return;
      }
      if(['failed','success'].includes(state.payment?.status)){
        const reset=await request('purchase_new',paymentKey?{key:paymentKey}:{});if(!current(reset,version))return;
        adoptPayment(reset);
      }
      state.paymentUnknown=true;
      const result=await request('purchase_checkout',{plan:plan.code,accepted:'1',...(state.payment?.replaceable&&paymentKey?{key:paymentKey}:{})});
      if(!current(result,version))return;
      adoptPayment(result);expectedActivation=paymentKey;checkoutStatus();
      if(result.activated||result.scheduled)refreshSubscription=true;
      const url=safeUrl(result.payment_url,true);
      if(state.payment?.status==='pending'&&url&&result.plan_code===plan.code&&result.amount===plan.price_toman*10){
        checkoutStatus('در حال رفتن به پرداخت باسلام…');continuePayment();
      }
    }catch(error){
      if(version===epoch){checkoutStatus(error.message,'error');if(error.code==='subscription_active'){state.paymentUnknown=false;refreshSubscription=true;}}
    }finally{
      state.checkoutBusy=false;
      if(version===epoch){if(refreshSubscription){await refresh();await paymentStatus(false);}else if(state.paymentUnknown){await paymentStatus(true);}renderPanel();}
      paymentPoller.sync();
    }
  }
  async function newPurchase(){
    if(paymentBusy||state.checkoutBusy)return;
    paymentBusy=true;paymentPoller.sync();const version=epoch;
    try{
      const result=await request('purchase_new',paymentKey?{key:paymentKey}:{});
      if(!current(result,version))return;
      adoptPayment(result);state.paymentMessage='';state.lastPaymentShown='';$('#detail-dialog').close();
      const url=new URL(location.href);url.searchParams.delete('idempotency_key');url.searchParams.delete('payment_return');
      history.replaceState(null,'',url);renderPanel();
    }catch(error){state.paymentMessage=error.message;toast(error.message,'error');renderPanel();}finally{paymentBusy=false;paymentPoller.sync();}
  }
  async function changeRepair(enabled){if(state.settingsBusy||data.stale||!data.subscription.active||data.subscription.capacity_exceeded||state.settingsUnknown||(enabled&&!state.repairAccepted))return;const version=epoch;state.settingsBusy=true;$('#detail-dialog').close();renderPanel();try{const result=await request('settings',{enabled:enabled?'1':'0',accepted:enabled?'1':'0'});if(!current(result.model,version))return;data=normalize(result.model);state.settingsUnknown=false;state.repairAccepted=false;toast(enabled?'اصلاح خودکار فعال شد.':'اصلاح خودکار خاموش شد. پایش محصولات ادامه دارد.');}catch(error){if(version===epoch){state.settingsUnknown=true;toast(error.message,'error');}}finally{state.settingsBusy=false;if(version===epoch)render();}}
  function help(trigger){openDialog('راهنمای پایشگر',`<div class="guide-invitation"><span class="trouble-icon">${icon('chart-line')}</span><div><h3>با پایشگر قدم‌به‌قدم آشنا شوید</h3><p>معنای آمار و کاربرد کنترل‌ها را روی داشبورد ببینید.</p></div>${button('شروع راهنمای تعاملی','start-tour','',!data.available||!data.subscription.active||data.stale?'disabled':'')}</div><details class="inline-details"><summary>معنای آمار و گزارش‌ها</summary><ol class="help-list"><li><strong>دریافت گزارش</strong>آخرین نتیجهٔ ثبت‌شده را نشان می‌دهد؛ پایش تازه‌ای آغاز نمی‌کند.</li><li><strong>مغایرت و اصلاح</strong>مغایرت اختلاف اولیه است؛ اصلاح پس از تأیید باسلام ثبت می‌شود.</li><li><strong>اثر پایشگر</strong>محصولات متمایز و اختلاف قیمت اصلاح‌شده را نشان می‌دهد. این مبلغ به معنی سود فروش نیست.</li><li><strong>ظرفیت</strong>هر محصول با تمام تنوع‌هایش یک محصول حساب می‌شود.</li></ol></details>${view.reportDetails()}`,trigger);}
  async function startNextPeriod(){
    const sub=data.subscription,revision=sub.coverage?.revision;
    if(state.coverageStartBusy||state.checkoutBusy||data.stale||!sub.active||sub.next_plan?.can_start_early!==true||!(sub.next_plan?.product_limit>sub.plan.product_limit)||!Number.isSafeInteger(revision)||revision<0)return;
    const version=epoch;state.coverageStartBusy=true;
    const control=$('[data-action=confirm-next-period]');if(control){control.disabled=true;control.textContent='در حال بررسی شروع دوره…';}
    try{
      const result=await request('coverage_start_next',{revision:String(revision)});
      if(!current(result,version))return;
      coverage.reset();$('#detail-dialog').close();
      toast('درخواست شروع دوره ثبت شد؛ وضعیت تازهٔ اشتراک دریافت می‌شود.');
    }catch(error){if(version===epoch){$('#detail-dialog').close();toast(error.message+' نتیجهٔ اشتراک را پیش از اقدام دوباره بررسی کنید.','error');}}
    finally{state.coverageStartBusy=false;if(version===epoch)await refresh(true);}
  }
  function productGuide(item,trigger){
    const reason=item.reason,r=reasons[reason]||reasons.unknown,url=safeUrl(item.edit_url);
    let steps,actionButton='';
    if(['values_differ','verification_mismatch','repair_batch_pending','manual_repair_unconfirmed'].includes(reason)){
      steps=[['مقدار مرجع را بررسی کنید','در جدول، قیمت و موجودی ستون «مرجع ووکامرس» را ببینید. این مقادیر با تنظیمات ووسلام محاسبه شده‌اند. اگر درست نیستند، ابتدا محصول یا تنظیمات آن را در ووکامرس اصلاح کنید.'],['مقدار درست را به باسلام بفرستید','«اعمال مقدار ووکامرس» را بزنید و تأیید کنید. قیمت و موجودی همین محصول و تنوع‌های متصل آن به‌روز می‌شود.'],['نتیجه را ببینید','پس از بررسی پاسخ باسلام، نتیجه در تاریخچه تازه می‌شود. اگر مغایرت باقی بود، شناسهٔ محصول را برای پشتیبانی بفرستید.']];
      if(data.subscription.active&&!data.stale)actionButton=button(`${icon('refresh')} اعمال مقدار ووکامرس`,'manual-repair','',`data-product="${item.woo_product_id}"`);
    }else if(['mapping_missing','mapping_incomplete','remote_owner_mismatch','source_removed'].includes(reason)){
      steps=[['محصول را در ووکامرس باز کنید','وضعیت انتشار و اتصال این محصول به غرفهٔ باسلام را ببینید.'],['اتصال را اصلاح کنید','در بخش ووسلام محصول، مطمئن شوید محصول و تمام تنوع‌ها به مورد درست در غرفهٔ شما متصل‌اند؛ سپس تغییرها را ذخیره کنید.'],['منتظر بررسی بعدی بمانید','پایشگر اتصال اصلاح‌شده را در دور بعد بررسی می‌کند. دریافت گزارش، دور جدیدی آغاز نمی‌کند.']];
    }else if(['timeout','rate_limited','source_changed','coverage_changed','repair_already_attempted'].includes(reason)){
      steps=[['فعلاً تغییری در محصول لازم نیست',r[1]],['نتیجهٔ دور بعد را ببینید','پایشگر بررسی را دوباره انجام می‌دهد. پس از دور بعد، «تازه‌سازی موارد» را بزنید.'],['اگر مشکل تکرار شد','نام محصول، شناسهٔ ووکامرس و زمان آخرین بررسی را برای پشتیبانی بفرستید.']];
      actionButton=button('دریافت وضعیت تازه','guide-refresh','secondary');
    }else if(reason==='permission_denied'){
      steps=[['اتصال غرفه را باز کنید','در خانهٔ ووسلام، اتصال حساب باسلام را بررسی و در صورت نیاز دوباره برقرار کنید.'],['دوباره نتیجه را ببینید','پس از برقراری اتصال و اجرای دور بعد، فهرست موارد را تازه کنید.']];
      actionButton=button('رفتن به اتصال غرفه','connection','');
    }else{
      steps=[['این مورد را بررسی کنید',r[2]],['نتیجه را پیگیری کنید','پس از رفع مشکل، نتیجهٔ دور بعد را در تاریخچه ببینید. اگر مشکل باقی ماند، شناسهٔ محصول و زمان بررسی را برای پشتیبانی بفرستید.']];
    }
    return openDialog('مراحل رفع مشکل محصول',`<div class="issue-guide-title"><span class="product-symbol">${icon('box')}</span><div><h3>${esc(item.title)}</h3><p>${esc(r[0])}</p></div></div><ol class="issue-guide-steps">${steps.map(([title,text],i)=>`<li><span>${n(i+1)}</span><div><h4>${esc(title)}</h4><p>${esc(text)}</p></div></li>`).join('')}</ol><div class="issue-guide-meta">شناسهٔ ووکامرس: <bdi>${n(item.woo_product_id)}</bdi> · بررسی در ${date(item.checked_at)}</div><div class="dialog-actions">${actionButton}${url?`<a class="button secondary" href="${esc(url)}" target="_blank" rel="noopener">بازکردن محصول در ووکامرس</a>`:''}<a class="text-button" href="${esc(safeUrl(config.supportUrl))}">پیام به پشتیبانی ${icon('arrow-left')}</a></div>`,trigger);
  }
  async function action(name,el){
    if(name.startsWith('go-')&&tabs.includes(name.slice(3))){$('#detail-dialog').close();return selectTab(name.slice(3),true);}
    if(name==='start-next-period'){
      const next=data.subscription.next_plan;
      if(!next||next.can_start_early!==true||!data.subscription.active||next.product_limit<=data.subscription.plan.product_limit||data.stale||state.coverageStartBusy)return;
      return openDialog('پلن بعدی زودتر شروع شود؟',`<p>با شروع «${esc(next.title)}»، دورهٔ فعلی همین حالا پایان می‌یابد و زمان باقی‌ماندهٔ آن منتقل نمی‌شود. دورهٔ ${n(next.period_days)}روزهٔ جدید از اکنون شروع می‌شود و ظرفیت به ${n(next.product_limit)} محصول می‌رسد.</p><div class="dialog-actions">${button('تأیید و شروع پلن بعدی','confirm-next-period','')}${button('ادامهٔ دورهٔ فعلی','close-dialog','secondary')}</div>`,el);
    }
    if(name==='confirm-next-period')return startNextPeriod();
    if(name.startsWith('coverage-'))return coverage.action(name,el);
    if(name==='refresh'||name==='check-settings')return refresh(true);
    if(name==='help'||name==='repair-help')return help(el);
    if(name==='start-tour')return startTour($('#monitoring-help'));
    if(name==='close-dialog')return $('#detail-dialog').close();
    if(name==='close-toast')return hideToast();
    if(name==='connection'){const url=safeUrl(config.connectionUrl);if(url)location.assign(url);return;}
    if(name==='troubleshoot')return openDialog('راهنمای دریافت گزارش',`<ol class="help-list"><li><strong>دریافت دوباره</strong>چند لحظه بعد، گزارش را دوباره دریافت کنید.</li><li><strong>بررسی اتصال</strong>در خانهٔ ووسلام، مجوز اتصال غرفه را بررسی کنید.</li><li><strong>پیگیری پشتیبانی</strong>اگر مشکل ادامه داشت، زمان رخداد و کد «${esc(data.error||'unavailable')}» را به پشتیبانی بدهید.</li></ol><div class="dialog-actions">${button('بررسی اتصال غرفه','connection','')}${button('بستن راهنما','close-dialog')}</div>`,el);
    if(name==='go-issues'){await selectTab('history');$('#issues-section')?.scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});$('#issues-section summary')?.focus({preventScroll:true});return;}
    if(name==='issues-refresh'){pages.issues.cursors=[null];return loadPage('issues',0);}
    if(name==='more-issues'){const expanded=el.getAttribute('aria-expanded')==='true';$$('[data-extra-issue]').forEach(row=>row.hidden=expanded);el.setAttribute('aria-expanded',String(!expanded));el.textContent=expanded?'نمایش موارد بیشتر':'نمایش کمتر';return;}
    if(name==='product-guide'){const item=[...pages.issues.rows,...data.subscription.products.recent_issues].find(x=>String(x.woo_product_id)===el.dataset.product);if(item)return productGuide(item,el);return;}
    if(name==='guide-refresh'){$('#detail-dialog').close();pages.issues.cursors=[null];return loadPage('issues',0);}
    if(name==='history-refresh'){pages.history.cursors=[null];return loadPage('history',0);}
    if(name==='history-retry')return loadPage('history');
    if(name==='changes-retry')return loadPage('changes');
    if(/^(history|issues|changes)-(next|prev)$/.test(name)){const [kind,direction]=name.split('-'),p=pages[kind];if(p.busy)return;const index=p.index+(direction==='next'?1:-1);if(index<0||direction==='next'&&!p.next)return;if(direction==='next')p.cursors[index]=p.next;await loadPage(kind,index);const target=$(`[data-action="${name}"]`);if(target&&!target.disabled)target.focus();else if(kind==='changes')$('#detail-dialog [data-action=close-dialog]').focus();else $('#panel-history summary')?.focus();return;}
    if(name==='changes'){const scan=pages.history.rows.find(x=>String(x.id)===el.dataset.scan);if(!scan)return;Object.assign(pages.changes,{cursors:[null],index:0,next:null,rows:[],meta:null,scan,error:'',busy:false,serial:pages.changes.serial+1});openDialog(`اصلاح‌های دور ${n(scan.id)}`,'<p role="status">در حال دریافت اصلاح‌ها…</p>',el);return loadPage('changes');}
    if(name==='manual-repair'){
      if(data.stale||!data.subscription.active||state.manualBusy)return;
      const item=[...pages.issues.rows,...data.subscription.products.recent_issues].find(x=>String(x.woo_product_id)===el.dataset.product);
      if(!item)return;
      state.manualItem={id:item.woo_product_id,requestId:crypto.randomUUID()};
      return openDialog('هماهنگ‌سازی یک محصول',`<section class="repair-confirm"><span class="confirm-symbol">${icon('refresh')}</span><h3>${esc(item.title)}</h3><p class="confirm-copy">قیمت و موجودی فعلی ووکامرس برای همین محصول و تنوع‌های متصل آن، مطابق تنظیمات ووسلام روی باسلام اعمال می‌شود. تنظیم اصلاح خودکار تغییر نمی‌کند.</p><div class="dialog-actions">${button('انصراف','close-dialog','secondary')}${button('تأیید هماهنگ‌سازی','confirm-manual-repair','')}</div></section>`,el);
    }
    if(name==='confirm-manual-repair'){
      if(!state.manualItem||state.manualBusy||data.stale)return;
      const item=state.manualItem,version=epoch;state.manualBusy=true;el.disabled=true;el.textContent='در حال بررسی و هماهنگ‌سازی…';
      try{
        const result=await request('manual_repair',{product:String(item.id),request_id:item.requestId});
        if(!current(result,version))return;
        $('#detail-dialog').close();
        const ok=result.status==='completed'&&result.reason==='ok';
        toast(ok?'اطلاعات همین محصول با ووکامرس هماهنگ و تأیید شد.':`اصلاح کامل تأیید نشد. ${reasons[result.reason]?.[2]||'نتیجه را در تاریخچه بررسی کنید.'}`,ok?'success':'warning');
      }catch(error){if(version===epoch){$('#detail-dialog').close();toast(error.message+' پیش از اقدام دوباره، نتیجه را بررسی کنید.','warning');}}
      finally{state.manualBusy=false;state.manualItem=null;if(version===epoch){pages.issues.cursors=[null];pages.history.cursors=[null];await refresh(true);await loadPage('issues',0);await loadPage('history',0);}}
      return;
    }
    if(name==='repair-switch'){
      if(state.settingsBusy||data.stale||!data.subscription.active||data.subscription.capacity_exceeded||state.settingsUnknown)return;
      if(data.subscription.products.auto_repair_enabled)return action('repair-toggle',el);
      state.repairAccepted=false;state.repairDialog=true;
      return openDialog('روشن کردن اصلاح خودکار',`<section class="repair-confirm"><span class="confirm-symbol">${icon('wrench')}</span><h3>اختلاف‌ها خودکار اصلاح شوند؟</h3><p class="confirm-copy">قیمت و موجودی محصولات در حال پایش، مطابق مقدار مرجع ووکامرس و تنظیمات ووسلام روی باسلام اعمال می‌شود.</p><label class="consent"><input id="repair-consent" type="checkbox"><span>تأیید می‌کنم مقدار مرجع ووکامرس برای اصلاح قیمت و موجودی باسلام استفاده شود.</span></label><div class="dialog-actions">${button('انصراف','close-dialog','secondary')}${button('روشن کردن اصلاح خودکار','repair-toggle','','disabled')}</div></section>`,el);
    }
    if(name==='repair-toggle'){if(data.subscription.products.auto_repair_enabled)return openDialog('توقف اصلاح خودکار',`<section class="repair-confirm"><span class="confirm-symbol">${icon('wrench')}</span><h3>اصلاح‌ها را خودتان مدیریت می‌کنید؟</h3><p class="confirm-copy">پس از خاموش‌کردن، پایشگر همچنان قیمت و موجودی را بررسی می‌کند و اختلاف‌ها را در تاریخچه نشان می‌دهد.</p><div class="confirm-effects"><span>${icon('checkmark')} پایش و اشتراک ادامه دارد</span><span>${icon('time')} رسیدگی به اختلاف‌ها از تاریخچه</span></div><div class="dialog-actions">${button('روشن بماند','close-dialog','secondary')}${button('خاموش‌کردن اصلاح خودکار','confirm-repair-off','danger')}</div></section>`,el);return changeRepair(true);}
    if(name==='confirm-repair-off')return changeRepair(false);
    if(name==='plans-refresh')return loadPlans(true);
    if(name==='checkout')return checkout();
    if(name==='payment-open'||name==='payment-show')return showPaymentDialog(false,el);
    if(name==='payment-dismiss')return paymentFeedback.dismiss();
    if(name==='payment-continue')return continuePayment();
    if(name==='payment-check')return paymentStatus();
    if(name==='payment-replacement'&&uuid(state.payment?.replacement_key)){paymentKey=state.payment.replacement_key;return paymentStatus(false);}
    if(name==='payment-new')return newPurchase();
    if(name==='copy-payment'){if(!state.payment?.key)return;try{await navigator.clipboard.writeText(state.payment.key);toast('شناسهٔ پیگیری کپی شد.');}catch{toast('شناسهٔ پیگیری را انتخاب و کپی کنید.','info');}}
  }
  root.addEventListener('click',event=>{const el=event.target.closest('button');if(!el||el.disabled)return;if(el.dataset.tab)void selectTab(el.dataset.tab);if(el.dataset.action)void action(el.dataset.action,el);});
  root.addEventListener('error',event=>{if(event.target.matches?.('.coverage-image img'))event.target.hidden=true;},true);
  root.addEventListener('submit',event=>{if(event.target.matches('.coverage-search')){event.preventDefault();void coverage.action(event.target.dataset.coverageSearch==='replacement'?'coverage-replacement-search':'coverage-search');}});
  root.addEventListener('input',event=>{if(event.target.matches('#coverage-search,#coverage-replacement-search')&&!event.isComposing)coverage.input(event.target.value,event.target.id==='coverage-replacement-search');});
  root.addEventListener('compositionend',event=>{if(event.target.matches('#coverage-search,#coverage-replacement-search'))coverage.input(event.target.value,event.target.id==='coverage-replacement-search');});
  root.addEventListener('change',event=>{if(event.target.name==='coverage-candidate')void coverage.action('coverage-choose',event.target);});
  root.addEventListener('toggle',event=>{if(event.target.matches('.coverage-more')&&event.target.open)root.querySelectorAll('.coverage-more[open]').forEach(el=>{if(el!==event.target)el.open=false;});},true);
  document.addEventListener('click',event=>root.querySelectorAll('.coverage-more[open]').forEach(el=>{if(!el.contains(event.target))el.open=false;}));
  root.addEventListener('keydown',event=>{if(event.key==='Escape'){const menu=event.target.closest('.coverage-more[open]');if(menu){event.preventDefault();menu.open=false;menu.querySelector('summary').focus();}}});
  $('#coverage-dialog').addEventListener('cancel',event=>{event.preventDefault();coverage.cancel();});
  $('#coverage-dialog').addEventListener('close',()=>coverage.cancel());
  root.addEventListener('change',event=>{const el=event.target;if(el.id==='repair-consent'){state.repairAccepted=el.checked;const control=$('[data-action=repair-toggle]');if(control)control.disabled=!el.checked;}if(el.id==='terms-consent'){state.terms=el.checked;const control=$('[data-action=confirm-checkout]');if(control)control.disabled=!el.checked||state.paymentUnknown||!canChangePlan()||state.checkoutBusy||data.stale;}if(el.name==='plan'&&!state.checkoutBusy){state.planTouched=true;state.selected=el.value;state.terms=false;checkoutStatus();renderPanel();$$('input[name=plan]').find(x=>x.value===el.value)?.focus({preventScroll:true});}});
  $('.tabs').addEventListener('keydown',event=>{const el=event.target.closest('[role=tab]');if(!el)return;const i=tabs.indexOf(el.dataset.tab),next={ArrowLeft:(i+1)%tabs.length,ArrowRight:(i+tabs.length-1)%tabs.length,Home:0,End:tabs.length-1}[event.key];if(next===undefined)return;event.preventDefault();void selectTab(tabs[next],true);});
  function dialogClosed(){state.paymentDialog=false;if(state.repairDialog){state.repairDialog=false;state.repairAccepted=false;}pages.changes.serial++;pages.changes.busy=false;if(state.quote){state.quote=null;state.terms=false;renderPanel('subscription');dialogTrigger=$('input[name=plan]:checked');}if(config.resetPaymentOnDismiss===true&&state.payment?.sandbox===true&&['pending','failed'].includes(state.payment.status)&&!state.checkoutBusy&&!state.coverageStartBusy&&!coverage.busy&&!paymentBusy)void newPurchase();hideToast();root.append($('#toast'));if(dialogTrigger?.isConnected)dialogTrigger.focus({preventScroll:true});}
  $('#detail-dialog').addEventListener('close',dialogClosed);
  // One completion-based payment loop: slow requests cannot overlap the next check.
  function createPaymentPoller({identity,paused,check,setTimer=setTimeout,clearTimer=clearTimeout}){
    const delays=[5000,10000,15000,30000,60000];
    let timer=null,running=false,attempt=0,key=null,generation=0;
    function cancel(){if(timer!==null)clearTimer(timer);timer=null;}
    function reset(){cancel();key=null;attempt=0;generation++;}
    function sync(){
      const next=identity();
      if(next!==key){cancel();key=next;attempt=0;generation++;}
      if(!key||paused()){cancel();return;}
      if(running||timer!==null)return;
      timer=setTimer(run,delays[Math.min(attempt,delays.length-1)]);
    }
    async function run(){
      timer=null;
      if(!key||identity()!==key||paused()){sync();return;}
      running=true;const version=generation;
      try{await check();}
      finally{running=false;if(version===generation)attempt++;sync();}
    }
    return {sync,reset};
  }
  function needsPaymentCheck(){
    const payment=state.payment;
    if(state.paymentUnknown)return true;
    if(!payment)return false;
    if(['pending','processing','unknown','activation','expired'].includes(payment.status))return true;
    if(payment.status==='review')return payment.review_reason==='pending_timeout';
    // Retry a failed dashboard refresh after a verified activation, too.
    return payment.status==='success'&&data.stale&&expectedActivation===payment.key&&!activationObserved;
  }
  const paymentPoller=createPaymentPoller({
    identity:()=>paymentLoaded&&state.tab==='subscription'&&data.available&&needsPaymentCheck()?`${data.scope||''}:${paymentKey||'latest'}`:null,
    paused:()=>document.hidden||paymentBusy||state.checkoutBusy||statusBusy,
    check:()=>paymentStatus(true,{background:true})
  });
  function canPoll(){return state.tab!=='subscription'&&!document.hidden&&!statusBusy&&!state.settingsBusy&&!state.manualBusy&&!state.checkoutBusy&&!state.coverageStartBusy&&!coverage.busy&&!paymentBusy&&!$('#detail-dialog').open&&!window.MonitoringTour?.isOpen&&!state.terms&&!state.repairAccepted&&!root.contains(document.activeElement);}
  setInterval(()=>{if(canPoll())void refresh();},30000);
  function resumePolling(){
    if(document.hidden){paymentPoller.sync();return;}
    // Returning to the page gets one lookup; it does not restart idle polling.
    if(state.tab==='subscription')void paymentStatus(needsPaymentCheck(),{background:true});
    else if(canPoll())void refresh();
  }
  document.addEventListener('visibilitychange',resumePolling);
  window.addEventListener('pageshow',event=>{if(event.persisted)resumePolling();});
  window.addEventListener('hashchange',()=>{if(tabs.includes(location.hash.slice(1)))void selectTab(location.hash.slice(1));});
  scenario();state.tab=paymentKey||params.has('payment_return')?'subscription':tabs.includes(location.hash.slice(1))?location.hash.slice(1):tabs.includes(params.get('tab'))?params.get('tab'):'overview';
  if(!data.available&&data.connected&&!data.error)state.scenario='loading';render();void refresh().then(()=>selectTab(state.tab));
})();
