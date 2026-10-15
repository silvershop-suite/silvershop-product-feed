<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Service;

use SilverShop\Model\Buyable;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

/**
 * Decides which catalogue items belong in a feed. The rules (all configured on the SiteConfig "Product feed" tab,
 * except the stage and the per-product flag):
 *
 *  - **published** — only products on the configured reading stage (Live by default);
 *  - **purchasable** — {@code canPurchase()} must hold (checked per buyable, variations included);
 *  - **not excluded** — the per-product "Exclude from feed" flag is off;
 *  - **categories** — if any include categories are set, the product must be in one of them (recursively, via the
 *    core's {@code ProductsShowable()}); products in any exclude category are dropped;
 *  - **brands** — same include/exclude logic over {@code silvershop/brands} (guarded — no-op without that module);
 *  - **minimum price** — the item's selling price must be at least the configured floor.
 *
 * {@link ProductFeedGenerator} calls {@see self::eligibleProducts()} for the DB-level product set, then expands each
 * product into the buyables to emit ({@see self::buyablesFor()}) and applies the per-buyable gate
 * ({@see self::isEligible()}). All reads must happen inside a reading-stage context (the generator wraps them).
 */
class ProductFeedSelector
{
    use Injectable;
    use Configurable;

    /**
     * The Versioned stage the feed reads from. {@code Live} (published) in production; a test harness that works on
     * the draft site overrides this to {@code Stage}.
     */
    private static string $reading_stage = Versioned::LIVE;

    /**
     * The feed-eligible products on the reading stage, with the DB-expressible rules applied (exclude flag, category
     * and brand include/exclude). Purchasability and the minimum-price floor are enforced per buyable in
     * {@see self::isEligible()} (they depend on {@code canPurchase()} / {@code sellingPrice()}, not plain columns).
     */
    public function eligibleProducts(?SiteConfig $siteConfig = null): DataList
    {
        $siteConfig ??= SiteConfig::current_site_config();

        /** @var DataList<Product> $products */
        $products = Versioned::get_by_stage(Product::class, $this->readingStage())
            ->filter('ExcludeFromFeed', 0);

        $products = $this->applyCategoryFilters($products, $siteConfig);
        $products = $this->applyBrandFilters($products, $siteConfig);

        return $products;
    }

    /**
     * The buyables a product contributes to the feed: each of its variations when it has any, otherwise the product
     * itself. Variants are emitted as separate items (the channel groups them by {@code item_group_id}, which
     * {@code catalog-data} derives from the parent). The per-buyable gate is NOT applied here — the caller runs
     * {@see self::isEligible()}.
     *
     * @return array<int, Buyable>
     */
    public function buyablesFor(Product $product): array
    {
        if ($product->hasMethod('Variations')) {
            $variations = $product->Variations();
            if ($variations->exists()) {
                return array_values($variations->toArray());
            }
        }

        return [$product];
    }

    /**
     * The final per-buyable gate: purchasable, and above the configured minimum price. (The product-level exclude
     * flag and the category/brand rules are already applied by {@see self::eligibleProducts()}; for a variation the
     * parent product passed those rules.)
     */
    public function isEligible(Buyable $buyable, ?SiteConfig $siteConfig = null): bool
    {
        if (!$buyable->canPurchase()) {
            return false;
        }

        $siteConfig ??= SiteConfig::current_site_config();
        $min = $this->minimumPrice($siteConfig);
        if ($min > 0.0 && round((float) $buyable->sellingPrice(), 2) < $min) {
            return false;
        }

        return true;
    }

    /**
     * Stream every feed-eligible buyable for the whole catalogue, product by product, so the generator never holds
     * more than one product's buyables in memory. Must run inside the reading-stage context.
     *
     * @return \Generator<int, Buyable>
     */
    public function eachBuyable(?SiteConfig $siteConfig = null): \Generator
    {
        $siteConfig ??= SiteConfig::current_site_config();

        foreach ($this->eligibleProducts($siteConfig) as $product) {
            if (!$product instanceof Product) {
                continue;
            }
            foreach ($this->buyablesFor($product) as $buyable) {
                if ($this->isEligible($buyable, $siteConfig)) {
                    yield $buyable;
                }
            }
        }
    }

    public function readingStage(): string
    {
        $stage = (string) static::config()->get('reading_stage');

        return $stage !== '' ? $stage : Versioned::LIVE;
    }

    private function minimumPrice(SiteConfig $siteConfig): float
    {
        if (!$siteConfig->hasField('ProductFeedMinPrice')) {
            return 0.0;
        }

        return round((float) $siteConfig->getField('ProductFeedMinPrice'), 2);
    }

    /**
     * @param DataList<Product> $products
     * @return DataList<Product>
     */
    private function applyCategoryFilters(DataList $products, SiteConfig $siteConfig): DataList
    {
        $include = $this->relationIDs($siteConfig, 'ProductFeedIncludeCategories');
        if ($include !== []) {
            $ids = $this->productIdsInCategories($include);
            // Empty include set means nothing qualifies; a 0 id never matches (and dodges the SS6 empty-set throw).
            $products = $products->filter('ID', $ids !== [] ? $ids : [0]);
        }

        $exclude = $this->relationIDs($siteConfig, 'ProductFeedExcludeCategories');
        if ($exclude !== []) {
            $ids = $this->productIdsInCategories($exclude);
            if ($ids !== []) {
                $products = $products->exclude('ID', $ids);
            }
        }

        return $products;
    }

    /**
     * @param DataList<Product> $products
     * @return DataList<Product>
     */
    private function applyBrandFilters(DataList $products, SiteConfig $siteConfig): DataList
    {
        if (!Product::singleton()->hasMethod('Brand')) {
            return $products;
        }

        $include = $this->relationIDs($siteConfig, 'ProductFeedIncludeBrands');
        if ($include !== []) {
            $products = $products->filter('BrandID', $include);
        }

        $exclude = $this->relationIDs($siteConfig, 'ProductFeedExcludeBrands');
        if ($exclude !== []) {
            $products = $products->exclude('BrandID', $exclude);
        }

        return $products;
    }

    /**
     * IDs of a SiteConfig many_many relation, or [] when the relation is not declared (e.g. the brand relations
     * without {@code silvershop/brands}, which are added by a guarded extension). Detection uses the merged
     * many_many config so it reflects whichever extensions are applied.
     *
     * @return array<int, int>
     */
    private function relationIDs(SiteConfig $siteConfig, string $relation): array
    {
        $manyMany = (array) $siteConfig->config()->get('many_many');
        if (!isset($manyMany[$relation])) {
            return [];
        }

        return array_values(array_map('intval', $siteConfig->getManyManyComponents($relation)->getIDList()));
    }

    /**
     * Product IDs that appear in any of the given categories (recursively), via the core's {@code ProductsShowable()}
     * — which is routed through {@code silvershop/category-index} when installed.
     *
     * @param array<int, int> $categoryIDs
     * @return array<int, int>
     */
    private function productIdsInCategories(array $categoryIDs): array
    {
        if ($categoryIDs === []) {
            return [];
        }

        $ids = [];
        foreach (ProductCategory::get()->byIDs($categoryIDs) as $category) {
            if (!$category->hasMethod('ProductsShowable')) {
                continue;
            }
            foreach ($category->ProductsShowable() as $product) {
                $ids[(int) $product->ID] = (int) $product->ID;
            }
        }

        return array_values($ids);
    }

    /**
     * Whether the record is a Variation (helper for callers expanding buyables).
     */
    public function isVariation(Buyable $buyable): bool
    {
        return $buyable instanceof Variation;
    }
}
