<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests;

use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\ProductFeed\Service\ProductFeedSelector;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

/**
 * The selection rules: per-product exclude flag, category include/exclude, minimum price, purchasability, the
 * published (stage) rule, and variation expansion.
 */
class ProductFeedSelectorTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    private ProductFeedSelector $selector;
    private SiteConfig $siteConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Most tests work on the draft stage (no publishing choreography); the published-rule test overrides this.
        Config::modify()->set(ProductFeedSelector::class, 'reading_stage', Versioned::DRAFT);
        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        Config::modify()->set(ShopConfigExtension::class, 'base_currency', 'EUR');

        $this->selector = ProductFeedSelector::create();
        $this->siteConfig = SiteConfig::current_site_config();
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

    public function testExcludedProductIsLeftOut(): void
    {
        $kept = $this->newProduct(['Title' => 'Kept']);
        $dropped = $this->newProduct(['Title' => 'Dropped', 'ExcludeFromFeed' => true]);

        $ids = $this->selector->eligibleProducts($this->siteConfig)->column('ID');
        $this->assertContains($kept->ID, $ids);
        $this->assertNotContains($dropped->ID, $ids);
    }

    public function testIncludeCategoriesRestrictsSelection(): void
    {
        $included = ProductCategory::create(['Title' => 'Included']);
        $included->write();
        $other = ProductCategory::create(['Title' => 'Other']);
        $other->write();

        $inside = $this->newProduct(['Title' => 'Inside', 'ParentID' => $included->ID]);
        $outside = $this->newProduct(['Title' => 'Outside', 'ParentID' => $other->ID]);

        $this->siteConfig->ProductFeedIncludeCategories()->add($included);

        $ids = $this->selector->eligibleProducts($this->siteConfig)->column('ID');
        $this->assertContains($inside->ID, $ids);
        $this->assertNotContains($outside->ID, $ids);
    }

    public function testExcludeCategoriesRemovesSelection(): void
    {
        $banned = ProductCategory::create(['Title' => 'Banned']);
        $banned->write();
        $fine = ProductCategory::create(['Title' => 'Fine']);
        $fine->write();

        $hidden = $this->newProduct(['Title' => 'Hidden', 'ParentID' => $banned->ID]);
        $shown = $this->newProduct(['Title' => 'Shown', 'ParentID' => $fine->ID]);

        $this->siteConfig->ProductFeedExcludeCategories()->add($banned);

        $ids = $this->selector->eligibleProducts($this->siteConfig)->column('ID');
        $this->assertNotContains($hidden->ID, $ids);
        $this->assertContains($shown->ID, $ids);
    }

    public function testEmptyIncludeCategoryUnionYieldsNothing(): void
    {
        // An include category with no products => nothing qualifies, and no "empty set" ORM error.
        $empty = ProductCategory::create(['Title' => 'Empty']);
        $empty->write();
        $this->newProduct(['Title' => 'Elsewhere']);

        $this->siteConfig->ProductFeedIncludeCategories()->add($empty);

        $this->assertSame([], $this->selector->eligibleProducts($this->siteConfig)->column('ID'));
    }

    public function testMinimumPriceGate(): void
    {
        $cheap = $this->newProduct(['Title' => 'Cheap', 'BasePrice' => 10.0]);
        $dear = $this->newProduct(['Title' => 'Dear', 'BasePrice' => 30.0]);

        $this->siteConfig->ProductFeedMinPrice = 20.0;
        $this->siteConfig->write();

        $this->assertFalse($this->selector->isEligible($cheap, $this->siteConfig));
        $this->assertTrue($this->selector->isEligible($dear, $this->siteConfig));
    }

    public function testPurchasableGate(): void
    {
        $buyable = $this->newProduct(['Title' => 'Buyable']);
        $notBuyable = $this->newProduct(['Title' => 'Locked', 'AllowPurchase' => false]);

        $this->assertTrue($this->selector->isEligible($buyable, $this->siteConfig));
        $this->assertFalse($this->selector->isEligible($notBuyable, $this->siteConfig));
    }

    public function testBuyablesForExpandsVariations(): void
    {
        $product = $this->newProduct(['Title' => 'Has Variations']);
        $v1 = Variation::create(['ProductID' => $product->ID, 'Price' => 50.0, 'InternalItemID' => 'V1']);
        $v1->write();
        $v2 = Variation::create(['ProductID' => $product->ID, 'Price' => 55.0, 'InternalItemID' => 'V2']);
        $v2->write();

        $buyables = $this->selector->buyablesFor($product);
        $this->assertCount(2, $buyables);
        $this->assertContainsOnlyInstancesOf(Variation::class, $buyables);
    }

    public function testBuyablesForSimpleProductIsItself(): void
    {
        $product = $this->newProduct(['Title' => 'Simple']);
        $buyables = $this->selector->buyablesFor($product);
        $this->assertCount(1, $buyables);
        $this->assertSame($product->ID, $buyables[0]->ID);
    }

    public function testEachBuyableAppliesAllGates(): void
    {
        $this->newProduct(['Title' => 'In', 'BasePrice' => 50.0, 'InternalItemID' => 'IN-1']);
        $this->newProduct(['Title' => 'Excluded', 'ExcludeFromFeed' => true, 'InternalItemID' => 'EX-1']);
        $this->newProduct(['Title' => 'Locked', 'AllowPurchase' => false, 'InternalItemID' => 'LK-1']);

        $skus = [];
        foreach ($this->selector->eachBuyable($this->siteConfig) as $buyable) {
            $skus[] = $buyable->getField('InternalItemID');
        }

        $this->assertContains('IN-1', $skus);
        $this->assertNotContains('EX-1', $skus); // exclude flag
        $this->assertNotContains('LK-1', $skus); // not purchasable
    }

    public function testPublishedRuleOnLiveStage(): void
    {
        Config::modify()->set(ProductFeedSelector::class, 'reading_stage', Versioned::LIVE);

        $published = $this->newProduct(['Title' => 'Published']);
        $published->publishSingle();

        $draftOnly = $this->newProduct(['Title' => 'Draft only']);

        $ids = $this->selector->eligibleProducts($this->siteConfig)->column('ID');
        $this->assertContains($published->ID, $ids);
        $this->assertNotContains($draftOnly->ID, $ids);
    }
}
