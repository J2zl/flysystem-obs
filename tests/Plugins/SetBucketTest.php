<?php

declare(strict_types=1);

namespace Zing\Flysystem\Obs\Tests\Plugins;

use League\Flysystem\Filesystem;
use Mockery;
use Zing\Flysystem\Obs\ObsAdapter;
use Zing\Flysystem\Obs\Plugins\SetBucket;
use Zing\Flysystem\Obs\Tests\TestCase;

class SetBucketTest extends TestCase
{
    public function testSetBucket(): void
    {
        $adapter = Mockery::mock(ObsAdapter::class);
        $adapter->shouldReceive('setBucket')
            ->withArgs(['test'])->once()->passthru();
        $adapter->shouldReceive('getBucket')
            ->withNoArgs()
            ->once()
            ->passthru();
        $filesystem = new Filesystem($adapter);
        $filesystem->addPlugin(new SetBucket());
        $filesystem->bucket('test');
        self::assertSame('test', $adapter->getBucket());
    }
}
