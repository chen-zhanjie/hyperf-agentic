<?php
declare(strict_types=1);

namespace ChenZhanjie\Agentic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ChenZhanjie\Agentic\Support\ConfigLoader;

class ConfigLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/agentic_config_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testLoadFileReturnsArrayForPhpFile(): void
    {
        $path = $this->tmpDir . '/test.php';
        file_put_contents($path, '<?php return ["key" => "value", "num" => 42];');

        $result = ConfigLoader::loadFile($path);
        $this->assertSame(['key' => 'value', 'num' => 42], $result);
    }

    public function testLoadFileReturnsArrayForYamlFile(): void
    {
        $path = $this->tmpDir . '/test.yaml';
        file_put_contents($path, "key: value\nnum: 42\n");

        $result = ConfigLoader::loadFile($path);
        $this->assertSame(['key' => 'value', 'num' => 42], $result);
    }

    public function testLoadFileReturnsEmptyForMissingFile(): void
    {
        $result = ConfigLoader::loadFile('/nonexistent/file.php');
        $this->assertSame([], $result);
    }

    public function testLoadFileReturnsEmptyForUnsupportedExtension(): void
    {
        $path = $this->tmpDir . '/test.json';
        file_put_contents($path, '{"key": "value"}');

        $result = ConfigLoader::loadFile($path);
        $this->assertSame([], $result);
    }

    public function testLoadDirectoryLoadsAllFiles(): void
    {
        file_put_contents($this->tmpDir . '/app.php', '<?php return ["name" => "test"];');
        file_put_contents($this->tmpDir . '/db.yaml', "host: localhost\nport: 3306\n");

        $result = ConfigLoader::loadDirectory($this->tmpDir);
        $this->assertArrayHasKey('app', $result);
        $this->assertArrayHasKey('db', $result);
        $this->assertSame(['name' => 'test'], $result['app']);
        $this->assertSame('localhost', $result['db']['host']);
    }

    public function testLoadDirectoryReturnsEmptyForMissingDir(): void
    {
        $result = ConfigLoader::loadDirectory('/nonexistent/dir');
        $this->assertSame([], $result);
    }

    public function testMergeDeepMergesArrays(): void
    {
        $base = ['a' => 1, 'b' => ['c' => 2, 'd' => 3]];
        $override = ['b' => ['c' => 99], 'e' => 5];
        $result = ConfigLoader::merge($base, $override);

        $this->assertSame(1, $result['a']);
        $this->assertSame(99, $result['b']['c']);
        $this->assertSame(3, $result['b']['d']);
        $this->assertSame(5, $result['e']);
    }

    public function testMergeOverrideReplacesScalars(): void
    {
        $base = ['key' => 'old'];
        $override = ['key' => 'new'];
        $result = ConfigLoader::merge($base, $override);
        $this->assertSame('new', $result['key']);
    }
}
