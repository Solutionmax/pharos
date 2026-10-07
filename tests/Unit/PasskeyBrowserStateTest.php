<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PasskeyBrowserStateTest extends TestCase
{
    public function test_http_or_unsupported_browser_disables_ceremonies_without_request_or_password_submission(): void
    {
        $node = <<<'JS'
const fs = require('fs'), vm = require('vm');
const script = fs.readFileSync(0, 'utf8');
const results = [];
for (const [secure, supported] of [[false, true], [true, false], [true, true]]) {
  let requests = 0, prevented = false;
  const roots = [true, false].map(register => {
    const button = {disabled: false}, status = {textContent: ''}, handlers = {};
    const form = {elements: {_token: {value: 'csrf'}, current_password: {value: 'secret'}},
      querySelector: () => button, addEventListener: (name, fn) => handlers[name] = fn};
    button.addEventListener = (name, fn) => handlers[name] = fn;
    return {register, button, status, form, handlers, dataset: {unavailable: 'Gebruik de HTTPS-hostnaam.', error: 'error'},
      querySelector: selector => selector.includes('form') ? form : selector.includes('button') ? button : status};
  });
  const context = {window: {isSecureContext: secure, PublicKeyCredential: supported ? function(){} : undefined},
    navigator: {credentials: supported ? {} : undefined}, Uint8Array,
    document: {querySelectorAll: selector => roots.filter(root => selector.includes('register') === root.register)},
    fetch: () => {requests++; throw Error('HTTP context must never request challenge');}};
  vm.runInNewContext(script, context);
  if (!secure || !supported) roots[0].handlers.submit({preventDefault() {prevented = true;}});
  results.push({disabled: roots.map(root => root.button.disabled), message: roots.map(root => root.status.textContent), prevented, requests});
}
process.stdout.write(JSON.stringify(results));
JS;
        $process = new Process(['node', '-e', $node], input: file_get_contents(dirname(__DIR__, 2).'/public/assets/pharos-passkeys.js'));
        $process->mustRun();
        $states = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_slice($states, 0, 2) as $state) {
            $this->assertSame([true, true], $state['disabled']);
            $this->assertSame(['Gebruik de HTTPS-hostnaam.', 'Gebruik de HTTPS-hostnaam.'], $state['message']);
            $this->assertTrue($state['prevented'], 'Password form must never fall back to native GET submission');
            $this->assertSame(0, $state['requests']);
        }
        $this->assertSame([false, false], $states[2]['disabled']);
        $this->assertSame(['', ''], $states[2]['message']);
    }
}
