(function(){
  const form=document.getElementById('destination-form'),selector=document.getElementById('format');
  if(!form || !selector)return;
  const profiles=JSON.parse(document.getElementById('integration-profiles').textContent),drafts={};
  const draftFields=['label','url','telegram_token','telegram_chat_id','signal_number','signal_recipient','signal_token'];
  let current=selector.value;
  function paint(announce){
    const profile=profiles[selector.value];if(!profile)return;
    document.getElementById('label').placeholder=profile.name;
    const address=document.getElementById('webhook-address'),url=document.getElementById('url');
    address.hidden=selector.value==='telegram';url.disabled=address.hidden;url.required=!address.hidden;url.placeholder=profile.placeholder;
    document.getElementById('destination-address-label').textContent=profile.address;
    document.getElementById('destination-address-help').textContent=profile.help;
    for(const name of ['telegram','signal']){
      const fields=document.getElementById(name+'-fields');fields.hidden=selector.value!==name;fields.disabled=fields.hidden;
      fields.querySelectorAll('input').forEach(input=>{input.required=!fields.hidden;});
    }
    document.getElementById('destination-title').textContent=profile.title;
    document.getElementById('destination-summary').textContent=profile.summary;
    document.getElementById('destination-flow-name').textContent=profile.title;
    const steps=document.getElementById('destination-steps');steps.replaceChildren();
    profile.steps.forEach(text=>{const li=document.createElement('li');li.textContent=text;steps.append(li);});
    document.getElementById('destination-result').textContent=profile.result;
    document.getElementById('destination-docs').href=profile.docs;
    document.getElementById('generic-signature-help').hidden=selector.value!=='generic';
    if(announce)document.getElementById('destination-announcement').textContent=profile.title+' setup fields and instructions shown.';
  }
  selector.addEventListener('change',()=>{
    drafts[current]=Object.fromEntries(draftFields.map(id=>[id,document.getElementById(id).value]));
    current=selector.value;
    draftFields.forEach(id=>{document.getElementById(id).value=drafts[current]?.[id]||'';});
    paint(true);
  });
  paint(false);
  document.querySelectorAll('[data-select-destination]').forEach(link=>link.addEventListener('click',()=>{
    selector.value=link.dataset.selectDestination;selector.dispatchEvent(new Event('change'));selector.focus({preventScroll:true});
  }));
  const component=document.getElementById('integration-component'),status=document.getElementById('integration-status');
  const base=document.getElementById('incoming-integrations').dataset.integrationBase;
  function examples(){
    const id=/^\d+$/.test(component.value)?component.value:'COMPONENT_ID';
    document.querySelectorAll('[data-component-url]').forEach(el=>{el.textContent=base+'/'+el.dataset.componentUrl+'/'+id;});
    document.getElementById('n8n-component-body').textContent=JSON.stringify({status:Number(status.value)},null,2);
    const incident={name:'Service unavailable',status:'investigating',message:'We are investigating a service interruption.',impact:'major',components:{[id]:'major_outage'}};
    document.getElementById('incident-example').textContent=JSON.stringify(incident,null,2);
    // Values are fixed copy plus a numeric ID. Quote the installation URL for shell use.
    const quotedUrl="'"+(base+'/incidents').replaceAll("'","'\\''")+"'";
    document.getElementById('incident-curl').textContent='curl -X POST '+quotedUrl+' \\\n  -H \'Authorization: Bearer YOUR_PHAROS_TOKEN\' \\\n  -H \'Content-Type: application/json\' \\\n  -d \''+JSON.stringify(incident)+'\'';
  }
  component.addEventListener('change',examples);status.addEventListener('change',examples);examples();
  document.querySelectorAll('[data-copy-target]').forEach(button=>button.addEventListener('click',async()=>{
    const target=document.getElementById(button.dataset.copyTarget);let copied=false;
    try{if(navigator.clipboard){await navigator.clipboard.writeText(target.textContent);copied=true;}}catch(error){}
    if(!copied){const selection=getSelection(),range=document.createRange();range.selectNodeContents(target);selection.removeAllRanges();selection.addRange(range);try{copied=document.execCommand('copy');}catch(error){}if(copied)selection.removeAllRanges();}
    window.pharosToast?.(copied?'Copied to clipboard.':'Select and copy the highlighted text.',copied?'Copied':'Copy example');
  }));
})();
