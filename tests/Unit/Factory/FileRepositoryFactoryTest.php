<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\Maintenance\Laminas\Mvc\Factory\FileRepositoryFactory;
use Contenir\Maintenance\Repository\FileRepository;
use Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Factory\Stub\ArrayContainer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[Group('factory')]
final class FileRepositoryFactoryTest extends TestCase
{
    public function testBuildsFileRepositoryFromConfiguredPath(): void
    {
        $container = new ArrayContainer([
            'config' => ['maintenance' => ['file' => '/tmp/maintenance.local.php']],
        ]);

        $repository = (new FileRepositoryFactory())($container);

        self::assertInstanceOf(FileRepository::class, $repository);
    }

    public function testThrowsWhenFilePathIsMissing(): void
    {
        $container = new ArrayContainer(['config' => ['maintenance' => []]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config[maintenance][file]');

        (new FileRepositoryFactory())($container);
    }

    public function testThrowsWhenFilePathIsEmpty(): void
    {
        $container = new ArrayContainer(['config' => ['maintenance' => ['file' => '']]]);

        $this->expectException(RuntimeException::class);

        (new FileRepositoryFactory())($container);
    }

    public function testThrowsWhenFilePathIsNonString(): void
    {
        $container = new ArrayContainer(['config' => ['maintenance' => ['file' => 42]]]);

        $this->expectException(RuntimeException::class);

        (new FileRepositoryFactory())($container);
    }

    public function testThrowsWhenContainerHasNoConfig(): void
    {
        $container = new ArrayContainer([]);

        $this->expectException(RuntimeException::class);

        (new FileRepositoryFactory())($container);
    }
}
