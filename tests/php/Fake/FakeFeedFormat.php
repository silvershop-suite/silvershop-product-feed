<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests\Fake;

use SilverShop\ProductFeed\Format\ChannelFeed;
use SilverShop\ProductFeed\Format\FeedFormat;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\TestOnly;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * A deterministic test serializer: header + one plain line per item + footer, so a test can assert exactly what the
 * generation pipeline wrote (and that the values came from the real {@link \SilverShop\CatalogData\CatalogProductData}
 * VO).
 */
class FakeFeedFormat implements FeedFormat, TestOnly
{
    use Injectable;

    public function getContentType(): string
    {
        return 'text/plain; charset=utf-8';
    }

    public function getFileExtension(): string
    {
        return 'txt';
    }

    public function renderHeader(SiteConfig $siteConfig, ChannelFeed $channel): string
    {
        return '<<HEAD channel=' . $channel->getChannelKey() . ">>\n";
    }

    public function renderItem(array $mapped): string
    {
        return sprintf(
            "ITEM id=%s price=%s avail=%s title=%s\n",
            (string) ($mapped['id'] ?? ''),
            (string) ($mapped['price'] ?? ''),
            (string) ($mapped['availability'] ?? ''),
            (string) ($mapped['title'] ?? '')
        );
    }

    public function renderFooter(): string
    {
        return "<<FOOT>>\n";
    }
}
