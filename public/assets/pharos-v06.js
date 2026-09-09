(function(){
  const stack=document.createElement('div');stack.className='pharos-toasts';stack.setAttribute('aria-live','polite');stack.setAttribute('aria-atomic','false');document.body.append(stack);
  window.pharosToast=function(message,title='Status updated',kind='success'){
    const toast=document.createElement('div');toast.className='pharos-toast '+kind;
    const mark=document.createElement('span');mark.className='toast-mark';mark.textContent=kind==='warning'?'!':'✓';mark.setAttribute('aria-hidden','true');
    const body=document.createElement('div'), heading=document.createElement('strong'),text=document.createElement('p');heading.textContent=title;text.textContent=message;body.append(heading,text);
    const close=document.createElement('button');close.type='button';close.setAttribute('aria-label','Dismiss notification');close.textContent='×';close.onclick=()=>toast.remove();
    toast.append(mark,body,close);stack.append(toast);
    while(stack.children.length>3)stack.firstElementChild.remove();
    let timer;const schedule=()=>{clearTimeout(timer);timer=setTimeout(()=>{if(!toast.contains(document.activeElement))toast.remove();},12000);};
    toast.addEventListener('mouseenter',()=>clearTimeout(timer));toast.addEventListener('mouseleave',schedule);toast.addEventListener('focusin',()=>clearTimeout(timer));toast.addEventListener('focusout',schedule);schedule();
  };
  const flash=document.querySelector('.flash');if(flash){window.pharosToast(flash.textContent.trim(),'Saved');flash.remove();}
  const live=document.getElementById('pharos-live');if(!live || !window.fetch)return;
  const indicator=document.getElementById('live-refresh');
  let pending=false,failed=false;
  function signature(region){
    const copy=region.cloneNode(true);
    copy.querySelectorAll('.when').forEach(el=>el.remove());
    copy.querySelectorAll('details').forEach(el=>el.removeAttribute('open'));
    copy.querySelectorAll('.live-changed').forEach(el=>el.classList.remove('live-changed'));
    return copy.innerHTML;
  }
  async function refresh(){
    if(document.hidden || pending)return;
    pending=true;
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
    try{
      const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'text/html'},signal:controller.signal});
      if(!response.ok || response.redirected)throw new Error('Refresh unavailable');
      const doc=new DOMParser().parseFromString(await response.text(),'text/html'), next=doc.getElementById('pharos-live');
      if(!next)throw new Error('Status page unavailable');
      // Never interrupt a keyboard user interacting with a service or a link.
      if(signature(next)!==signature(live) && !(live.contains(document.activeElement) && document.activeElement.matches(':focus-visible'))){
        const open=new Map(Array.from(live.querySelectorAll('details[id]')).map(el=>[el.id,el.open]));
        next.querySelectorAll('details[id]').forEach(el=>{if(open.has(el.id))el.open=open.get(el.id);});
        const previous=new Map(Array.from(live.querySelectorAll('[data-live-key]')).map(el=>[el.dataset.liveKey,el.dataset.liveValue]));
        let message='The latest service status and incident updates are now on this page.';
        next.querySelectorAll('[data-live-key]').forEach(el=>{if(previous.get(el.dataset.liveKey)!==el.dataset.liveValue){el.classList.add('live-changed');if(el.dataset.liveMessage)message=el.dataset.liveMessage;}});
        live.replaceChildren(...next.childNodes);
        document.querySelector('.daytip')?.classList.remove('on');
        window.pharosToast(message);
      }
      const stamp=live.querySelector('.when'),freshStamp=next.querySelector('.when');
      if(stamp && freshStamp)stamp.textContent=freshStamp.textContent;
      indicator.textContent='Checked '+new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})+' · refreshes every 30 seconds';
      indicator.dataset.offline='false';failed=false;
    }catch(error){
      indicator.textContent='Connection interrupted. Retrying automatically.';indicator.dataset.offline='true';
      if(!failed)window.pharosToast('The last loaded status is still shown. We will retry automatically.','Connection interrupted','warning');
      failed=true;
    }finally{clearTimeout(timeout);pending=false;}
  }
  setInterval(refresh,30000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  window.addEventListener('online',refresh);
})();
