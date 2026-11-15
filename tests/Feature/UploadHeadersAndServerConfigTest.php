<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security review B3 and A7: uploads are never sniffed or run as a page, also
 * when Apache serves them itself, dotfiles are never served, and neither PHP
 * nor Apache announce their version in the Docker image.
 */
class UploadHeadersAndServerConfigTest extends TestCase
{
    use RefreshDatabase;

    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function test_the_upload_route_serves_files_with_nosniff_and_a_sandbox(): void
    {
        $name = 'upload-'.bin2hex(random_bytes(4)).'.html';
        $this->file = storage_path('app/public/'.$name);
        file_put_contents($this->file, '<script>alert(1)</script>');

        // The framework's own /storage route for the local disk used to shadow this one.
        $this->get('/storage/'.$name)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
    }

    public function test_the_upload_route_never_serves_a_dotfile_or_leaves_the_folder(): void
    {
        $this->assertFileExists(storage_path('app/public/.gitignore'));

        $this->get('/storage/.gitignore')->assertNotFound();
        $this->get('/storage/sub/.hidden')->assertNotFound();
        $this->get('/storage/..%2F..%2F.env')->assertNotFound();
        // The private disk is not reachable under /storage any more either.
        $this->get('/storage/private/anything')->assertNotFound();
    }

    public function test_htaccess_hides_dotfiles_and_marks_files_apache_serves_itself(): void
    {
        $rules = (string) file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('RewriteRule (^|/)\. index.php [L]', $rules);
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !/\.well-known/', $rules);
        $this->assertStringContainsString('RewriteRule ^storage/ - [E=PHAROS_UPLOAD:1]', $rules);
        $this->assertStringContainsString('Header set X-Content-Type-Options "nosniff" env=PHAROS_STATIC', $rules);
        $this->assertStringContainsString("Header set Content-Security-Policy \"sandbox; default-src 'none'\" env=PHAROS_UPLOAD", $rules);
        // The dotfile rule must come before the front controller rules it relies on.
        $this->assertLessThan(strpos($rules, 'Send Requests To Front Controller'), strpos($rules, 'RewriteRule (^|/)\.'));
    }

    public function test_the_docker_image_hides_versions_and_dotfiles_on_the_same_base_image(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        $apache = (string) file_get_contents(base_path('docker/apache-hardening.conf'));

        $this->assertStringContainsString('FROM php:8.5-apache', $dockerfile);
        $this->assertStringContainsString("printf 'expose_php=Off\\n'", $dockerfile);
        $this->assertStringContainsString('a2enmod rewrite headers', $dockerfile);
        $this->assertStringContainsString('COPY docker/apache-hardening.conf /etc/apache2/conf-available/zz-pharos-hardening.conf', $dockerfile);
        $this->assertStringContainsString('RUN a2enconf zz-pharos-hardening', $dockerfile);

        $this->assertStringContainsString('ServerTokens Prod', $apache);
        $this->assertStringContainsString('ServerSignature Off', $apache);
        $this->assertStringContainsString('<FilesMatch "^\.">', $apache);
        $this->assertStringContainsString('<DirectoryMatch "/\.(?!well-known(/|$))">', $apache);
    }
}
