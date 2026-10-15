<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Extension;

use SilverShop\Page\ProductCategory;
use SilverShop\ProductFeed\Control\ProductFeedController;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TreeMultiselectField;

/**
 * The CMS-editable product-feed selection config, on a dedicated "Product feed" tab on {@link SiteConfig}. These are
 * the knobs a shop editor tunes to control *which* products appear in every channel's feed:
 *
 *  - include / exclude by category (recursive);
 *  - a minimum selling price floor.
 *
 * Brand include/exclude is added by {@link ProductFeedBrandSiteConfigExtension} only when {@code silvershop/brands}
 * is installed. Which *channels* are enabled is a developer/config concern ({@code ChannelFeedRegistry}) and is shown
 * here read-only for reference.
 *
 * @property float $ProductFeedMinPrice
 * @extends Extension<\SilverStripe\SiteConfig\SiteConfig>
 */
class ProductFeedSiteConfigExtension extends Extension
{
    private static array $db = [
        'ProductFeedMinPrice' => 'Currency',
    ];

    private static array $many_many = [
        'ProductFeedIncludeCategories' => ProductCategory::class,
        'ProductFeedExcludeCategories' => ProductCategory::class,
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $tab = $fields->fieldByName('Root.Shop') ? 'Root.Shop.ShopTabs.ProductFeed' : 'Root.ProductFeed';

        $fields->addFieldsToTab($tab, [
            HeaderField::create('ProductFeedSelectionHeader', _t(self::class . '.SelectionHeader', 'Which products to include')),
            TreeMultiselectField::create(
                'ProductFeedIncludeCategories',
                _t(self::class . '.IncludeCategories', 'Only these categories (leave empty for all)'),
                ProductCategory::class
            )->setDescription(_t(
                self::class . '.IncludeCategoriesDesc',
                'When set, only products in these categories (and their sub-categories) are fed.'
            )),
            TreeMultiselectField::create(
                'ProductFeedExcludeCategories',
                _t(self::class . '.ExcludeCategories', 'Exclude these categories'),
                ProductCategory::class
            ),
            NumericField::create(
                'ProductFeedMinPrice',
                _t(self::class . '.MinPrice', 'Minimum price')
            )->setScale(2)->setDescription(_t(
                self::class . '.MinPriceDesc',
                'Products selling below this price are left out. 0 includes all prices.'
            )),
        ]);

        $fields->addFieldToTab($tab, $this->enabledChannelsField());
        $fields->findOrMakeTab($tab)->setTitle(_t(self::class . '.TabTitle', 'Product feed'));
    }

    /**
     * A read-only summary of the enabled channels, their output files and serving URLs, so an editor can see the
     * feed wiring without needing config access.
     */
    private function enabledChannelsField(): LiteralField
    {
        $registry = ChannelFeedRegistry::create();
        $generator = ProductFeedGenerator::create();
        $controller = ProductFeedController::create();

        $enabled = $registry->enabled();
        if ($enabled === []) {
            $body = '<p>' . _t(
                self::class . '.NoChannels',
                'No feed channels are enabled yet. Install a channel connector (e.g. silvershop/product-feed-google) '
                . 'and enable its key in the ChannelFeedRegistry.enabled_channels config.'
            ) . '</p>';
        } else {
            $rows = '';
            foreach ($enabled as $key => $channel) {
                $rows .= sprintf(
                    '<tr><td><strong>%s</strong></td><td><code>%s</code></td><td><code>%s</code></td></tr>',
                    htmlspecialchars($channel->getTitle(), ENT_QUOTES),
                    htmlspecialchars($channel->getFileName(), ENT_QUOTES),
                    htmlspecialchars((string) $controller->LinkForChannel($key), ENT_QUOTES)
                );
            }
            $body = '<table class="table"><thead><tr><th>Channel</th><th>File</th><th>URL</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>'
                . '<p>' . sprintf(
                    _t(self::class . '.OutputDir', 'Feed files are written to %s.'),
                    '<code>' . htmlspecialchars($generator->outputDir(), ENT_QUOTES) . '</code>'
                ) . '</p>';
        }

        return LiteralField::create(
            'ProductFeedChannels',
            '<div class="product-feed-channels"><h3>'
            . _t(self::class . '.ChannelsHeader', 'Enabled channels')
            . '</h3>' . $body . '</div>'
        );
    }
}
