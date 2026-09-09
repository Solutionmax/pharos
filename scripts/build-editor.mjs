import {build} from 'esbuild';
import {readFile, mkdir, copyFile} from 'node:fs/promises';
await mkdir('public/assets/editor', {recursive:true});
await build({entryPoints:['resources/js/editor.js'],bundle:true,minify:true,format:'iife',outfile:'public/assets/editor/editor.js',legalComments:'linked',plugins:[{
  name:'current-dompurify',
  setup(builder){builder.onLoad({filter:/@toast-ui\/editor\/dist\/esm\/index\.js$/},async ({path})=>{
    const source=await readFile(path,'utf8');
    // Toast UI ships its sanitizer inline. Replace that entire vendored copy,
    // not just its npm dependency, so the browser receives the patched version.
    const start=source.indexOf('/*! @license DOMPurify');
    const end=source.indexOf('var purify = createDOMPurify();',start);
    if(start<0 || end<0) throw new Error('Editor sanitizer layout changed: review before building');
    return {contents:source.slice(0,start)+"import purify from 'dompurify';\n"+source.slice(end+'var purify = createDOMPurify();'.length),resolveDir:path.slice(0,path.lastIndexOf('/')),loader:'js'};
  });}
}]});
await copyFile('node_modules/@toast-ui/editor/dist/toastui-editor.css','public/assets/editor/editor.css');
await copyFile('node_modules/@toast-ui/editor/dist/theme/toastui-editor-dark.css','public/assets/editor/dark.css');
