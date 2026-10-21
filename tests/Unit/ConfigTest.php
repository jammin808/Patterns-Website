<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psc\Config;

/**
 * Which server file is read. Once the file outside the web root exists, a config/local.php uploaded later is never
 * merged or moved over it (a partial upload would drop the settings it leaves out): it is reported as shadowed.
 */
final class ConfigTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/psc-config-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/site/config', 0777, true);
        copy(PSC_ROOT . '/config/defaults.php', $this->base . '/site/config/defaults.php');
    }

    protected function tearDown(): void
    {
        foreach (['/site/config/local.php', '/site/config/defaults.php', '/patterns-data/config.php'] as $f) {
            if (is_file($this->base . $f)) {
                unlink($this->base . $f);
            }
        }
        foreach (['/site/config', '/site', '/patterns-data', ''] as $d) {
            if (is_dir($this->base . $d)) {
                rmdir($this->base . $d);
            }
        }
    }

    private function write(string $file, array $values): void
    {
        $dir = dirname($this->base . $file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->base . $file, '<?php return ' . var_export($values, true) . ';');
    }

    public function testALocalFileAloneIsReadAndNotShadowed(): void
    {
        $this->write('/site/config/local.php', ['app' => ['page_cache_seconds' => 42]]);
        $config = Config::load($this->base . '/site');
        self::assertSame($this->base . '/site/config/local.php', $config->source());
        self::assertSame(42, $config->int('app.page_cache_seconds'));
        self::assertFalse($config->shadowed());
    }

    public function testALocalFileBesideTheServerFileIsReportedAndNotMerged(): void
    {
        $this->write('/patterns-data/config.php', ['db' => ['dsn' => 'mysql:host=127.0.0.1'], 'app' => ['secret' => str_repeat('cd', 32)]]);
        $this->write('/site/config/local.php', ['app' => ['page_cache_seconds' => 42]]);
        $config = Config::load($this->base . '/site');
        self::assertSame($this->base . '/patterns-data/config.php', $config->source());
        self::assertTrue($config->shadowed());
        self::assertSame(300, $config->int('app.page_cache_seconds'), 'the shadowed file changes nothing');
        self::assertSame('mysql:host=127.0.0.1', $config->string('db.dsn'), 'and never replaces the live settings');
        self::assertFileExists($this->base . '/site/config/local.php', 'nor is it moved over the live file');
    }

    public function testNoLocalFileIsNotShadowed(): void
    {
        $this->write('/patterns-data/config.php', ['app' => ['page_cache_seconds' => 60]]);
        $config = Config::load($this->base . '/site');
        self::assertFalse($config->shadowed());
        self::assertSame(60, $config->int('app.page_cache_seconds'));
        self::assertFalse(Config::fromArray(PSC_ROOT, [])->shadowed());
    }
}
