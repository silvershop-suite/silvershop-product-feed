<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests;

use SilverShop\ProductFeed\Format\Debug\DebugChannelFeed;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Tests\Fake\FakeChannelFeed;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * The channel registry seam: register (config), look up by key, report unknown keys, and separate "registered"
 * from "enabled".
 */
class ChannelFeedRegistryTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ChannelFeedRegistry::class, 'channels', [
            'fake' => FakeChannelFeed::class,
            'debug' => DebugChannelFeed::class,
        ]);
        Config::modify()->set(ChannelFeedRegistry::class, 'enabled_channels', ['fake']);
    }

    private function registry(): ChannelFeedRegistry
    {
        return ChannelFeedRegistry::create();
    }

    public function testAllReturnsRegisteredChannelsKeyedByChannelKey(): void
    {
        $all = $this->registry()->all();
        $this->assertSame(['fake', 'debug'], array_keys($all));
        $this->assertInstanceOf(FakeChannelFeed::class, $all['fake']);
        $this->assertInstanceOf(DebugChannelFeed::class, $all['debug']);
    }

    public function testGetByKey(): void
    {
        $this->assertInstanceOf(FakeChannelFeed::class, $this->registry()->get('fake'));
    }

    public function testGetUnknownKeyReturnsNull(): void
    {
        $this->assertNull($this->registry()->get('nope'));
    }

    public function testHas(): void
    {
        $this->assertTrue($this->registry()->has('fake'));
        $this->assertFalse($this->registry()->has('nope'));
    }

    public function testEnabledIsSubsetOfRegistered(): void
    {
        $enabled = $this->registry()->enabled();
        $this->assertSame(['fake'], array_keys($enabled));
        $this->assertInstanceOf(FakeChannelFeed::class, $enabled['fake']);
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue($this->registry()->isEnabled('fake'));
        // Registered but not enabled.
        $this->assertFalse($this->registry()->isEnabled('debug'));
        // Not registered at all.
        $this->assertFalse($this->registry()->isEnabled('nope'));
    }

    public function testEnabledIgnoresUnregisteredKeys(): void
    {
        Config::modify()->set(ChannelFeedRegistry::class, 'enabled_channels', ['fake', 'ghost']);
        $this->assertSame(['fake'], array_keys($this->registry()->enabled()));
    }

    public function testDebugChannelFileNameDerivesFromFormatExtension(): void
    {
        $debug = $this->registry()->get('debug');
        $this->assertNotNull($debug);
        $this->assertSame('debug.jsonl', $debug->getFileName());
    }
}
