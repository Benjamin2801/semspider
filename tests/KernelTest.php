<?php

namespace App\Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['APP_BUILD_ID']);
    }

    public function testEachBuildGetsItsOwnCacheDirectory(): void
    {
        $_SERVER['APP_BUILD_ID'] = '20261015120000';

        self::assertStringEndsWith('/var/cache/prod-20261015120000', (new Kernel('prod', false))->getCacheDir());
    }

    public function testDefaultCacheDirectoryWithoutBuildId(): void
    {
        self::assertStringEndsWith('/var/cache/prod', (new Kernel('prod', false))->getCacheDir());
    }

    public function testIgnoresUnsafeBuildId(): void
    {
        $_SERVER['APP_BUILD_ID'] = '../../etc';

        self::assertStringEndsWith('/var/cache/prod', (new Kernel('prod', false))->getCacheDir());
    }
}
