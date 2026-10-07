<?php

/** Build an isolated hosting/Composer archive. Never signs, uploads, tags or pushes. */
declare(strict_types=1);

$version = $argv[1] ?? '';
$output = $argv[2] ?? '';
if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $version) || $output === '') {
    fwrite(STDERR, "Usage: php scripts/build-local-package.php VERSION OUTPUT [--source=PATH] [--vendor=PATH]\n");
    exit(1);
}
$source = dirname(__DIR__);
$vendor = null;
foreach (array_slice($argv, 3) as $option) {
    if (str_starts_with($option, '--source=')) {
        $source = substr($option, 9);
    } elseif (str_starts_with($option, '--vendor=')) {
        $vendor = substr($option, 9);
    } else {
        fwrite(STDERR, "Unknown option\n");
        exit(1);
    }
}
$source = realpath($source);
if ($source === false || ! is_file($source.'/artisan') || ! is_file($source.'/composer.json')) {
    fwrite(STDERR, "Source is not a Pharos project\n");
    exit(1);
}

function runCommand(array $command, string $directory): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start build command');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(trim($stderr ?: $stdout));
    }

    return $stdout;
}

function copyTree(string $from, string $to): void
{
    if (! is_dir($to) && ! mkdir($to, 0755, true) && ! is_dir($to)) {
        throw new RuntimeException('Could not create staging directory');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Symlink in production dependencies');
        }
        $destination = $to.'/'.substr($file->getPathname(), strlen($from) + 1);
        if ($file->isDir()) {
            if (! is_dir($destination)) {
                mkdir($destination, 0755, true);
            }
        } elseif (! copy($file->getPathname(), $destination)) {
            throw new RuntimeException('Could not stage dependency');
        }
    }
}

$stage = sys_get_temp_dir().'/pharos-local-package-'.bin2hex(random_bytes(8));
$archive = null;
try {
    mkdir($stage, 0755, true);
    $tracked = explode("\0", runCommand(['git', 'ls-files', '-z'], $source));
    $skipRoots = ['.github', '.git', 'tests', 'node_modules', 'vendor', 'docker', 'scripts', 'brag-output', 'dist'];
    $skipFiles = ['Dockerfile', 'compose.yaml', 'package.json', 'package-lock.json', 'vite.config.js', 'phpunit.xml', 'phpstan.neon', 'pint.json', '.dockerignore', '.editorconfig', '.gitattributes', '.gitignore', '.npmrc'];
    foreach ($tracked as $relative) {
        if ($relative === '' || in_array(explode('/', $relative)[0], $skipRoots, true) || in_array($relative, $skipFiles, true)
            || (str_starts_with(basename($relative), '.env') && basename($relative) !== '.env.example')
            || str_starts_with($relative, 'storage/') || str_starts_with($relative, 'bootstrap/cache/')
            || str_contains($relative, '..') || is_link($source.'/'.$relative)) {
            continue;
        }
        $target = $stage.'/'.$relative;
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        if (! copy($source.'/'.$relative, $target)) {
            throw new RuntimeException('Could not stage source file');
        }
    }
    $config = $stage.'/config/pharos.php';
    $stamped = preg_replace("/('version'\s*=>\s*env\('PHAROS_VERSION',\s*)'[^']*'/", '$1'."'$version'", file_get_contents($config), 1, $count);
    if ($count !== 1) {
        throw new RuntimeException('Version stamp not found');
    }
    file_put_contents($config, $stamped);
    file_put_contents($stage.'/VERSION', $version."\n");
    foreach (['storage/app/public', 'storage/app/backups', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $directory) {
        mkdir($stage.'/'.$directory, 0755, true);
        file_put_contents($stage.'/'.$directory.'/.gitkeep', '');
    }
    if ($vendor !== null) {
        $vendor = realpath($vendor);
        if ($vendor === false || ! is_file($vendor.'/autoload.php')) {
            throw new RuntimeException('Supplied production vendor is invalid');
        }
        copyTree($vendor, $stage.'/vendor');
    } else {
        runCommand(['composer', 'install', '--no-dev', '--no-scripts', '--prefer-dist', '--no-interaction', '--no-progress', '--optimize-autoloader'], $stage);
    }
    if (! is_dir($output) && ! mkdir($output, 0755, true) && ! is_dir($output)) {
        throw new RuntimeException('Could not create output directory');
    }
    $output = realpath($output);
    $name = 'pharos-'.$version.'.zip';
    $archive = new ZipArchive;
    if ($archive->open($output.'/'.$name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create hosting archive');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $archive->addFile($file->getPathname(), substr($file->getPathname(), strlen($stage) + 1));
        }
    }
    $archive->close();
    $archive = null;
    copy($output.'/'.$name, $output.'/pharos-latest.zip');
    $sha = hash_file('sha256', $output.'/'.$name);
    file_put_contents($output.'/'.$name.'.sha256', $sha.'  '.$name."\n");
    file_put_contents($output.'/pharos-latest.zip.sha256', $sha."  pharos-latest.zip\n");
    $metadata = ['version' => $version, 'url' => $name, 'sha256' => $sha, 'released_at' => gmdate('c'), 'prerelease' => str_contains($version, '-')];
    file_put_contents($output.'/release-info.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    $package = json_decode(file_get_contents($stage.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $package['version'] = $version;
    $package['dist'] = ['type' => 'zip', 'url' => 'file://'.$output.'/'.$name, 'shasum' => hash_file('sha1', $output.'/'.$name)];
    file_put_contents($output.'/packages.json', json_encode(['packages' => ['solutionmax/pharos' => [$version => $package]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    fwrite(STDOUT, json_encode(['version' => $version, 'archive' => $output.'/'.$name, 'sha256' => $sha], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    $failed = true;
} finally {
    $archive?->close();
    if (is_dir($stage)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($stage);
    }
}
exit(isset($failed) ? 1 : 0);
