<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WidgetJavascriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_embed_response_is_executable_javascript(): void
    {
        User::factory()->create();
        $response = $this->get('/embed.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $process = new Process(['node', '--check'], input: $response->getContent());
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
