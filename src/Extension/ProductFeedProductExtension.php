<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;

/**
 * Adds the per-product "Exclude from feed" flag to {@link \SilverShop\Page\Product}. When set, the product (and all
 * its variations) is left out of every channel's feed regardless of the other selection rules.
 *
 * @property bool $ExcludeFromFeed
 * @extends Extension<\SilverShop\Page\Product>
 */
class ProductFeedProductExtension extends Extension
{
    private static array $db = [
        'ExcludeFromFeed' => 'Boolean',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $checkbox = CheckboxField::create(
            'ExcludeFromFeed',
            _t(self::class . '.ExcludeFromFeed', 'Exclude this product from product feeds')
        )->setDescription(_t(
            self::class . '.ExcludeFromFeedDesc',
            'Leaves this product (and its variations) out of every shopping-channel feed.'
        ));

        // Product auto-scaffolds extension $db fields, so the checkbox is already on the form — remove the
        // scaffolded copy and add it back once (the scaffold-duplicate pattern used across the suite) on the
        // Settings tab, falling back to Main.
        $fields->removeByName('ExcludeFromFeed');
        $tab = $fields->fieldByName('Root.Settings') ? 'Root.Settings' : 'Root.Main';
        $fields->addFieldToTab($tab, $checkbox);
    }
}
