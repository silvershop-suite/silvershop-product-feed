<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ListboxField;

/**
 * Adds brand include/exclude to the SiteConfig "Product feed" tab. Wired onto {@link SiteConfig} only when
 * {@code silvershop/brands} is installed (see {@code _config/product-feed.yml}, guarded by {@code moduleexists}),
 * so the two many_many relations below only ever resolve to a real {@code Brand} class when that module is present.
 * The class is referenced by string (never imported) and read through {@code getManyManyComponents()}, so this
 * extension stays loadable and analysable with or without that module.
 *
 * @extends Extension<\SilverStripe\SiteConfig\SiteConfig>
 */
class ProductFeedBrandSiteConfigExtension extends Extension
{
    private static array $many_many = [
        'ProductFeedIncludeBrands' => 'SilverShop\\Brands\\Brand',
        'ProductFeedExcludeBrands' => 'SilverShop\\Brands\\Brand',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $options = $this->brandOptions();
        $tab = $fields->fieldByName('Root.Shop') ? 'Root.Shop.ShopTabs.ProductFeed' : 'Root.ProductFeed';

        $fields->addFieldsToTab($tab, [
            ListboxField::create(
                'ProductFeedIncludeBrands',
                _t(self::class . '.IncludeBrands', 'Only these brands (leave empty for all)'),
                $options
            ),
            ListboxField::create(
                'ProductFeedExcludeBrands',
                _t(self::class . '.ExcludeBrands', 'Exclude these brands'),
                $options
            ),
        ]);
    }

    /**
     * Brand id => title, read through the relation's own data class so the Brand class is never named here.
     *
     * @return array<int, string>
     */
    private function brandOptions(): array
    {
        $dataClass = $this->owner->getManyManyComponents('ProductFeedIncludeBrands')->dataClass();
        if (!is_string($dataClass) || $dataClass === '' || !class_exists($dataClass)) {
            return [];
        }

        /** @var array<int, string> $map */
        $map = $dataClass::get()->map('ID', 'Title')->toArray();

        return $map;
    }
}
