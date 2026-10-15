<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests;

use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverShop\ProductFeed\Service\ProductFeedSelector;
use SilverShop\ProductFeed\Tests\Fake\FakeChannelFeed;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * The generation pipeline end-to-end: real products → CatalogProductData → fake channel map → fake format → a
 * static file written atomically. Asserts the written content is built from real VO fields, variations are
 * expanded, excluded/skip items are left out, and the temp file is renamed (never left behind).
 */
class ProductFeedGeneratorTest extends SapphireTest
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

        $this->outputDir = sys_get_temp_dir() . '/pf-gen-' . uniqid('', true);
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

    private function newProduct(array $data = []): Product
    {
        $product = Product::create(array_merge([
            'Title' => 'Item',
            'BasePrice' => 50.0,
            'AllowPurchase' => true,
        ], $data));
        $product->write();

        return $product;
    }

    public function testGeneratesStaticFileAtomicallyWithRealVoContent(): void
    {
        $this->newProduct(['Title' => 'Simple', 'InternalItemID' => 'SIMPLE-1', 'BasePrice' => 50.0]);

        $generator = ProductFeedGenerator::create();
        $channel = FakeChannelFeed::create();
        $result = $generator->generate($channel);

        $finalPath = $this->outputDir . '/fake.txt';
        $this->assertSame($finalPath, $result['path']);
        $this->assertFileExists($finalPath);
        // Atomic publish: the .temp working file must have been renamed away.
        $this->assertFileDoesNotExist($finalPath . '.temp');

        $contents = (string) file_get_contents($finalPath);
        $this->assertStringStartsWith('<<HEAD channel=fake>>', $contents);
        $this->assertStringEndsWith("<<FOOT>>\n", $contents);
        // Values are the real CatalogProductData fields (feedId, formattedPrice, availability).
        $this->assertStringContainsString('ITEM id=SIMPLE-1 price=50.00 EUR avail=in_stock', $contents);
        $this->assertSame(1, $result['items']);
    }

    public function testVariationsAreEmittedAsSeparateItems(): void
    {
        $product = $this->newProduct(['Title' => 'Varied', 'InternalItemID' => 'VAR-1']);
        $v1 = Variation::create(['ProductID' => $product->ID, 'Price' => 50.0, 'InternalItemID' => 'VAR-1-A']);
        $v1->write();
        $v2 = Variation::create(['ProductID' => $product->ID, 'Price' => 60.0, 'InternalItemID' => 'VAR-1-B']);
        $v2->write();

        $result = ProductFeedGenerator::create()->generate(FakeChannelFeed::create());
        $contents = (string) file_get_contents($result['path']);

        $this->assertStringContainsString('ITEM id=VAR-1-A price=50.00 EUR', $contents);
        $this->assertStringContainsString('ITEM id=VAR-1-B price=60.00 EUR', $contents);
        // The product itself is not emitted when it has variations.
        $this->assertStringNotContainsString('ITEM id=VAR-1 ', $contents);
        $this->assertSame(2, $result['items']);
    }

    public function testExcludedAndSkippedItemsAreOmittedButFeedStillValid(): void
    {
        $this->newProduct(['Title' => 'Kept', 'InternalItemID' => 'KEEP-1']);
        $this->newProduct(['Title' => 'Excluded', 'InternalItemID' => 'EX-1', 'ExcludeFromFeed' => true]);
        // Mapped to an empty array by the fake channel => skipped by the generator.
        $this->newProduct(['Title' => 'Skip', 'InternalItemID' => 'SKIP-1']);

        $result = ProductFeedGenerator::create()->generate(FakeChannelFeed::create());
        $contents = (string) file_get_contents($result['path']);

        $this->assertStringContainsString('id=KEEP-1', $contents);
        $this->assertStringNotContainsString('EX-1', $contents);
        $this->assertStringNotContainsString('SKIP-1', $contents);
        $this->assertStringStartsWith('<<HEAD channel=fake>>', $contents);
        $this->assertStringContainsString('<<FOOT>>', $contents);
        $this->assertSame(1, $result['items']);
    }

    public function testGenerateAllWritesEveryEnabledChannel(): void
    {
        $this->newProduct(['Title' => 'One', 'InternalItemID' => 'ONE-1']);

        $results = ProductFeedGenerator::create()->generateAll();
        $this->assertArrayHasKey('fake', $results);
        $this->assertFileExists($this->outputDir . '/fake.txt');
    }

    public function testGenerateChannelByKey(): void
    {
        $this->newProduct(['Title' => 'One', 'InternalItemID' => 'ONE-1']);

        $result = ProductFeedGenerator::create()->generateChannel('fake');
        $this->assertNotNull($result);
        $this->assertSame('fake', $result['channel']);

        $this->assertNull(ProductFeedGenerator::create()->generateChannel('nope'));
    }

    public function testOutputDirHonoursAbsolutePath(): void
    {
        $this->assertSame($this->outputDir, ProductFeedGenerator::create()->outputDir());
    }
}
