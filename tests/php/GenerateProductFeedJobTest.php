<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests;

use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Page\Product;
use SilverShop\ProductFeed\Job\GenerateProductFeedJob;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverShop\ProductFeed\Service\ProductFeedSelector;
use SilverShop\ProductFeed\Tests\Fake\FakeChannelFeed;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * The recurring regeneration job: it regenerates every enabled channel and re-arms itself (self-scheduling), and
 * scheduling is idempotent.
 */
class GenerateProductFeedJobTest extends SapphireTest
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

        $this->outputDir = sys_get_temp_dir() . '/pf-job-' . uniqid('', true);
        Config::modify()->set(ProductFeedGenerator::class, 'output_dir', $this->outputDir);

        Config::modify()->set(ChannelFeedRegistry::class, 'channels', ['fake' => FakeChannelFeed::class]);
        Config::modify()->set(ChannelFeedRegistry::class, 'enabled_channels', ['fake']);
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

    private function descriptorCount(): int
    {
        return QueuedJobDescriptor::get()
            ->filter('Implementation', GenerateProductFeedJob::class)
            ->count();
    }

    public function testScheduleNextQueuesTheJob(): void
    {
        $this->assertFalse(GenerateProductFeedJob::isScheduled());

        GenerateProductFeedJob::scheduleNext();

        $this->assertTrue(GenerateProductFeedJob::isScheduled());
        $this->assertSame(1, $this->descriptorCount());
    }

    public function testScheduleNextIsIdempotent(): void
    {
        GenerateProductFeedJob::scheduleNext();
        GenerateProductFeedJob::scheduleNext();

        $this->assertSame(1, $this->descriptorCount());
    }

    public function testProcessGeneratesEnabledChannelsAndReArms(): void
    {
        $product = Product::create(['Title' => 'One', 'BasePrice' => 50.0, 'AllowPurchase' => true, 'InternalItemID' => 'ONE-1']);
        $product->write();

        $job = new GenerateProductFeedJob();
        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertFileExists($this->outputDir . '/fake.txt');
        // The recurring job re-queued itself.
        $this->assertTrue(GenerateProductFeedJob::isScheduled());
    }
}
