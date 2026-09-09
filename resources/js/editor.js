import Editor from '@toast-ui/editor';
// All assets are local; usage statistics are explicitly disabled.
window.tui = {...window.tui, usageStatistics:false};
function initialiseEditors(){
  document.querySelectorAll('[data-pharos-editor]').forEach(host=>{
    const textarea=document.getElementById(host.dataset.pharosEditor);
    if(!textarea || host.dataset.ready) return;
    const required=textarea.required;
    let editor;
    try {
      host.hidden=false;
      editor=new Editor({el:host,height:'210px',minHeight:'180px',initialEditType:'wysiwyg',initialValue:textarea.value,
        usageStatistics:false,autofocus:false,hideModeSwitch:false,
        theme:document.documentElement.dataset.theme==='dark'?'dark':undefined,
        toolbarItems:[['bold','italic','strike'],['ul','ol','quote'],['link','code']],
        hooks:{addImageBlobHook:()=>false},
      });
    } catch(error) {host.hidden=true; return;}
    host.dataset.ready='true';
    host.querySelector('button.more')?.setAttribute('aria-label','More formatting options');
    textarea.hidden=true;textarea.required=false;
    const sync=()=>{textarea.value=editor.getMarkdown();};
    editor.on('change',()=>{sync();if(textarea.value.trim())host.removeAttribute('aria-invalid');});
    host.querySelectorAll('[contenteditable=true]').forEach(el=>{
      el.setAttribute('role','textbox');el.setAttribute('aria-multiline','true');
      el.setAttribute('aria-label',textarea.labels?.[0]?.textContent || 'Message');
      if(required) el.setAttribute('aria-required','true');
    });
    // The upstream mode tabs are generic divs; make them keyboard operable.
    host.querySelectorAll('.toastui-editor-mode-switch .tab-item').forEach(tab=>{
      tab.setAttribute('role','button');tab.tabIndex=0;
      tab.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();tab.click();}});
    });
    textarea.labels?.[0]?.addEventListener('click',event=>{event.preventDefault();editor.focus();});
    textarea.form?.addEventListener('submit',event=>{
      sync();
      if(required && !textarea.value.trim()){
        event.preventDefault();editor.focus();
        host.setAttribute('aria-invalid','true');
        window.pharosToast?.('Add a message before posting.','Check your update','warning');
      }else host.removeAttribute('aria-invalid');
    });
    textarea.form?.addEventListener('reset',()=>setTimeout(()=>editor.setMarkdown(textarea.value),0));
    textarea.addEventListener('input',()=>{if(textarea.value!==editor.getMarkdown())editor.setMarkdown(textarea.value);});
    new MutationObserver(()=>host.classList.toggle('toastui-editor-dark',document.documentElement.dataset.theme==='dark'))
      .observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
  });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialiseEditors);else initialiseEditors();
