/* Approved D3 visual components. Real values and actions are supplied by monitoring-runtime.js. */
window.createWoosalamMonitoringView = ctx => {
  'use strict';
  let data = ctx.data;
  const state = ctx.state, F = {plans: [], reasons: ctx.reasons};
  const $ = s => ctx.root.querySelector(s);
  const unavailable = () => ctx.unavailable();
  const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const n=(v,d=0)=>v==null?'—':Number(v).toLocaleString('fa-IR',{maximumFractionDigits:d});
  const money=v=>v==null?'ثبت نشده':`${n(v/10,1)} تومان`;
  const date=(v,time=true)=>v&&!Number.isNaN(Date.parse(v))?new Intl.DateTimeFormat('fa-IR',{dateStyle:'medium',...(time?{timeStyle:'short'}:{}),timeZone:ctx.timezone || 'UTC'}).format(new Date(v)):'ثبت نشده';
  const time=v=>v&&!Number.isNaN(Date.parse(v))?new Intl.DateTimeFormat('fa-IR',{hour:'2-digit',minute:'2-digit',timeZone:ctx.timezone || 'UTC'}).format(new Date(v)):'—';
  const icon=name=>`<span class="icon" data-icon="${esc(name)}" aria-hidden="true"></span>`;
  const badge=(label,tone='',name='')=>`<span class="badge ${tone}">${name?icon(name):''}${label}</span>`;
  const button=(label,action,type='secondary',extra='')=>`<button type="button" class="button ${type}" data-action="${action}" ${extra}>${label}</button>`;
  const notice=(title,text,tone='warning',action='')=>`<div class="notice ${tone}">${icon(tone==='success'?'circle-checkmark':'info')}<div><strong>${title}</strong>${text?`<p>${text}</p>`:''}${action}</div></div>`;
  const heading=(title,sub='',extra='')=>`<div class="section-heading"><div><h2>${title}</h2>${sub?`<p>${sub}</p>`:''}</div>${extra}</div>`;
  const meta=(label,value)=>`<div class="meta-row"><span>${label}</span><strong>${value}</strong></div>`;
  const percentage=v=>v==null?'ثبت نشده':`${n(v,1)}٪`;
  function icons(){ window.renderWoosalamIcons(ctx.root); }
  function empty(title,description,action='',name='chart-line',tone=''){return `<div class="empty ${tone}"><div class="empty-icon">${icon(name)}</div><h2>${title}</h2><p>${description}</p>${action}</div>`;}
  function syncIllustration(active,preview=false){
    return `<figure class="sync-scene ${active?'is-running':'is-idle'} ${preview?'sync-preview':''}" aria-label="نمایش روند هماهنگ‌سازی: قیمت و موجودی از مرجع ووسلام در ووکامرس به باسلام منتقل و نتیجه تأیید می‌شود.">
      <div class="sync-stage" aria-hidden="true">
        <div class="sync-store sync-source"><div class="sync-store-title"><span class="sync-shop-icon">${icon('shop')}</span><strong>ووکامرس</strong></div><span class="sync-store-caption">مرجع ووسلام</span><div class="sync-product">${icon('box')}<span>محصول شما</span></div><div class="sync-field"><span>قیمت</span><strong>۲۵۰٬۰۰۰<small>تومان</small></strong></div><div class="sync-field"><span>موجودی</span><strong>۱۲<small>عدد</small></strong></div></div>
        <div class="sync-transit"><span class="sync-lane lane-price"></span><span class="sync-lane lane-stock"></span><span class="sync-packet packet-price">${icon('cash')} قیمت</span><span class="sync-packet packet-stock">${icon('box')} موجودی</span><span class="sync-route-arrow">${icon('arrow-left')}</span></div>
        <div class="sync-store sync-destination"><div class="sync-store-title"><span class="sync-shop-icon">${icon('shop')}</span><strong>باسلام</strong></div><span class="sync-store-caption">غرفهٔ شما</span><div class="sync-product">${icon('box')}<span>همان محصول</span></div><div class="sync-field sync-price"><span>قیمت</span><span class="sync-value-stack"><strong class="sync-old">۲۳۰٬۰۰۰<small>تومان</small></strong><strong class="sync-new">۲۵۰٬۰۰۰<small>تومان</small></strong></span><span class="sync-confirm">${icon('checkmark')}</span></div><div class="sync-field sync-stock"><span>موجودی</span><span class="sync-value-stack"><strong class="sync-old">۷<small>عدد</small></strong><strong class="sync-new">۱۲<small>عدد</small></strong></span><span class="sync-confirm">${icon('checkmark')}</span></div></div>
      </div>
      <div class="sync-phases" aria-hidden="true">${active?`<span class="phase-compare">${icon('eye-show')} مقایسهٔ قیمت و موجودی</span><span class="phase-update">${icon('refresh')} انتقال تغییرها به باسلام</span><span class="phase-done">${icon('circle-checkmark')} قیمت و موجودی هماهنگ شد</span>`:`<span>${icon(preview?'circle-checkmark':'time')} ${preview?'قیمت و موجودی هماهنگ، از فروشگاه تا غرفه':'در انتظار فعال‌بودن اصلاح خودکار'}</span>`}</div>
    </figure>`;
}
  function activationPanel(){
    return `<section class="activation" aria-labelledby="activation-title">
      <div class="activation-hero">
        <div class="activation-copy"><span class="activation-eyebrow">${icon('shield')} مراقبت از محصولات شما در باسلام</span>
          <h2 id="activation-title">قیمت قدیمی؟ موجودی ناهماهنگ؟<br><em>پایشگر حواسش هست.</em></h2>
          <p>وقتی قیمت یا موجودی فروشگاهتان تغییر می‌کند، ممکن است اطلاعات همان محصول در باسلام متفاوت بماند. پایشگر محصولات تحت پوشش را دوره‌ای با ووکامرس مقایسه می‌کند تا اختلاف‌ها از چشم شما دور نمانند.</p>
          <div class="activation-promise">${icon('refresh')} با اصلاح خودکار، اختلاف‌ها مطابق تنظیمات ووسلام در باسلام اصلاح می‌شوند.</div>
          <div class="activation-actions">${button(`مشاهدهٔ پلن‌ها و فعال‌سازی ${icon('arrow-left')}`,'go-subscription','')}</div>
          <div class="activation-trust">${icon('checkmark')} انتخاب محصولات با شماست؛ هر محصول با تمام تنوع‌هایش یک جایگاه.</div>
        </div>
        <div class="activation-art"><div class="activation-art-heading"><span class="section-eyebrow">از فروشگاه شما تا غرفهٔ باسلام</span><h3>یک مرجع، قیمت و موجودی هماهنگ</h3></div>${syncIllustration(true,true)}<span class="activation-art-note">${icon('circle-checkmark')} نتیجهٔ اصلاح در تاریخچه ثبت می‌شود</span></div>
      </div>
      <div class="activation-benefits">
        <article><span>${icon('cash')}</span><h3>قیمت قدیمی را پیدا کنید</h3><p>اختلاف قیمت غرفه با مرجع ووکامرس مشخص می‌شود تا محصول با قیمت نادرست از چشم شما دور نماند.</p></article>
        <article><span>${icon('box')}</span><h3>موجودی ناهماهنگ را اصلاح کنید</h3><p>با اصلاح خودکار، موجودی باسلام مطابق تنظیمات ووسلام به‌روز می‌شود؛ برای کاهش ریسک سفارش کالای ناموجود.</p></article>
        <article><span>${icon('time')}</span><h3>کمتر بررسی دستی کنید</h3><p>محصولات را یک‌به‌یک مقایسه نکنید؛ نتیجهٔ بررسی‌ها و مقدار قبل و بعد از اصلاح را همین‌جا ببینید.</p></article>
      </div>
      <div class="activation-start"><div><span class="section-eyebrow">همراه فروشگاه شما</span><h3>پایش منظم، با کنترل شما</h3><p>اصلاح خودکار را هر زمان خواستید خاموش کنید؛ بررسی محصولات و گزارش‌ها ادامه دارند.</p></div><div class="activation-start-points"><span>${icon('eye-show')} پایش محصولات منتخب</span><span>${icon('wrench')} اصلاح خودکار قابل کنترل</span><span>${icon('view-list')} تاریخچهٔ شفاف تغییرات</span></div></div>
    </section>`;
  }
  function renewalBanner(withAction=true){
    const sub=data.subscription;
    if(!data.available||!sub.expires_at||(sub.active&&!sub.capacity_exceeded))return '';
    const expired=!sub.active;
    return `<aside class="renewal-banner" aria-label="وضعیت اشتراک"><span class="renewal-symbol">${icon(expired?'calendar':'box')}</span><div class="renewal-copy"><span class="renewal-eyebrow">${expired?'زمان تمدید اشتراک رسیده':'ظرفیت بیشتر برای فروشگاه شما'}</span><h2>${expired?'اشتراک پایشگر شما پایان یافته':`پایش ${n(sub.connected_units)} محصول، به پلن بزرگ‌تری نیاز دارد`}</h2><p>${expired?'برای ادامهٔ پایش و اصلاح خودکار قیمت و موجودی، اشتراک را تمدید کنید.':'تعداد محصولات متصل از ظرفیت پلن فعلی بیشتر شده؛ با انتخاب ظرفیت مناسب، پایش را ادامه دهید.'}</p><div class="renewal-meta"><span>${icon(expired?'time':'shield')} ${expired?`پایان اشتراک: ${date(sub.expires_at,false)}`:`ظرفیت فعلی: ${n(sub.plan.product_limit)} محصول`}</span><span>گزارش‌های قبلی در دسترس‌اند</span></div></div>${withAction?button(`${expired?'تمدید و ادامهٔ پایش':'انتخاب پلن مناسب'} ${icon('arrow-left')}`,'go-subscription','renewal-cta'):'<span class="renewal-next">از پلن‌های زیر انتخاب کنید</span>'}</aside>`;
}
  function kpi(label,value,name,attention=false){return `<article class="card kpi ${attention?'attention':''}"><div class="kpi-head"><span>${label}</span>${icon(name)}</div><strong>${n(value)}</strong></article>`;}
  function overview(){
    if(!data.available)return unavailable();
    const sub=data.subscription,p=sub.products,s=p.summary,a=p.history.aggregate;
    const sourceFailure=['coverage_source_unavailable','coverage_upgrade_required'].includes(p.scan_error)?F.reasons[p.scan_error]:null,sourceNotice=sourceFailure?notice(sourceFailure[0],sourceFailure[1]+' '+sourceFailure[2],'warning'):'';
    if(!sub.expires_at)return activationPanel();
    if(data.empty){const prior=a.total_scans>0||p.benefits.correction_events>0;return `${sourceNotice}${renewalBanner()}${prior?benefitsPanel(p.benefits,a):''}${empty(sourceFailure?'آماده‌سازی پوشش کامل نشده است':prior?'پایش دورهٔ جدید آمادهٔ شروع است':'پایشگر آمادهٔ مراقبت از محصولات شماست',prior?'گزارش‌های دوره‌های قبلی در تاریخچه در دسترس‌اند.':'اولین گزارش پس از بررسی قیمت و موجودی محصولات متصل نمایش داده می‌شود.',button(prior?'مشاهدهٔ تاریخچه':'مدیریت محصولات در حال پایش',prior?'go-history':'go-coverage'),'shield')}<div class="overview-support">${repairMini()}${subscriptionMini()}</div>${prior?aggregatePanel(a):''}`;}

    return `${sourceNotice}${renewalBanner()}${benefitsPanel(p.benefits,a)}${aggregatePanel(a)}<div class="overview-support">${repairMini()}${subscriptionMini()}</div>`;
  }

  const repairReasons=['values_differ','verification_mismatch','repair_batch_pending','manual_repair_unconfirmed'];
  function canRepair(item){return data.subscription.active&&!data.stale&&repairReasons.includes(item.reason);}
  function issueComparison(item){
    const desired=Array.isArray(item.desired)?item.desired:[],observed=Array.isArray(item.observed)?item.observed:[];
    if(!desired.length&&!observed.length)return '<p class="issue-no-values">مقدارهای این محصول دریافت نشد؛ ابتدا علت زیر را برطرف کنید.</p>';
    const key=v=>`${v.basalam_product_id||''}:${v.variation_id||''}`;
    const values=new Map();desired.forEach(v=>values.set(key(v),{desired:v}));observed.forEach(v=>values.set(key(v),{...values.get(key(v)),observed:v}));
    return `<div class="issue-comparison" role="group" aria-label="مقایسهٔ قیمت و موجودی">${[...values.values()].map(({desired:d,observed:o})=>{
      const target=d||o;
      return `<div class="issue-target">${target.variation_id?`<p class="issue-variant">تنوع ${n(target.variation_id)}</p>`:''}<table><caption class="screen-reader-text">مقدار مرجع ووکامرس و آخرین مقدار دریافت‌شده از باسلام</caption><thead><tr><th scope="col">مشخصه</th><th scope="col">مرجع ووکامرس</th><th scope="col">در باسلام</th></tr></thead><tbody>${[['primary_price','قیمت',money],['stock','موجودی',v=>v==null?'دریافت نشد':`${n(v)} عدد`]].map(([field,label,format])=>{
        const differs=d?.[field]!=null&&o?.[field]!=null&&d[field]!==o[field];
        return `<tr class="${differs?'has-difference':''}"><th scope="row">${label}</th><td>${d?.[field]==null?'دریافت نشد':format(d[field])}</td><td>${o?.[field]==null?'دریافت نشد':format(o[field])}${differs?'<span class="issue-difference">متفاوت</span>':''}</td></tr>`;
      }).join('')}</tbody></table></div>`;
    }).join('')}</div>`;
  }
  function issuesList(issues=data.subscription.products.recent_issues){
    if(!issues.length)return `<div class="empty-compact empty success"><div class="empty-icon">${icon('circle-checkmark')}</div><h3>موردی برای رسیدگی ثبت نشده</h3></div>`;
    return issues.map((item,i)=>{
      const r=F.reasons[item.reason]||F.reasons.unknown,repairable=canRepair(item),result=({skipped:'بررسی کامل نشده',failed:'بررسی ناموفق',mismatch:'نیاز به هماهنگ‌سازی'}[item.outcome]||'نیازمند بررسی');
      return `<details class="issue" data-key="issue-${Number(item.woo_product_id)}" ${i>2?'data-extra-issue hidden':''}><summary><span class="product-symbol">${icon('box')}</span><span class="issue-name"><strong>${esc(item.title)}</strong><small>${esc(r[0])} · آخرین بررسی ${time(item.checked_at)}</small></span>${badge(result,item.outcome==='failed'?'danger':'warning')}${icon('arrow-down')}</summary><div class="issue-content"><p class="issue-explanation">${esc(r[1])}</p>${issueComparison(item)}${item.targets_truncated?`<p class="small-text muted">${n(item.desired.length)} تنوع از ${n(item.targets_total)} در این گزارش نمایش داده شده است.</p>`:''}<div class="issue-next-step"><div><strong>${repairable?'برای هماهنگ‌کردن این محصول':'قدم بعدی'}</strong><p>${repairable?'مقدار مرجع ووکامرس را بررسی کنید؛ سپس آن را روی همین محصول در باسلام اعمال کنید.':esc(r[2])}</p></div><div class="issue-actions">${repairable?button(`${icon('refresh')} اعمال مقدار ووکامرس`,'manual-repair','',`data-product="${item.woo_product_id}"`):''}${button('مراحل رفع مشکل','product-guide','secondary small',`data-product="${item.woo_product_id}"`)}</div></div></div></details>`;
    }).join('')+(issues.length>3?button(`نمایش ${n(issues.length-3)} مورد دیگر ${icon('arrow-down')}`,'more-issues','ghost small','aria-expanded="false"'):'');
  }
  function repairMini(){
    const sub=data.subscription,enabled=sub.products.auto_repair_enabled,effective=enabled&&sub.active&&!sub.capacity_exceeded&&!state.settingsUnknown&&!data.stale;
    return `<article class="card mini-card"><div class="card-header"><h3>اصلاح خودکار</h3>${badge(state.settingsUnknown?'در انتظار بررسی':data.stale?'نیاز به دریافت گزارش':effective?'فعال':enabled?'فعلاً اجرا نمی‌شود':'خاموش',effective?'success':'',effective?'checkmark':'')}</div><div class="mini-card-body">${syncIllustration(effective)}</div><footer class="mini-card-footer">${meta('آخرین اصلاح',time(sub.products.benefits?.last_correction_at))}<button type="button" class="text-button" data-action="repair-switch" ${data.stale||state.settingsUnknown||state.settingsBusy||!sub.active||sub.capacity_exceeded?'disabled':''}>${enabled?'خاموش کردن اصلاح خودکار':'روشن کردن اصلاح خودکار'} ${icon('arrow-left')}</button></footer></article>`;
  }
  function subscriptionMini(){const sub=data.subscription;return `<article class="card mini-card"><div class="card-header"><h3>اشتراک فروشگاه</h3>${badge(sub.active?'فعال':!sub.expires_at?'فعال نشده':'پایان‌یافته',sub.active?'success':'warning')}</div><div class="mini-card-body"><strong>${!sub.expires_at?'بدون اشتراک':esc(sub.plan.title)}</strong>${capacity(sub)}</div><footer class="mini-card-footer">${meta('پایان دوره',date(sub.expires_at,false))}<button type="button" class="text-button" data-action="go-subscription">${sub.active?'مدیریت اشتراک':sub.expires_at?'تمدید اشتراک':'انتخاب اشتراک'} ${icon('arrow-left')}</button></footer></article>`;}
  function capacity(sub){
    const c=sub.coverage||{},valid=v=>Number.isSafeInteger(v)&&v>=0;
    const selected=valid(c.selected_products)?c.selected_products:null,limit=valid(c.product_limit)?c.product_limit:sub.plan.product_limit;
    const counts=meta('محصولات متصل فروشگاه',n(valid(c.connected_products)?c.connected_products:sub.connected_units));
    if(!sub.expires_at)return counts;
    const meter=selected!==null&&limit>0?`<div class="meter" role="meter" aria-label="تعداد محصولات در حال پایش" aria-valuemin="0" aria-valuemax="${Number(limit)}" aria-valuenow="${Math.min(selected,limit)}" aria-valuetext="${n(selected)} محصول از ظرفیت ${n(limit)} محصول"><span style="width:${Math.min(100,selected/limit*100)}%"></span></div>`:'';
    return `<div class="capacity-line"><span>محصولات در حال پایش</span><strong>${n(selected)} از ${n(limit)}</strong></div>${meter}${counts}${meta('ظرفیت خالی',n(valid(c.available_capacity)?c.available_capacity:null))}${meta('خارج از پوشش',n(valid(c.uncovered_products)?c.uncovered_products:null))}${button('مدیریت محصولات در حال پایش','go-coverage','ghost small')}`;
  }
  function aggregatePanel(a){return `<div class="overall" data-tour="latest-scan">${heading('عملکرد همهٔ دورها')}<div class="kpis">${kpi('بررسی انجام‌شده',a.checked_products,'box')}${kpi('اصلاح تأییدشده',a.updated_products,'circle-checkmark')}${kpi('مغایرت شناسایی‌شده',a.mismatched_products,'view-list')}${kpi('دور پایش',a.total_scans,'time')}</div></div>`;}
  function benefitsPanel(b,a){return `<section class="impact-section" data-tour="impact" aria-labelledby="impact-title"><div class="section-heading"><div><span class="section-eyebrow">مراقبت از قیمت و موجودی فروشگاه</span><h2 id="impact-title">اثر پایشگر برای کسب‌وکار شما</h2></div></div><div class="impact-showcase"><article class="impact-money"><span class="impact-icon">${icon('cash')}</span><h3>اختلاف قیمت اصلاح‌شده</h3><div class="impact-number">${n((b.underpriced_gap_irr==null||b.overpriced_gap_irr==null?null:(b.underpriced_gap_irr+b.overpriced_gap_irr)/10))}<span>تومان</span></div><div class="impact-split"><div><span>اصلاح قیمت‌های کمتر از مرجع</span><strong>${money(b.underpriced_gap_irr)}</strong></div><div><span>اصلاح قیمت‌های بیشتر از مرجع</span><strong>${money(b.overpriced_gap_irr)}</strong></div></div><details class="inline-details"><summary>معنای این مبلغ</summary><p>جمع اختلاف قیمت هر واحد در اصلاح‌های تأییدشده است. برای محاسبهٔ زیان جلوگیری‌شده، اطلاعات فروش هم لازم است.</p></details></article><article class="impact-products"><div class="card-header"><h3>محصول با قیمت یا موجودی اصلاح‌شده</h3>${icon('box')}</div><div class="impact-number">${n(b.restored_products)}<span>محصول متمایز</span></div><div class="impact-counters"><div>${icon('cash')}<strong>${n(b.price_corrections)}</strong><span>اصلاح قیمت</span></div><div>${icon('box')}<strong>${n(b.stock_corrections)}</strong><span>اصلاح موجودی</span></div><div>${icon('circle-checkmark')}<strong>${n(b.correction_events)}</strong><span>اصلاح تأییدشده</span></div></div><p class="change-time">آخرین اصلاح: ${date(b.last_correction_at)}</p></article></div><article class="card comparison"><div><h3>همگامی در دورهای قابل مقایسه</h3><p>${n(a.comparison_products)} مقایسه</p></div><div class="comparison-values"><div><small>قبل از اصلاح</small><strong>${percentage(a.before_match_percent)}</strong><div class="meter"><span style="width:${a.before_match_percent||0}%"></span></div></div>${icon('arrow-left')}<div><small>بعد از اصلاح</small><strong>${percentage(a.after_match_percent)}</strong><div class="meter success"><span style="width:${a.after_match_percent||0}%"></span></div></div></div></article></section>`;}
  function scanRow(s){
    const sum=s.summary||{},status=s.status==='running'?'در حال بررسی':['failed','interrupted'].includes(s.status)?'ناقص':sum.needs_attention?'کامل؛ نیازمند رسیدگی':'کامل',tone=s.status==='running'?'info':s.status==='failed'?'danger':sum.needs_attention?'warning':'success';
    const remaining=sum.checked_products==null||sum.healthy_products==null?null:Math.max(0,sum.checked_products-sum.healthy_products);
    return `<details class="scan-row"><summary><span class="scan-date"><strong>${date(s.started_at)}</strong><small>${s.kind==='manual'?'رسیدگی دستی':'دور'} ${n(s.id)} · ${s.completed_at?`پایان ${time(s.completed_at)}`:'در حال پایش'}</small></span><span class="scan-count"><strong>${n(sum.checked_products)}</strong><small>بررسی</small></span><span class="scan-count"><strong>${n(sum.updated_products)}</strong><small>اصلاح</small></span>${badge(status,tone)}${icon('arrow-down')}</summary><div class="scan-detail"><div class="detail-grid">${[['مغایرت اولیه',sum.mismatched_products],['همگام در پایان دور',sum.healthy_products],['بررسی ناموفق',sum.failed_products],['بررسی‌نشده',sum.skipped_products],['نیازمند رسیدگی',sum.needs_attention],['پردازش‌شده',sum.processed_products]].map(([l,v])=>`<div>${l}<strong>${n(v)}</strong></div>`).join('')}</div>${s.error?notice('پایش این دور نیمه‌تمام ماند',F.reasons[s.error]?F.reasons[s.error][1]+' '+F.reasons[s.error][2]:'بررسی کامل نشد. نتایج تا همین مرحله ثبت شده‌اند؛ ادامهٔ بررسی را در دور بعد دنبال کنید.','warning',button('موارد فعلی در تاریخچه','go-issues','ghost small')):sum.needs_attention?`<div class="scan-followup"><div><strong>${n(sum.needs_attention)} مورد در پایان این دور نیاز به پیگیری داشت</strong><p>${n(remaining)} مغایرت باقی‌مانده · ${n(sum.failed_products)} خطای بررسی · ${n(sum.skipped_products)} بررسی‌نشده</p><small>موارد فعلی نیازمند رسیدگی در بالای همین تاریخچه در دسترس‌اند.</small></div>${button(`پیگیری موارد فعلی ${icon('arrow-left')}`,'go-issues','small')}</div>`:''}<details class="inline-details"><summary>جزئیات مقایسهٔ این دور</summary><p>قبل از اصلاح: ${percentage(s.before_match_percent)} · بعد از اصلاح: ${percentage(s.after_match_percent)}</p><p>تعداد مقایسه‌های معتبر: ${n(s.comparison_products)}</p></details><div class="scan-actions"><span>${n(s.recorded_changes)} اصلاح ثبت‌شده</span>${button(`جزئیات اصلاح‌های دور ${n(s.id)} ${icon('arrow-left')}`,'changes','secondary small',`data-scan="${s.id}"`)}</div></div></details>`;
  }
  function settingsPanel(){
    if(!data.available)return unavailable();
    const sub=data.subscription,enabled=sub.products.auto_repair_enabled,allowed=sub.active&&!sub.capacity_exceeded&&!data.stale&&!state.settingsBusy;
    if(!sub.active)return `${renewalBanner()}${heading('اصلاح خودکار')}<article class="settings-unlock card" data-tour="settings"><div class="unlock-art" aria-hidden="true">${icon('shield')}<span>${icon('wrench')}</span></div><div><span class="section-eyebrow">قیمت و موجودی هماهنگ، با خیال راحت‌تر</span><h2>${sub.expires_at?'با تمدید اشتراک، اصلاح خودکار را ادامه دهید':'اصلاح خودکار را برای فروشگاه فعال کنید'}</h2><p>پایشگر مغایرت محصولات متصل را پیدا می‌کند و قیمت و موجودی را با مرجع ووسلام هماهنگ می‌کند.</p><div class="unlock-features"><span>${icon('checkmark')} پایش قیمت و موجودی</span><span>${icon('checkmark')} ثبت اصلاح‌های تأییدشده</span></div>${button(sub.expires_at?'تمدید اشتراک':'انتخاب اشتراک','go-subscription','')}${button('آشنایی با اصلاح خودکار','repair-help','ghost')}</div></article>`;
    return `${heading('اصلاح خودکار')}<div class="settings-layout"><article class="card settings-card" data-tour="settings"><div class="repair-state"><span class="repair-symbol ${enabled&&allowed?'active':''}">${icon('wrench')}</span><div><h3>هماهنگ‌سازی خودکار محصولات</h3><p>قیمت و موجودی، مطابق تنظیمات ووسلام</p></div>${badge(state.settingsUnknown?'در انتظار بررسی':data.stale?'نیاز به به‌روزرسانی':enabled&&allowed?'فعال':enabled?'متوقف':'خاموش',enabled&&allowed&&!state.settingsUnknown?'success':'')}</div>${syncIllustration(enabled&&allowed&&!state.settingsUnknown)}${state.settingsUnknown?notice('بررسی آخرین تنظیم','وضعیت ثبت‌شده را دریافت کنید.','warning',button('بررسی وضعیت تنظیم','check-settings','secondary small')):sub.capacity_exceeded?notice('ظرفیت بیشتری نیاز دارید',`فروشگاه ${n(sub.connected_units)} محصول متصل دارد. یک پلن با ظرفیت بیشتر انتخاب کنید.`,'warning',button('انتخاب ظرفیت','go-subscription','secondary small')):data.stale?notice('ابتدا گزارش را به‌روز کنید','تغییر تنظیم پس از دریافت وضعیت تازه در دسترس است.','info',button('دریافت گزارش','refresh','secondary small')):''}<div class="settings-action">${!enabled?`<label class="consent"><input id="repair-consent" type="checkbox" ${state.repairAccepted?'checked':''} ${!allowed||state.settingsUnknown?'disabled':''}><span>تأیید می‌کنم قیمت و موجودی ووسلام، مرجع اصلاح محصولات متصل در باسلام باشد.</span></label>`:''}<div class="settings-controls"><div><strong>${state.settingsUnknown||data.stale?(enabled?'آخرین وضعیت ثبت‌شده: روشن':'آخرین وضعیت ثبت‌شده: خاموش'):(enabled?'اصلاح خودکار روشن است':'اصلاح خودکار خاموش است')}</strong><p>${state.settingsUnknown||data.stale?'برای ادامه، وضعیت فعلی را دریافت کنید.':enabled?'با توقف اصلاح، پایش و اشتراک ادامه دارند.':'برای هماهنگ‌سازی خودکار، این قابلیت را فعال کنید.'}</p></div>${button(enabled?'خاموش‌کردن اصلاح خودکار':`${icon('wrench')} فعال‌کردن اصلاح خودکار`,'repair-toggle',enabled?'danger-outline':'',!allowed||state.settingsUnknown||(!enabled&&!state.repairAccepted)?'disabled':'')}</div></div></article><aside class="card help-box"><h3>اصلاح‌ها چگونه ثبت می‌شوند؟</h3><ol class="repair-steps"><li><span>۱</span><div><strong>مقایسه</strong><p>قیمت و موجودی با مرجع ووسلام</p></div></li><li><span>۲</span><div><strong>اصلاح</strong><p>هماهنگ‌سازی محصول متصل در باسلام</p></div></li><li><span>۳</span><div><strong>تأیید</strong><p>ثبت نتیجه در تاریخچهٔ پایش</p></div></li></ol>${meta('آخرین اصلاح',date(sub.products.benefits?.last_correction_at))}<button type="button" class="text-button" data-action="go-history">مشاهدهٔ تاریخچه ${icon('arrow-left')}</button></aside></div>`;
  }
  function supportLink(){return `<a class="button secondary" href="${esc(ctx.supportUrl||'admin.php?page=sync_basalam_tickets')}">${icon('book')} پیام به پشتیبانی</a>`;}
  function offerPresentation(plan){
    return ({
      2000:{name:'اقتصادی',description:'شروعی مطمئن برای پایش فروشگاه',icon:'shield'},
      3500:{name:'به‌صرفه',description:'همراه رشد محصولات فروشگاه شما',icon:'chart-line'},
      6000:{name:'پیشنهاد ووسلام',description:'پوشش گسترده‌تر، با خیال راحت‌تر',icon:'shield',recommended:true},
    })[plan.product_limit]||{name:plan.title||'اشتراک پایشگر',description:'پایش محصولات منتخب فروشگاه',icon:'shield'};
  }
  function offerCard(plan){
    const presentation=offerPresentation(plan),chosen=state.selected===plan.code;
    return `<label class="offer-card ${chosen?'is-selected':''} ${presentation.recommended?'is-recommended':''}">
      <span class="offer-card-head"><span><span class="offer-kicker">${presentation.recommended?'پوشش گسترده‌تر':'پلن پایشگر'}</span><strong class="offer-name">${esc(presentation.name)}</strong></span><input type="radio" name="plan" value="${esc(plan.code)}" ${chosen?'checked':''} aria-label="${esc(presentation.name)}، ${n(plan.product_limit)} محصول، ${n(plan.price_toman)} تومان برای ${n(plan.period_days)} روز"></span>
      <span class="offer-description">${esc(presentation.description)}</span>
      <span class="offer-visual"><span class="offer-capacity"><small>پایش تا</small><span><b>${n(plan.product_limit)}</b> محصول</span></span><span class="offer-art" aria-hidden="true"><i>${icon('box')}</i><i>${icon('box')}</i><i>${icon(presentation.icon)}</i><span>${icon('checkmark')}</span></span></span>
      <span class="offer-price"><strong>${n(plan.price_toman)} <small>تومان</small></strong><span>${icon('time')} ${n(plan.period_days)} روزه</span></span>
      <span class="offer-features" role="list" aria-label="امکانات پلن ${esc(presentation.name)}"><span role="listitem">${icon('checkmark')} پایش دوره‌ای قیمت و موجودی</span><span role="listitem">${icon('checkmark')} اصلاح خودکار طبق تنظیمات ووسلام</span><span role="listitem">${icon('checkmark')} گزارش پایش و تاریخچهٔ اصلاح‌ها</span><span role="listitem">${icon('checkmark')} انتخاب و مدیریت محصولات تحت پوشش</span></span>
      <span class="offer-select" aria-hidden="true">${chosen?icon('circle-checkmark'):''}${chosen?'انتخاب شما':'انتخاب این پلن'}${icon('arrow-left')}</span>
    </label>`;
  }
  function subscriptionPanel(){
    if(!data.available)return unavailable();
    const sub=data.subscription,selected=F.plans.find(p=>p.code===state.selected),locked=!!state.payment&&!state.payment.replaceable&&!['failed','success'].includes(state.payment.status);
    const limit=sub.coverage?.product_limit??sub.plan.product_limit,days=sub.expires_at?Math.max(0,Math.ceil((Date.parse(sub.expires_at)-Date.now())/86400000)):0;
    const paidPeriod=sub.active||days>0;
    if(paidPeriod)return `<div class="subscription-simple" data-tour="subscription"><section class="subscription-current"><div class="subscription-current-heading"><span class="subscription-current-icon">${icon('shield')}</span><div>${badge(sub.active?'اشتراک فعال':'اشتراک متوقف است',sub.active?'success':'warning')}<h3>${esc(sub.plan.title||'اشتراک پایشگر')}</h3><p>معتبر تا ${date(sub.expires_at,false)}</p></div></div><dl class="subscription-numbers"><div><dt>ظرفیت پلن</dt><dd>${n(limit)} <small>محصول</small></dd></div><div><dt>محصولات در حال پایش</dt><dd>${n(sub.coverage?.selected_products)} <small>محصول</small></dd></div><div><dt>زمان باقی‌مانده</dt><dd>${n(days)} <small>روز</small></dd></div></dl><div class="subscription-current-footer"><p>بعد از پایان این دوره می‌توانید پلن تازه بخرید.</p>${supportLink()}</div>${sub.next_plan?`<details class="inline-details"><summary>اشتراک از قبل خریداری‌شده</summary><p>${esc(sub.next_plan.title)} پس از پایان این دوره شروع می‌شود.</p>${sub.active&&sub.next_plan.can_start_early&&sub.next_plan.product_limit>sub.plan.product_limit?button('شروع دورهٔ از قبل خریداری‌شده','start-next-period','secondary small',data.stale?'disabled':''):''}</details>`:''}</section>${paymentFollowup()}</div>`;
    if(!F.plans.length)return ctx.plansUnavailable();
    const unavailablePurchase=data.stale||state.checkoutBusy||state.paymentChecking||state.paymentUnknown||locked||!!sub.next_plan;
    const connected=sub.coverage?.connected_products??sub.connected_units;
    const countKnown=Number.isInteger(connected)&&connected>=0;
    const selectedLimit=selected?.product_limit;
    const selectedCount=countKnown&&selected?Math.min(connected,selectedLimit):null;
    const coverageCopy=countKnown&&selected?(connected>selectedLimit?`از ${n(connected)} محصول متصل، تا ${n(selectedLimit)} محصول تحت پوشش قرار می‌گیرد؛ انتخاب محصولات با شماست.`:`پوشش ${n(connected)} محصول متصل و ${n(selectedLimit-selectedCount)} جایگاه برای محصولات بعدی.`):'محصولات متصل را تا ظرفیت پلن انتخاب و مدیریت کنید.';
    return `<div class="subscription-simple subscription-offers" data-tour="subscription">
      <header class="offers-heading"><div><span class="offers-eyebrow">${icon('shield')} مراقبت از فروشگاه، به اندازهٔ نیاز شما</span><h2>فروشگاه شما، پلن مناسب شما</h2><p>همهٔ امکانات پایشگر؛ با ظرفیت دلخواه شما.</p></div><div class="offers-actions">${!sub.expires_at?button(`${icon('arrow-right')} بازگشت`,'go-overview','ghost small'):''}${paymentFollowup()}</div></header>
      <fieldset class="offer-plans" ${state.checkoutBusy?'disabled':''}><legend class="screen-reader-text">انتخاب پلن پایشگر</legend>${F.plans.map(offerCard).join('')}</fieldset>
      <div class="subscription-unit">${icon('box')} هر محصول با تمام تنوع‌هایش، فقط یک جایگاه از ظرفیت پلن را می‌گیرد.</div>
      ${countKnown?`<p class="purchase-coverage">${esc(coverageCopy)}</p>`:''}
      <details class="inline-details simple-terms"><summary>شرایط اشتراک</summary><p>با خرید، مرجع‌بودن قیمت و موجودی ووسلام برای اصلاح خودکار را می‌پذیرید. اصلاح خودکار از تنظیمات قابل توقف است. هر اشتراک برای یک سایت و غرفه است و از زمان فعال‌سازی ${selected?n(selected.period_days):'—'} روز اعتبار دارد. تا پایان دوره امکان خرید پلن دیگر وجود ندارد.</p></details>
      <section class="offer-checkout" aria-label="خرید پلن انتخاب‌شده"><div class="offer-checkout-plan"><span class="offer-checkout-icon">${icon('shield')}</span><div><span>انتخاب شما</span><strong>${selected?esc(offerPresentation(selected).name):'یک پلن انتخاب کنید'}</strong><small>${selected?`${n(selected.product_limit)} محصول · ${n(selected.period_days)} روز`:''}</small></div></div><div class="offer-checkout-price"><span>مبلغ اشتراک</span><strong>${selected?n(selected.price_toman):'—'} <small>تومان</small></strong></div><div class="offer-checkout-action">${button(state.checkoutBusy?'در حال اتصال به پرداخت…':`خرید و فعال‌سازی ${icon('arrow-left')}`,'checkout','',unavailablePurchase||!selected?'disabled':'')}<span>${icon('shield')} پرداخت در باسلام</span></div></section>
    </div>`;
  }
  function paymentDetails(p=state.payment){
    const labels={superseded:'این درخواست جایگزین شده',pending:'پرداخت آماده است',processing:'در حال بررسی پرداخت',unknown:'در حال بررسی پرداخت',activation:'پرداخت موفق؛ در حال فعال‌سازی',review:'پیگیری پرداخت',expired:'لینک پرداخت منقضی شده',failed:'پرداخت انجام نشد',success:'اشتراک شما فعال شد',scheduled:'اشتراک بعدی خریداری شده'};
    // Stable failure codes also cover responses cached before the copy update.
    const failureMessage=p?.failure_code==='gateway_failed'?'':p?.failure_code==='callback_configuration'?'خطا در بازگشت به ووسلام با پشتیبانی تماس بگیرید':p?.failure_message||'';
    let message={pending:'برای پرداخت، پلن دلخواهتان را انتخاب و خرید را ادامه دهید.',processing:'تأیید درگاه در حال دریافت است؛ نیازی به پرداخت دوباره نیست.',unknown:'پاسخ درگاه در حال دریافت است.',activation:'',expired:'اگر پرداخت نکرده‌اید، دوباره پلن دلخواهتان را بخرید. اگر مبلغ کسر شده، نتیجه را بررسی کنید.',failed:failureMessage,success:'پایشگر برای فروشگاه شما آماده است.',scheduled:'پس از پایان دورهٔ فعلی شروع می‌شود.',superseded:'درخواست جدید را دنبال کنید؛ پیگیری پرداخت قبلی ادامه دارد.'}[p?.status]??'پرداخت نیاز به بررسی پشتیبانی دارد.';
    if(p?.status==='review'&&p.review_reason==='pending_timeout')message='دریافت پاسخ درگاه طول کشیده است.';
    if(p?.status==='review'&&['superseded_paid','active_period_paid'].includes(p.review_reason))message='پرداخت ثبت شده است؛ برای فعال سازی اشتراک نیازمند رسیدگی است لطفا با پشتیبانی ارتباط بگیرید';
    if(p?.purchase_blocked&&!['failed','success','scheduled','activation'].includes(p.status)&&!['superseded_paid','active_period_paid'].includes(p.review_reason))message='پیگیری پرداخت قبلی ادامه دارد. اشتراک فعلی شما محفوظ است.';
    return {title:labels[p?.status]||labels.unknown,message,tone:['success','scheduled'].includes(p?.status)?'success':p?.status==='failed'?'error':'info'};
  }
  function paymentFollowup(){
    return state.payment||state.paymentUnknown&&state.paymentMessage?`<div class="payment-followup">${button(`${icon('invoice')} پیگیری پرداخت`,'payment-open','ghost small')}</div>`:'';
  }
  function paymentToastAllowed(p=state.payment){
    return ['activation','failed','expired','success'].includes(p?.status)||(p?.status==='review'&&p.review_reason!=='pending_timeout');
  }
  function paymentToast(){
    if(!paymentToastAllowed())return '';
    const detail=paymentDetails();
    return `<span class="toast-symbol">${icon(detail.tone==='success'?'circle-checkmark':'info')}</span><div class="toast-copy" role="${detail.tone==='error'?'alert':'status'}" aria-atomic="true"><strong>${esc(detail.title)}</strong>${detail.message?`<p>${esc(detail.message)}</p>`:''}${button('جزئیات پرداخت','payment-open','ghost small')}</div><button type="button" class="toast-close" data-action="payment-dismiss" aria-label="بستن پیام پرداخت">${icon('close')}</button>`;
  }
  function paymentSummary(){
    const p=state.payment;if(!p)return '';
    const detail=paymentDetails(p),pending=!['success','scheduled','failed'].includes(p.status);
    const tracking=p.key?`<details class="payment-tracking"><summary>کد پیگیری برای پشتیبانی</summary><p>اگر مبلغ کسر شده یا اشتراک فعال نشده، این کد را برای پشتیبانی بفرستید.</p><div class="payment-tracking-code"><bdi>${esc(p.key)}</bdi>${button(`${icon('copy')} کپی کد`,'copy-payment','secondary small')}</div></details>`:'';
    return `<section class="simple-payment ${detail.tone}" aria-label="نتیجه پرداخت" aria-live="polite" aria-busy="${!!state.paymentChecking}"><span class="payment-status-icon">${icon(detail.tone==='success'?'circle-checkmark':'info')}</span><div class="payment-result-copy"><strong>${esc(detail.title)}</strong>${detail.message?`<p>${esc(detail.message)}</p>`:''}${state.paymentMessage?`<p class="payment-feedback" role="status">${esc(state.paymentMessage)}</p>`:''}${tracking}<div class="payment-result-actions">${pending?button(state.paymentChecking?'در حال بررسی…':'بررسی پرداخت','payment-check','secondary small',state.paymentChecking?'disabled':''):''}${p.status==='superseded'&&p.replacement_key?button('ادامهٔ درخواست جدید','payment-replacement','secondary small'):''}</div></div></section>`;
  }
  function activationSuccess(){return `<section class="subscription-celebration"><div class="celebration-art" aria-hidden="true"><span>${icon('checkmark')}</span>${Array.from({length:12},(_,i)=>`<i style="--piece:${i}"></i>`).join('')}</div><span class="celebration-label">خرید موفق</span><h3>پایشگر فعال شد</h3><p>اشتراک شما آماده است. حالا محصولاتتان را برای پایش ببینید و مدیریت کنید.</p><div class="dialog-actions">${button('مشاهدهٔ محصولات در حال پایش','go-coverage','')}${button('دیدن اشتراک من','close-dialog','secondary')}</div></section>`;}
  function reportDetails(){const p=data.subscription.products,a=p.history.aggregate;if(!data.available||!a)return '';return `<details class="inline-details"><summary>جزئیات آمار</summary><div class="detail-grid">${[['شروع آخرین دور',date(p.scan_started_at)],['پایان آخرین دور',date(p.scan_completed_at)],['پردازش‌شده در آخرین دور',n(p.summary?.processed_products)],['آغاز ثبت آمار',date(a.since)],['دور کامل',n(a.completed_scans)],['دور جاری',n(a.running_scans)],['دور ناموفق',n(a.failed_scans)],['همگام در مجموع دورها',n(a.healthy_products)],['نیازمند رسیدگی در مجموع دورها',n(a.needs_attention)],['ناموفق در مجموع دورها',n(a.failed_products)],['بررسی‌نشده در مجموع دورها',n(a.skipped_products)],['پردازش‌شده در مجموع دورها',n(a.processed_products)],['قدیمی‌ترین بررسی محصول',date(p.oldest_checked_at)]].map(([label,value])=>`<div>${label}<strong>${value}</strong></div>`).join('')}</div></details><details class="inline-details"><summary>وضعیت اتصال و زمان‌بندی</summary><p>${esc(data.subscription.message||'هنوز ثبت نشده')}</p><p>${esc(data.subscription.guidance||'')}</p><p>بررسی اتصال: ${date(data.subscription.checked_at)}</p><p>فاصلهٔ پایش: ${n(data.subscription.plan.probe_interval_minutes)} دقیقه</p></details>`;}

  return {overview, settingsPanel, subscriptionPanel, scanRow, reportDetails, activationSuccess, paymentSummary, paymentDetails, paymentToast, paymentToastAllowed, paymentFollowup, issuesList, empty, notice, button, icon, esc, n, money, date, time, badge, icons, heading,
    update(next, plans) { data=next; F.plans=plans; }};
};
