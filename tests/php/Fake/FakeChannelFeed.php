<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests\Fake;

use SilverShop\CatalogData\CatalogProductData;
use SilverShop\Model\Buyable;
use SilverShop\ProductFeed\Format\AbstractChannelFeed;
use SilverShop\ProductFeed\Format\FeedFormat;
use SilverStripe\Dev\TestOnly;

/**
 * A fake channel (key {@code fake}) mapping the normalised VO onto a tiny attribute array consumed by
 * {@link FakeFeedFormat}. An item whose feed id starts with {@code SKIP} maps to an empty array, exercising the
 * generator's "skip this item" path.
 */
class FakeChannelFeed extends AbstractChannelFeed implements TestOnly
{
    public function getChannelKey(): string
    {
        return 'fake';
    }

    public function getTitle(): string
    {
        return 'Fake test channel';
    }

    public function getFormat(): FeedFormat
    {
        return FakeFeedFormat::create();
    }

    public function mapItem(CatalogProductData $data, ?Buyable $buyable = null): array
    {
        if (str_starts_with($data->feedId(), 'SKIP')) {
            return [];
        }

        return [
            'id' => $data->feedId(),
            'price' => $data->formattedPrice(),
            'availability' => $data->availability()->value,
            'title' => $data->title(),
        ];
    }
}
