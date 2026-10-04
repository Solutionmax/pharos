import {build} from 'esbuild';
import {mkdir, copyFile} from 'node:fs/promises';
await mkdir('public/assets/not-found', {recursive:true});
// Only the parts of three.js the scene imports end up in the bundle.
await build({entryPoints:['resources/js/not-found.js'],bundle:true,minify:true,format:'iife',target:'es2020',outfile:'public/assets/not-found/scene.js',legalComments:'linked'});
await copyFile('node_modules/three/LICENSE','public/assets/not-found/THREE-LICENSE.txt');
