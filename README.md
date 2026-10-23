# SilverShop Product Feed

[![CI](https://github.com/silvershop-suite/silvershop-product-feed/actions/workflows/ci.yml/badge.svg)](https://github.com/silvershop-suite/silvershop-product-feed/actions/workflows/ci.yml)

The **channel-agnostic product-feed core** for [SilverShop](https://github.com/silvershop/silvershop) on
Silverstripe CMS 6. It selects feed-eligible catalogue products, normalises each one through
[`silvershop-suite/catalog-data`](https://github.com/silvershop-suite/silvershop-catalog-data), hands them to a pluggable
channel serializer, writes a static feed file **atomically**, and regenerates it on a schedule.

The core knows **no channel specifics**. A channel connector — e.g. `silvershop-suite/product-feed-google` (RSS 2.0 +
the Google `g:` namespace) — plugs into the `ChannelFeed` / `FeedFormat` registry seam and the core generates and
serves its feed without any change to the core. This mirrors `silvershop-suite/newsletter` + its ESP connectors and
`silvershop-suite/seo`'s structured-data registry.

```
catalogue ─▶ ProductFeedSelector ─▶ CatalogProductDataFactory ─▶ ChannelFeed::mapItem() ─▶ FeedFormat (serialise)
             (which products?)        (one normalised VO)          (channel attributes)      ─▶ static file (atomic)
```

## Requirements

- PHP 8.3+
- `silverstripe/framework` ^6.0, `silverstripe/cms` ^6.0
- `silvershop/core` ^6.2
- `silvershop-suite/catalog-data` (the shared product-data mapper)
- `symbiote/silverstripe-queuedjobs` ^6 (scheduling)

Optional (soft, guarded): `silvershop-suite/brands` (brand include/exclude selection), `silvershop-suite/product-identity`
(GTIN/MPN, surfaced through catalog-data).

> **Release ordering.** `silvershop-suite/catalog-data` is not on Packagist yet, so the CI / a normal `composer require`
> is **release-blocked until catalog-data is published** — a known release-ordering dependency, exactly like
> `silvershop-suite/newsletter` → its ESP connectors. For development and the clean-room, install catalog-data (and its
> own guarded soft-deps) as **path repositories**:
>
> ```json
> "repositories": [
>   { "type": "path", "url": "../silvershop-catalog-data", "options": { "symlink": false } }
> ]
> ```

## Installation

```bash
composer require silvershop-suite/product-feed
```

Then run `dev/build?flush=all` (adds the per-product `ExcludeFromFeed` flag and the SiteConfig "Product feed" tab).

Out of the box **no feed is generated**: there is no production channel in the core. Install a connector (e.g.
`silvershop-suite/product-feed-google`) and enable its key, or enable the shipped non-production `debug` channel to try
the pipeline.

## The seam — writing a channel connector

A channel is a pair of small objects:

1. **`ChannelFeed`** — the field map. Implement (or extend `AbstractChannelFeed`) and return the channel's
   attribute array for one normalised item:

   ```php
   use SilverShop\CatalogData\CatalogProductData;
   use SilverShop\ProductFeed\Format\AbstractChannelFeed;
   use SilverShop\ProductFeed\Format\FeedFormat;

   class GoogleChannelFeed extends AbstractChannelFeed
   {
       public function getChannelKey(): string { return 'google'; }
       public function getFormat(): FeedFormat { return RssGFormat::create(); }

       public function mapItem(CatalogProductData $data): array
       {
           return [
               'g:id' => $data->feedId(),
               'g:title' => $data->title(),
               'g:price' => $data->formattedPrice(),      // "12.95 EUR"
               'g:availability' => $data->availability()->value,
               // …
           ];
       }
   }
   ```

2. **`FeedFormat`** — the syntax. `renderHeader()` / `renderItem(array)` / `renderFooter()` stream the file, plus
   `getContentType()` and `getFileExtension()`. The core streams the feed one item at a time, so even a huge
   catalogue is never held in memory.

Register the channel and enable it:

```yaml
SilverShop\ProductFeed\Registry\ChannelFeedRegistry:
  channels:
    google: '%$My\GoogleChannelFeed'
  enabled_channels:
    - google
```

The core ships a deliberately **non-production** `debug` channel (a JSON-Lines dump of the full
`CatalogProductData` VO) so you can inspect exactly what the catalogue maps to. It is registered but **not**
enabled.

## Selecting products

On the CMS *Settings → Shop → Product feed* tab (editor-facing):

- **include / exclude categories** (recursive, via the core's `ProductsShowable()`);
- **minimum price** floor;
- **include / exclude brands** (only with `silvershop-suite/brands`).

Plus a per-product **Exclude from feed** flag on each product. The hard rules are always applied: only **published**
products on the Live stage, only **purchasable** buyables. Products with variations are emitted as separate items;
`catalog-data` supplies the shared `item_group_id` so a channel can group variants.

The product id in every feed is `catalog-data`'s locked `feedId()`, so a product's `id` here and its
`content_ids` in conversion tracking agree by construction.

## Generating, scheduling & serving

- **Schedule:** `GenerateProductFeedJob` is a recurring, self-re-arming QueuedJob (default nightly,
  `regenerate_interval_mins`). Arm it once with `--schedule` (below).
- **CLI / on demand:** `GenerateProductFeedTask` — `sake tasks:ProductFeed-Generate` (all enabled channels),
  `--channel=google` (one), `--schedule` (arm the job). Also runnable over HTTP at
  `dev/tasks/ProductFeed-Generate` (preferred where core's `sellingPrice()` touches the cart session).
- **Serving:** files are written to `public/feeds/<channel>.<ext>` (config `output_dir`) so your web server can
  serve them directly at zero request-time cost. A convenience route `feed/<channel>` (config `url_segment`) also
  streams the cached file with the right `Content-Type` + cache headers, 404ing on an unknown or not-yet-generated
  channel. Set `ProductFeedController.access_token` to require `?token=…` for an obfuscated, catalogue-exposing
  feed.

### Key config

```yaml
SilverShop\ProductFeed\Service\ProductFeedGenerator:
  output_dir: 'feeds'       # under the public web root
  chunk_size: 100           # products per ORM-reset chunk (memory discipline)

SilverShop\ProductFeed\Service\ProductFeedSelector:
  reading_stage: 'Live'     # publish state the feed reads

SilverShop\ProductFeed\Job\GenerateProductFeedJob:
  regenerate_interval_mins: 1440

SilverShop\ProductFeed\Control\ProductFeedController:
  cache_max_age: 3600
  access_token: ''
```

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
