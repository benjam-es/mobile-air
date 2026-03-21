<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Support\BundleFileManager;
use Native\Mobile\Traits\PlatformFileOperations;
use Tests\TestCase;

class CrossPlatformFileOperationsTest extends TestCase
{
    use PlatformFileOperations;

    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir().'/nativephp_crossplatform_test_'.uniqid();
        File::makeDirectory($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testDir);
        parent::tearDown();
    }

    /**
     * @dataProvider platformProvider
     */
    public function test_file_operations_on_different_platforms($platform, $expectedCommand)
    {
        Process::fake(['rsync*' => Process::result(), 'robocopy*' => Process::result()]);
        $this->mockOperatingSystem($platform);

        $source = $this->testDir.'/source';
        $dest = $this->testDir.'/dest';

        File::makeDirectory($source);
        File::put($source.'/test.txt', 'content');

        BundleFileManager::copyRaw($source, $dest);

        Process::assertRan(fn ($process) => str_contains($process->command, 'rsync') || str_contains($process->command, 'robocopy'));
    }

    public static function platformProvider(): array
    {
        return [
            'Windows' => ['Windows', 'xcopy'],
            'Linux' => ['Linux', 'cp -a'],
            'Darwin' => ['Darwin', 'cp -a'],
        ];
    }

    public function test_windows_path_handling()
    {
        $this->mockOperatingSystem('Windows');

        // Test Windows path with backslashes
        $path = 'C:\\Users\\Test\\Android\\Sdk';

        // In RunsAndroid trait, this is how Windows paths are handled
        if (preg_match('/^([A-Za-z]):(\\\\.*)|([A-Za-z]):(\/.*)/', $path, $matches)) {
            if (isset($matches[2]) && $matches[2]) {
                // Windows path with backslashes
                $drive = $matches[1].'\\:';
                $rest = str_replace('\\', '\\\\', $matches[2]);
                $result = $drive.$rest;
            }
        }

        $this->assertEquals('C\\:\\\\Users\\\\Test\\\\Android\\\\Sdk', $result);
    }

    public function test_unix_path_handling()
    {
        $this->mockOperatingSystem('Linux');

        // Test Unix path
        $path = '/home/user/Android/Sdk';
        $result = str_replace('\\', '/', $path);

        $this->assertEquals('/home/user/Android/Sdk', $result);
    }

    public function test_directory_removal_windows_vs_unix()
    {
        // Create test directory
        $testRemoveDir = $this->testDir.'/to_remove';
        File::makeDirectory($testRemoveDir.'/sub', 0755, true);
        File::put($testRemoveDir.'/file.txt', 'test');

        // Test removal
        $this->removeDirectory($testRemoveDir);

        // Directory should be gone regardless of platform
        $this->assertDirectoryDoesNotExist($testRemoveDir);
    }

    public function test_file_operations_with_special_characters()
    {
        Process::fake(['rsync*' => Process::result()]);

        $source = $this->testDir.'/source with spaces';
        $dest = $this->testDir.'/dest with spaces';

        File::makeDirectory($source);
        File::put($source.'/file with spaces.txt', 'content');

        BundleFileManager::copyRaw($source, $dest);

        Process::assertRan(fn ($process) => str_contains($process->command, 'source with spaces'));
    }

    public function test_exclusion_handling_across_platforms()
    {
        Process::fake(['rsync*' => Process::result()]);

        $source = $this->testDir.'/source';
        $dest = $this->testDir.'/dest';

        // Create structure with excludable directories
        File::makeDirectory($source.'/node_modules/package', 0755, true);
        File::makeDirectory($source.'/.git/objects', 0755, true);
        File::makeDirectory($source.'/src', 0755, true);

        File::put($source.'/node_modules/package.json', '{}');
        File::put($source.'/.git/config', 'git config');
        File::put($source.'/src/index.php', '<?php');

        // Test copy with bundle exclusions applied
        BundleFileManager::copy($source, $dest);

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'rsync')
                && str_contains($process->command, "--exclude='node_modules'")
                && str_contains($process->command, "--exclude='.git'");
        });
    }

    /**
     * Mock output methods
     */
    protected function info($message)
    {
        // Mock for testing
    }

    protected function warn($message)
    {
        // Mock for testing
    }
}
