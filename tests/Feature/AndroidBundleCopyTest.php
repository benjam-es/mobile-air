<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Support\BundleExclusions;
use Native\Mobile\Support\BundleFileManager;
use Tests\TestCase;

class AndroidBundleCopyTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_android_bundle_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_prepare_uses_bundle_file_manager_for_copy(): void
    {
        $appPath = $this->fakeRsyncAndGetAppPath();

        BundleFileManager::copy(base_path(), $appPath);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return str_contains($cmd, 'rsync -a --copy-links')
                && str_contains($cmd, "--exclude='node_modules'")
                && str_contains($cmd, "--exclude='.git'")
                && str_contains($cmd, "--exclude='/nativephp'");
        });
    }

    public function test_prepare_runs_cleanup_after_composer_install(): void
    {
        $appPath = $this->createAppPath([
            'node_modules' => ['package' => ['index.js' => '{}']],
            'artisan' => '#!/usr/bin/env php',
            'composer.lock' => '{}',
            'tests' => ['Unit' => ['Test.php' => '<?php']],
            'app' => ['Models' => ['User.php' => '<?php']],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertDirectoryDoesNotExist($appPath.'node_modules');
        $this->assertDirectoryDoesNotExist($appPath.'tests');
        $this->assertFileDoesNotExist($appPath.'artisan');
        $this->assertFileDoesNotExist($appPath.'composer.lock');
        $this->assertDirectoryExists($appPath.'app/Models');
    }

    public function test_zip_excludes_bootstrap_cache_contents(): void
    {
        $tempDir = $this->createAppPath([
            'bootstrap' => [
                'cache' => ['packages.php' => '<?php return [];'],
                'app.php' => '<?php // bootstrap',
            ],
            'app' => ['Http' => ['Kernel.php' => '<?php']],
        ]);

        $zipPath = $this->testProjectPath.'/test_bundle.zip';
        $this->createZipFromDirectory($tempDir, $zipPath);

        $zip = new \ZipArchive;
        $zip->open($zipPath);

        $this->assertFalse($zip->statName('bootstrap/cache/packages.php'));
        $this->assertNotFalse($zip->statName('bootstrap/app.php'));
        $this->assertNotFalse($zip->statName('app/Http/Kernel.php'));

        $zip->close();
    }

    public function test_zip_adds_required_empty_directories(): void
    {
        $tempDir = $this->createAppPath([
            'app' => ['Http' => ['Kernel.php' => '<?php']],
        ]);

        $zipPath = $this->testProjectPath.'/test_bundle.zip';
        $this->createZipFromDirectory($tempDir, $zipPath);

        $zip = new \ZipArchive;
        $zip->open($zipPath);

        foreach (BundleExclusions::ANDROID_REQUIRED_DIRS as $dir) {
            $this->assertNotFalse(
                $zip->statName($dir.'/') ?: $zip->statName($dir),
                "Required dir '{$dir}' missing from zip"
            );
        }

        $zip->close();
    }

    public function test_zip_excludes_jks_and_zip_files(): void
    {
        $tempDir = $this->createAppPath([
            'app' => ['Http' => ['Kernel.php' => '<?php']],
            'keystore.jks' => 'binary keystore data',
            'old_bundle.zip' => 'binary zip data',
            'config' => ['app.php' => '<?php return [];'],
        ]);

        $zipPath = $this->testProjectPath.'/test_bundle.zip';
        $this->createZipFromDirectory($tempDir, $zipPath);

        $zip = new \ZipArchive;
        $zip->open($zipPath);

        $this->assertFalse($zip->statName('keystore.jks'));
        $this->assertFalse($zip->statName('old_bundle.zip'));
        $this->assertNotFalse($zip->statName('app/Http/Kernel.php'));
        $this->assertNotFalse($zip->statName('config/app.php'));

        $zip->close();
    }

    public function test_zip_excludes_idea_directory(): void
    {
        $tempDir = $this->createAppPath([
            '.idea' => ['workspace.xml' => '<project/>'],
            'app' => ['Http' => ['Kernel.php' => '<?php']],
        ]);

        $zipPath = $this->testProjectPath.'/test_bundle.zip';
        $this->createZipFromDirectory($tempDir, $zipPath);

        $zip = new \ZipArchive;
        $zip->open($zipPath);

        $this->assertFalse($zip->statName('.idea/workspace.xml'));
        $this->assertNotFalse($zip->statName('app/Http/Kernel.php'));

        $zip->close();
    }

    public function test_android_required_dirs_constant_has_expected_entries(): void
    {
        $this->assertContains('bootstrap/cache', BundleExclusions::ANDROID_REQUIRED_DIRS);
        $this->assertContains('storage/framework/cache', BundleExclusions::ANDROID_REQUIRED_DIRS);
        $this->assertContains('storage/framework/sessions', BundleExclusions::ANDROID_REQUIRED_DIRS);
        $this->assertContains('storage/framework/views', BundleExclusions::ANDROID_REQUIRED_DIRS);
    }

    // Helpers

    protected function fakeRsyncAndGetAppPath(int $exitCode = 0): string
    {
        Process::fake([
            'rsync*' => Process::result(output: '', errorOutput: $exitCode ? 'error' : '', exitCode: $exitCode),
        ]);

        $appPath = $this->testProjectPath.'/nativephp/android/laravel/';
        File::makeDirectory($appPath, 0755, true);

        return $appPath;
    }

    protected function createAppPath(array $structure): string
    {
        $appPath = $this->testProjectPath.'/nativephp/android/laravel/';
        $this->createDirectoryStructure($appPath, $structure);

        return $appPath;
    }

    /**
     * Create a zip using the same logic as PreparesBuild::addDirectoryToZip + required dirs.
     */
    protected function createZipFromDirectory(string $sourceDir, string $zipPath): void
    {
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $source = rtrim(str_replace('\\', '/', realpath($sourceDir)), '/').'/';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $filePath = str_replace('\\', '/', $file->getRealPath());
            $relativePath = ltrim(str_replace('\\', '/', substr($filePath, strlen($source))), '/');

            // Same safety net as PreparesBuild::addDirectoryToZip
            if (str_starts_with($relativePath, 'bootstrap/cache/') ||
                str_starts_with($relativePath, '.idea') ||
                str_ends_with($relativePath, '.jks') ||
                str_ends_with($relativePath, '.zip')) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }

        foreach (BundleExclusions::ANDROID_REQUIRED_DIRS as $dir) {
            if (! $zip->statName($dir)) {
                $zip->addEmptyDir($dir);
            }
        }

        $zip->close();
    }
}
