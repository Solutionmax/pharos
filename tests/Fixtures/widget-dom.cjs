'use strict';
const vm = require('node:vm');
const assert = require('node:assert/strict');
let input = '';
process.stdin.on('data', part => { input += part; });
process.stdin.on('end', async () => {
  const payload = JSON.parse(input);
  const elements = [];
  const requests = [];
  const timers = [];
  function element(tag) {
    const node = { tag, children: [], attributes: {}, style: {}, textContent: '', isConnected: true,
      appendChild(child) { this.children.push(child); }, setAttribute(name, value) { this.attributes[name] = value; } };
    Object.defineProperty(node, 'innerHTML', { set() { throw new Error('HTML injection'); } });
    elements.push(node); return node;
  }
  const parent = {insertBefore(node) { this.widget = node; }};
  const context = {
    document: {currentScript: {parentNode: parent, nextSibling: null}, createElement: element},
    AbortController, setTimeout(fn, ms) { timers.push(ms); return timers.length; }, clearTimeout() {},
    fetch(url, options) { requests.push({url, options}); return payload.fail ? Promise.reject(new Error('Network down')) : Promise.resolve({ok: true, json: () => Promise.resolve({status: 3, name: '<img src=x onerror=bad()>', label: 'Partial outage'})}); }
  };
  vm.runInNewContext(payload.script, context);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(requests.length, 1);
  assert.equal(requests[0].options.credentials, 'omit');
  assert.equal(parent.widget.href, payload.statusUrl);
  assert.equal(requests[0].url, payload.dataUrl);
  const label = parent.widget.children[1];
  assert.equal(label.textContent, payload.fail ? 'Status unavailable' : '<img src=x onerror=bad()>: Partial outage');
  assert.equal(elements.filter(node => node.tag === 'img').length, 0);
  assert.ok(timers.includes(60000));
  process.stdout.write('widget DOM checks passed\n');
});
