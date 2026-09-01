<?php

namespace Tests\Unit;

use App\Support\Bytes;
use PHPUnit\Framework\TestCase;

class BytesTest extends TestCase
{
    public function test_sizes_read_like_a_file_manager(): void
    {
        $this->assertSame('0 B', Bytes::human(0));
        $this->assertSame('512 B', Bytes::human(512));
        $this->assertSame('1.5 KB', Bytes::human(1536));
        $this->assertSame('10.0 MB', Bytes::human(10 * 1024 * 1024));
        $this->assertSame('2.3 GB', Bytes::human((int) (2.3 * 1024 ** 3)));
    }

    public function test_nothing_depends_on_the_intl_extension_for_sizes(): void
    {
        // Number::fileSize() needs ext-intl, which shared hosts and the Docker
        // image do not always have; a backup then crashed after being written.
        foreach (['app', 'resources'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$dir)) as $file) {
                if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'Number::fileSize')) {
                    $this->fail($file->getPathname().' still uses Number::fileSize');
                }
            }
        }
        $this->assertTrue(true);
    }
}
