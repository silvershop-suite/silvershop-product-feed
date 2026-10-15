<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Format;

use SilverShop\CatalogData\CatalogProductData;
use SilverShop\Model\Buyable;

/**
 * The field-mapping half of the feed seam — the interface a channel connector implements and registers so the core
 * can generate and serve its feed without knowing anything channel-specific. This is the product-feed analogue of
 * {@code silvershop/seo}'s {@code StructuredDataProvider} registry and {@code silvershop/newsletter}'s
 * {@code NewsletterProvider} registry.
 *
 * A channel is a pair: this object maps a normalised {@link CatalogProductData} VO onto the channel's attribute
 * array ({@see self::mapItem()}), and its {@link FeedFormat} ({@see self::getFormat()}) serialises that array into
 * the feed file's syntax. Connectors register under a stable key in config:
 *
 *   SilverShop\ProductFeed\Registry\ChannelFeedRegistry:
 *     channels:
 *       google: '%$SilverShop\ProductFeed\Google\GoogleChannelFeed'
 *     enabled_channels:
 *       - google
 *
 * See {@link AbstractChannelFeed} for sensible defaults ({@see self::getFileName()} / {@see self::getTitle()}) so a
 * connector only has to implement the key, the format and the mapping.
 */
interface ChannelFeed
{
    /**
     * Short, stable key for this channel, used in config, the generated file name and the serving URL
     * (e.g. {@code "google"}). Lower-case, no spaces.
     */
    public function getChannelKey(): string;

    /**
     * Human-readable channel name for CMS / CLI display, e.g. {@code "Google Merchant"}.
     */
    public function getTitle(): string;

    /**
     * The static file name written for this channel, e.g. {@code "google.xml"}. Defaults (in
     * {@link AbstractChannelFeed}) to {@code "<channelKey>.<format file extension>"}.
     */
    public function getFileName(): string;

    /**
     * The serializer that turns {@see self::mapItem()} output into feed-file bytes.
     */
    public function getFormat(): FeedFormat;

    /**
     * Map one normalised catalogue item onto this channel's attribute array (e.g. Google's {@code g:*} keys). The
     * returned array is handed verbatim to {@link FeedFormat::renderItem()}. Return an empty array to skip the item.
     *
     * The normalised {@link CatalogProductData} VO carries everything most channels need. The source buyable is
     * also passed for the rare channel-specific lookup the format-agnostic VO deliberately does not expose — e.g.
     * the Google connector resolving {@code google_product_category} from the product's own category tree. It may be
     * {@code null} when a caller maps a VO without a backing record.
     *
     * @return array<string, mixed>
     */
    public function mapItem(CatalogProductData $data, ?Buyable $buyable = null): array;
}
