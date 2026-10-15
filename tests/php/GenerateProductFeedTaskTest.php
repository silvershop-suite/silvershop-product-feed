<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests;

use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Page\Product;
use SilverShop\ProductFeed\Job\GenerateProductFeedJob;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverShop\ProductFeed\Service\ProductFeedSelector;
use SilverShop\ProductFeed\Task\GenerateProductFeedTask;
use SilverShop\ProductFeed\Tests\Fake\FakeChannelFeed;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * The CLI task: generates all enabled channels by default, a single channel with --channel, fails cleanly on an
 * unknown channel, and arms the recurring job with --schedule.
 */
class GenerateProductFeedTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ProductFeedSelector::class, 'reading_stage', Versioned::DRAFT);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        Config::modify()->set(ShopConfigExtension::class, 'base_currency', 'EUR');

        $this->outputDir = sys_get_temp_dir() . '/pf-task-' . uniqid('', true);
        Config::modify()->set(ProductFeedGenerator::class, 'output_dir', $this->outputDir);

        Config::modify()->set(ChannelFeedRegistry::class, 'channels', ['fake' => FakeChannelFeed::class]);
        Config::modify()->set(ChannelFeedRegistry::class, 'enabled_channels', ['fake']);

        $product = Product::create(['Title' => 'One', 'BasePrice' => 50.0, 'AllowPurchase' => true, 'InternalItemID' => 'ONE-1']);
        $product->write();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            foreach (glob($this->outputDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->outputDir);
        }
        parent::tearDown();
    }

    private function runTask(array $options): int
    {
        $task = new GenerateProductFeedTask();
        $input = new ArrayInput($options, new InputDefinition($task->getOptions()));

        return $task->run($input, PolyOutput::create(PolyOutput::FORMAT_ANSI));
    }

    public function testGeneratesAllEnabledChannels(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runTask([]));
        $this->assertFileExists($this->outputDir . '/fake.txt');
    }

    public function testGeneratesSingleChannel(): void
    {
        $this->assertSame(Command::SUCCESS, $this->runTask(['--channel' => 'fake']));
        $this->assertFileExists($this->outputDir . '/fake.txt');
    }

    public function testUnknownChannelFails(): void
    {
        $this->assertSame(Command::FAILURE, $this->runTask(['--channel' => 'nope']));
    }

    public function testScheduleArmsTheJob(): void
    {
        $this->assertFalse(GenerateProductFeedJob::isScheduled());
        $this->assertSame(Command::SUCCESS, $this->runTask(['--schedule' => true]));
        $this->assertTrue(GenerateProductFeedJob::isScheduled());
    }
}
