<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FrontendLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_toast_script_uses_account_locale_and_keeps_customer_text(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'locale' => 'nl']);
        $html = $this->actingAs($admin)->get('/admin/profile')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script id="pharos-messages">(.*?)<\/script>/s', $html, $match));
        $node = <<<'JS'
const fs = require('fs'), vm = require('vm');
function element() { return { children: [], attributes: {}, append(...nodes) {this.children.push(...nodes);},
  setAttribute(key, value) {this.attributes[key] = value;}, addEventListener() {}, contains() {return false;} }; }
const body = element();
global.window = {};
global.document = {body, createElement: element, querySelector() {return null;}, getElementById() {return null;}};
global.setTimeout = () => 1;
vm.runInThisContext(fs.readFileSync(0, 'utf8'));
window.pharosToast('Customer Ω <script> stays text');
const toast = body.children[0].children[0];
process.stdout.write(JSON.stringify({title: toast.children[1].children[0].textContent,
  text: toast.children[1].children[1].textContent, close: toast.children[2].attributes['aria-label']}));
JS;
        $process = new Process(['node', '-e', $node], base_path(), input: $match[1]."\n".file_get_contents(public_path('assets/pharos-v06.js')));
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Status bijgewerkt', $data['title']);
        $this->assertSame('Melding sluiten', $data['close']);
        $this->assertSame('Customer Ω <script> stays text', $data['text']);
    }
}
