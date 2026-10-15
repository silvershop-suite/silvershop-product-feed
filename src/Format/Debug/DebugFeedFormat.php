<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Format\Debug;

use SilverShop\ProductFeed\Format\ChannelFeed;
use SilverShop\ProductFeed\Format\FeedFormat;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * A deliberately NON-PRODUCTION reference format: newline-delimited JSON (JSON Lines), one object per item. It
 * exists so the core is usable and testable standalone — to eyeball the normalised {@link CatalogProductData}
 * fields a channel would map, or to smoke-test the generation/serving pipeline — WITHOUT shipping a real channel
 * serializer (Google RSS lives in the separate {@code silvershop/product-feed-google} connector).
 *
 * Do not point a real merchant channel at this format.
 */
class DebugFeedFormat implements FeedFormat
{
    use Injectable;

    public function getContentType(): string
    {
        return 'application/x-ndjson; charset=utf-8';
    }

    public function getFileExtension(): string
    {
        return 'jsonl';
    }

    public function renderHeader(SiteConfig $siteConfig, ChannelFeed $channel): string
    {
        // A leading comment line makes it obvious at a glance that this is a diagnostic artifact, not a feed a
        // channel should consume. JSON parsers that read line-by-line simply skip a `#` line.
        return sprintf(
            "# silvershop/product-feed debug feed — channel \"%s\" — NOT a production feed format\n",
            $channel->getChannelKey()
        );
    }

    public function renderItem(array $mapped): string
    {
        return json_encode($mapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    public function renderFooter(): string
    {
        return '';
    }
}
