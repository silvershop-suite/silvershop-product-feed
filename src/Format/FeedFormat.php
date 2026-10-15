<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Format;

use SilverStripe\SiteConfig\SiteConfig;

/**
 * The serializer half of the feed seam: it knows the *syntax* of a feed file (RSS 2.0, Atom, TSV, JSON, …) but
 * nothing about which catalogue fields go where — that is the {@link ChannelFeed}'s job. The generator streams a
 * feed by writing the header once, then one rendered block per mapped item, then the footer, so a huge catalogue
 * never has to be held in memory at once.
 *
 * A channel connector pairs a {@link ChannelFeed} (the field map) with a {@link FeedFormat} (the syntax). The core
 * ships only a deliberately non-production {@link \SilverShop\ProductFeed\Format\Debug\DebugFeedFormat}; real
 * formats (e.g. the Google RSS {@code g:} serializer) live in their connector module and register themselves.
 */
interface FeedFormat
{
    /**
     * The HTTP {@code Content-Type} (including charset) the serving route sends for this format, e.g.
     * {@code "application/xml; charset=utf-8"}.
     */
    public function getContentType(): string;

    /**
     * The file extension (no dot) for the generated static file, e.g. {@code "xml"}. Combined with the channel key
     * to form the default file name.
     */
    public function getFileExtension(): string;

    /**
     * Opening bytes of the feed, written once before any item. The channel is passed so a format can incorporate
     * channel/site metadata (feed title, link, …) in its header.
     */
    public function renderHeader(SiteConfig $siteConfig, ChannelFeed $channel): string;

    /**
     * One item block, built from the channel's mapped attribute array ({@see ChannelFeed::mapItem()}). Called once
     * per feed-eligible buyable, in iteration order.
     *
     * @param array<string, mixed> $mapped
     */
    public function renderItem(array $mapped): string;

    /**
     * Closing bytes of the feed, written once after the last item.
     */
    public function renderFooter(): string;
}
