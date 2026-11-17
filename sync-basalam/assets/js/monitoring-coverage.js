/* Source-filtered catalog. Separate replacement browsing preserves the seller's place. */
window.createWoosalamCoverage = ctx => {
  'use strict';
  const {esc,n,button,notice,empty,icon}=ctx.view;
  let generation=0,replacement=null,saving=false;
  const listState=view=>({view,query:'',draft:'',cursors:[null],index:0,next:null,items:[],loaded:false,busy:false,error:'',serial:0,timer:null});
  const page=listState('all');
  let summary={};
  const integer=value=>Number.isSafeInteger(value)&&value>=0?value:null;
  const productId=value=>Number.isSafeInteger(value)&&value>0?value:null;
  const normalize=value=>String(value||'').trim().replace(/[۰-۹٠-٩]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.includes(c)?'۰۱۲۳۴۵۶۷۸۹'.indexOf(c):'٠١٢٣٤٥٦٧٨٩'.indexOf(c))).slice(0,160);
  function adopt(value){
    const source=value&&typeof value==='object'?value:{};
    summary={capacity_unit:source.capacity_unit,active:source.active===true,preparing:source.preparing===true};
    for(const key of ['product_limit','connected_products','selected_products','available_capacity','uncovered_products','revision'])summary[key]=integer(source[key]);
    ctx.onSummary(summary);
  }
  const allowed=(list=page)=>list.loaded&&!list.busy&&!list.timer&&!list.error&&!saving&&summary.active&&summary.revision!==null;
  function image(row){
    let src='';
    try{const u=new URL(row.image_url);if(!u.username&&!u.password&&(u.protocol==='https:'||(u.protocol==='http:'&&u.origin===window.location?.origin)))src=u.href;}catch{}
    return `<span class="coverage-image"><span ${src?'aria-hidden="true"':'aria-label="بدون تصویر"'} class="coverage-image-placeholder">${window.WoosalamIcons?.box||'◇'}</span>${src?`<img src="${esc(src)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">`:''}</span>`;
  }
  const product=row=>`<div class="coverage-product">${image(row)}<div class="coverage-product-copy"><strong>${esc(row.title||'محصول بدون عنوان')}</strong><p>شناسهٔ ووکامرس: <bdi>${row.woo_product_id}</bdi></p></div></div>`;
  function search(list,modal=false){
    const id=modal?'coverage-replacement-search':'coverage-search';
    return `<form class="coverage-search" data-coverage-search="${modal?'replacement':'main'}" ${modal?'':'data-tour="coverage-search"'} role="search" aria-label="${modal?'جستجوی محصول جایگزین':'جستجوی محصولات متصل'}"><label class="sr-only" for="${id}">${modal?'جستجوی محصول جایگزین':'جستجو در محصولات'}</label><button type="submit" class="coverage-search-submit" aria-label="${modal?'جستجوی جایگزین':'جستجو'}" ${saving?'disabled':''}>${icon('search')}</button><input id="${id}" type="search" maxlength="160" autocomplete="off" placeholder="عنوان محصول یا شناسهٔ ووکامرس…" value="${esc(list.draft)}" ${saving?'disabled':''}>${list.draft?button(icon('close'),modal?'coverage-replacement-clear':'coverage-clear','coverage-search-clear',`aria-label="پاک‌کردن جستجو${modal?'ی جایگزین':''}" ${saving?'disabled':''}`):''}</form>`;
  }
  function pagination(list,modal=false){
    if(!list.items.length&&list.index===0)return '';
    const prefix=modal?'coverage-replacement':'coverage';
    return `<div class="pagination">${button('قبلی',prefix+'-prev','secondary small',list.index===0||list.busy||saving?'disabled':'')}<span>صفحهٔ ${n(list.index+1)}</span>${button('بعدی',prefix+'-next','secondary small',!list.next||list.busy||saving?'disabled':'')}</div>`;
  }
  function render(){
    const c=summary,ready=allowed()&&!replacement,disabled=page.busy||saving?'disabled':'';
    const count=integer(c.selected_products),limit=integer(c.product_limit),percent=limit>0&&count!==null?Math.min(100,count/limit*100):0;
    let html=`<section class="coverage-workspace"><h2 class="sr-only">محصولات در حال پایش</h2><div class="coverage-overview"><div class="coverage-capacity" data-tour="coverage-capacity"><div><strong>${n(count)} <span>محصول در حال پایش</span></strong><span>از ظرفیت ${n(limit)} محصول</span></div><div class="coverage-meter" ${limit>0&&count!==null?`role="meter" aria-label="ظرفیت پایش" aria-valuemin="0" aria-valuemax="${limit}" aria-valuenow="${Math.min(count,limit)}"`:''}><span style="width:${percent}%"></span></div></div><div class="coverage-tools">${button(icon('book')+' راهنمای محصولات','coverage-guide','ghost small',disabled)}${button(icon('refresh'),'coverage-refresh','secondary small',`aria-label="تازه‌سازی محصولات" title="تازه‌سازی محصولات" ${disabled}`)}</div></div>`;
    if(page.error)html+=notice('فهرست دریافت یا ثبت نشد',esc(page.error),'warning',button('تلاش دوباره','coverage-refresh','secondary small',disabled));
    if(page.loaded&&!c.active)html+=notice('اشتراک فعال لازم است','برای تغییر انتخاب‌ها و ادامهٔ پایش، اشتراک را فعال کنید.','info',button('بررسی اشتراک','go-subscription','secondary small'));
    if(c.preparing)html+='<p class="coverage-status-note" role="status">انتخاب خودکار محصولات در حال تکمیل است.</p>';
    if(page.loaded&&c.active&&c.available_capacity===0)html+='<p class="coverage-status-note">ظرفیت پر است؛ برای انتخاب محصول جدید از «جایگزینی» استفاده کنید.</p>';
    html+=`<section class="coverage-catalog" aria-label="فهرست محصولات"><div class="coverage-toolbar">${search(page)}<div class="coverage-filters" role="group" aria-label="فیلتر وضعیت پایش" data-tour="coverage-filters">${[['all','همه',c.connected_products],['monitored','در حال پایش',c.selected_products],['unmonitored','بدون پایش',c.uncovered_products]].map(([key,title,value])=>button(`${title}<span class="coverage-count">${n(value)}</span>`,`coverage-filter-${key}`,'coverage-filter',`aria-pressed="${page.view===key}" ${saving?'disabled':''}`)).join('')}</div></div>`;
    html+=`<div class="coverage-list-note"><details class="coverage-policy" data-tour="coverage-default"><summary>انتخاب پیش‌فرض: پرفروش‌ترین‌ها <span aria-hidden="true">ⓘ</span></summary><p>بر اساس تعداد فروش ثبت‌شده در ووکامرس؛ در فروش برابر، شناسهٔ کوچک‌تر اولویت دارد. انتخاب‌های شما حفظ می‌شوند و محصول خارج‌شده خودکار برنمی‌گردد. هر محصول با تمام تنوع‌هایش یک جایگاه می‌گیرد.</p></details><span class="coverage-result-status" role="status" aria-live="polite">${saving?'در حال ثبت…':page.busy?'در حال جستجو…':page.query?`${n(page.items.length)} نتیجه در این صفحه`:`${n(c.available_capacity)} جایگاه خالی`}</span></div>`;
    html+=`<div class="coverage-list" data-tour="coverage-list" aria-busy="${page.busy||saving}"><div class="coverage-list-head" aria-hidden="true"><span>محصول</span><span>وضعیت</span><span>عملیات</span></div>`;
    if(page.items.length)html+=page.items.map(row=>{
      const id=productId(row.woo_product_id);if(!id)return '';
      const selected=row.selected===true,label=esc(row.title||'محصول بدون عنوان'),attrs=`data-product="${id}"`;
      const more=`<details class="coverage-more" data-key="product-${id}"><summary aria-label="گزینه‌های ${label}" title="گزینه‌های بیشتر"><span aria-hidden="true">⋯</span></summary><div class="coverage-more-popover">${button('خروج از پایش','coverage-remove','ghost small',`${attrs} aria-label="خروج از پایش ${label}"`)}</div></details>`;
      const controls=selected?`${button('جایگزینی','coverage-replace','secondary small',`${attrs} aria-label="جایگزینی ${label}" ${ready?'':'disabled'}`)}${ready?more:'<button class="coverage-more-disabled" type="button" disabled aria-label="گزینه‌های بیشتر">⋯</button>'}`:button('افزودن به پایش','coverage-add','small',`${attrs} aria-label="افزودن به پایش ${label}" ${!ready||row.eligible!==true||!(c.available_capacity>0)?'disabled':''}`);
      return `<article class="coverage-row">${product(row)}<div class="coverage-status"><span class="coverage-status-label ${selected?'is-monitored':''}"><i aria-hidden="true"></i>${selected?'در حال پایش':'بدون پایش'}</span><small>${selected?(row.selection_reason==='manual'?'انتخاب شما':'انتخاب خودکار'):row.excluded?'خارج‌شده توسط شما':''}</small></div><div class="coverage-row-actions" data-tour="coverage-actions">${controls}</div></article>`;
    }).join('');
    else if(!page.busy&&!page.error)html+=empty(page.query?'محصولی پیدا نشد':page.view==='monitored'?'محصولی در حال پایش نیست':page.view==='unmonitored'?'محصولی بدون پایش نیست':'محصول متصلی پیدا نشد',page.query?'عنوان دیگری یا شناسهٔ ووکامرس را امتحان کنید.':page.view==='all'?'محصولات منتشرشدهٔ متصل به غرفه در این فهرست نمایش داده می‌شوند.':'برای دیدن فهرست کامل، فیلتر «همه» را انتخاب کنید.','','box');
    else if(page.busy&&!page.items.length)html+='<div class="coverage-loading" role="status">در حال دریافت محصولات…</div>';
    return html+'</div>'+pagination(page)+'</section></section>';
  }
  function renderReplacement(){
    if(!replacement)return '';
    const r=replacement,l=r.list,ready=allowed(l);
    return `<div class="coverage-current"><span>محصول فعلی</span>${product(r.product)}<p>تا تأیید جایگزینی، این محصول در حال پایش می‌ماند.</p></div><div class="coverage-candidates"><h3>محصول جایگزین را انتخاب کنید</h3>${search(l,true)}<p class="coverage-result-status" role="status" aria-live="polite">${l.busy?'در حال جستجو…':'فقط محصولات بدون پایش نمایش داده می‌شوند.'}</p>${l.error?notice('فهرست دریافت نشد',esc(l.error),'warning',button('تلاش دوباره','coverage-replacement-refresh','secondary small')):''}<div class="coverage-candidate-list" role="group" aria-label="انتخاب محصول جایگزین" aria-busy="${l.busy}">${l.items.filter(row=>productId(row.woo_product_id)&&row.eligible===true&&row.selected!==true).map(row=>`<label class="coverage-candidate ${r.chosen?.woo_product_id===row.woo_product_id?'is-chosen':''}"><input type="radio" name="coverage-candidate" value="${row.woo_product_id}" ${r.chosen?.woo_product_id===row.woo_product_id?'checked':''} ${!ready?'disabled':''} aria-label="انتخاب ${esc(row.title||'محصول بدون عنوان')}">${product(row)}<span class="coverage-choice-mark" aria-hidden="true">✓</span></label>`).join('')}${!l.busy&&!l.error&&!l.items.length?'<p class="coverage-no-candidate">'+(l.query?'محصولی با این جستجو پیدا نشد.':'محصولی بدون پایش برای جایگزینی وجود ندارد.')+'</p>':''}</div>${pagination(l,true)}</div><footer class="coverage-dialog-footer"><p>${r.chosen?`انتخاب شما: <strong>${esc(r.chosen.title)}</strong>`:'یک محصول انتخاب کنید؛ هر دو تغییر با هم ثبت می‌شوند.'}</p><div class="button-row">${button(saving?'در حال ثبت…':'تأیید جایگزینی','coverage-confirm','',!ready||!r.chosen?'disabled':'')}${button('انصراف','coverage-cancel','secondary',saving?'disabled':'')}</div></footer>`;
  }
  function paint(list){if(list===page)ctx.render();else if(replacement?.list===list)ctx.renderReplacement?.(renderReplacement());}
  async function loadList(list,index=list.index){
    const epoch=generation,serial=++list.serial;
    list.busy=true;list.error='';paint(list);
    try{
      const result=await ctx.request('coverage',{view:list.view,query:list.query,...(list.cursors[index]?{cursor:String(list.cursors[index])}:{})});
      if(epoch!==generation||serial!==list.serial||!result)return;
      if(!result.summary||!Array.isArray(result.items))throw new Error('پاسخ فهرست معتبر نیست؛ دوباره دریافت کنید.');
      adopt(result.summary);list.items=result.items;list.next=result.has_more&&productId(result.next_cursor)?result.next_cursor:null;list.index=index;list.loaded=true;
    }catch(error){if(epoch===generation&&serial===list.serial)list.error=error.message;}
    finally{if(epoch===generation&&serial===list.serial){list.busy=false;paint(list);}}
  }
  async function first(list){clearTimeout(list.timer);list.timer=null;list.query=normalize(list.draft);list.cursors=[null];list.next=null;await loadList(list,0);}
  function input(value,modal=false){
    const list=modal?replacement?.list:page;if(!list||saving)return;
    list.draft=String(value||'').slice(0,160);clearTimeout(list.timer);list.serial++;list.busy=true;
    list.timer=setTimeout(()=>{list.timer=null;void first(list);},350);
  }
  function cancel(){
    if(saving)return;
    if(replacement){clearTimeout(replacement.list.timer);replacement.list.serial++;replacement=null;ctx.closeReplacement?.();}
  }
  async function change(add,remove){
    const list=replacement?.list||page;if(!allowed(list))return;
    const revision=summary.revision,epoch=generation;
    saving=true;page.error='';paint(list);let failure='';
    try{
      const result=await ctx.request('coverage_update',{add:JSON.stringify(add),remove:JSON.stringify(remove),revision:String(revision)});
      if(epoch!==generation||!result)return;
      if(!result.summary)throw new Error('نتیجهٔ ثبت مشخص نیست؛ فهرست تازه را بررسی کنید.');
      adopt(result.summary);
    }catch(error){if(epoch===generation)failure=error.message;}
    finally{if(epoch===generation){
      saving=false;const wasReplacing=!!replacement;cancel();
      // Keep filter, search and captured cursor. Read ambiguous outcomes, never replay the mutation.
      await loadList(page);
      if(!page.items.length&&page.index>0&&!page.error)await loadList(page,page.index-1);
      if(failure){page.error=failure;ctx.render();}
      if(wasReplacing)ctx.focusCoverageProduct?.();
      ctx.toast(failure||(add.length&&remove.length?'محصول جایگزین شد.':add.length?'محصول به پایش اضافه شد.':'محصول از پایش خارج شد.'),failure?'error':'success');
    }}
  }
  async function action(name,el){
    if(!name.startsWith('coverage-'))return false;
    if(saving)return true;
    if(name==='coverage-cancel'){cancel();return true;}
    if(name==='coverage-guide'){if(!replacement)ctx.guide?.(el);return true;}
    const modal=name.startsWith('coverage-replacement-'),list=modal?replacement?.list:page;
    if(!list)return true;
    if(name==='coverage-confirm'){
      if(replacement?.chosen&&allowed(replacement.list))await change([replacement.chosen.woo_product_id],[replacement.product.woo_product_id]);
      return true;
    }
    if(name==='coverage-choose'){
      const r=replacement,row=r?.list.items.find(x=>String(x.woo_product_id)===String(el?.value));
      if(r&&allowed(r.list)&&row?.eligible===true&&row.selected!==true){r.chosen=row;paint(r.list);}
      return true;
    }
    if(name.endsWith('-search')){list.draft=ctx.searchInput?.(modal)??list.draft;await first(list);return true;}
    if(name.endsWith('-clear')){list.draft='';await first(list);ctx.focusSearch?.(modal);return true;}
    if(name.endsWith('-refresh')){await first(list);return true;}
    if(name.startsWith('coverage-filter-')&&!replacement){const v=name.slice('coverage-filter-'.length);if(['all','monitored','unmonitored'].includes(v)){page.view=v;await first(page);}return true;}
    if(name.endsWith('-next')&&list.next&&!list.busy){list.cursors[list.index+1]=list.next;await loadList(list,list.index+1);return true;}
    if(name.endsWith('-prev')&&list.index>0&&!list.busy){await loadList(list,list.index-1);return true;}
    if(replacement||!allowed())return true;
    const row=page.items.find(item=>String(item.woo_product_id)===el?.dataset.product);
    if(!row||!productId(row.woo_product_id))return true;
    if(name==='coverage-replace'&&row.selected===true){
      replacement={product:row,chosen:null,list:listState('unmonitored')};
      ctx.openReplacement?.(renderReplacement(),el);await first(replacement.list);
    }
    if(name==='coverage-remove'&&row.selected===true)await change([], [row.woo_product_id]);
    if(name==='coverage-add'&&row.selected!==true&&row.eligible===true&&summary.available_capacity>0)await change([row.woo_product_id],[]);
    return true;
  }
  return {render,renderReplacement,input,load:()=>loadList(page),action,cancel,get loaded(){return page.loaded;},get busy(){return page.busy||saving||!!replacement;},get saving(){return saving;},get replacing(){return !!replacement;},reset(){generation++;clearTimeout(page.timer);saving=false;cancel();summary={};Object.assign(page,listState('all'));}};
};
