<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Format\Debug;

use SilverShop\CatalogData\CatalogProductData;
use SilverShop\Model\Buyable;
use SilverShop\ProductFeed\Format\AbstractChannelFeed;
use SilverShop\ProductFeed\Format\FeedFormat;
use SilverStripe\Core\Injector\Injector;

/**
 * A diagnostic channel (key {@code debug}) that dumps the full normalised {@link CatalogProductData} VO as JSON
 * Lines via {@link DebugFeedFormat}. Registered by default but NOT enabled, so it never generates unless a
 * developer turns it on (or targets it with the CLI task) to inspect exactly what the catalogue maps to.
 *
 * It is NOT a merchant feed — a real channel (e.g. Google) ships in its own connector.
 */
class DebugChannelFeed extends AbstractChannelFeed
{
    public function getChannelKey(): string
    {
        return 'debug';
    }

    public function getTitle(): string
    {
        return 'Debug (diagnostic, non-production)';
    }

    public function getFormat(): FeedFormat
    {
        return Injector::inst()->get(DebugFeedFormat::class);
    }

    public function mapItem(CatalogProductData $data, ?Buyable $buyable = null): array
    {
        return [
            'id' => $data->feedId(),
            'item_group_id' => $data->itemGroupId(),
            'title' => $data->title(),
            'description' => $data->description(),
            'link' => $data->link(),
            'image_link' => $data->imageLink(),
            'additional_image_links' => $data->additionalImageLinks(),
            'availability' => $data->availability()->value,
            'price' => $data->formattedPrice(),
            'sale_price' => $data->formattedSalePrice(),
            'brand' => $data->brand(),
            'gtin' => $data->gtin(),
            'mpn' => $data->mpn(),
            'identifier_exists' => $data->identifierExists(),
            'condition' => $data->condition()->value,
            'product_type' => $data->productType(),
            'attributes' => $data->attributes(),
        ];
    }
}
