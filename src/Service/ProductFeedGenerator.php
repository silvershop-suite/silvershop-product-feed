<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Service;

use RuntimeException;
use SilverShop\CatalogData\CatalogProductDataFactory;
use SilverShop\Model\Buyable;
use SilverShop\Page\Product;
use SilverShop\ProductFeed\Format\ChannelFeed;
use SilverShop\ProductFeed\Format\FeedFormat;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

/**
 * The feed engine: iterate the selected buyables → normalise each through {@link CatalogProductDataFactory} → map
 * via the channel → serialise via its {@link \SilverShop\ProductFeed\Format\FeedFormat} → stream to a static file
 * written atomically (a {@code .temp} file renamed into place). This is the {@code viva-shopping-feeds} generator
 * re-grounded on the suite: the connector supplies the field map + format, the core owns selection, iteration,
 * memory discipline, file IO and scheduling.
 *
 * Memory discipline for large catalogues: products are processed in ID chunks and {@link DataObject::reset()} is
 * called after each chunk so the ORM's identity/field caches don't grow unbounded. The whole run happens inside the
 * configured reading stage (Live by default) so only published products are emitted.
 */
class ProductFeedGenerator
{
    use Injectable;
    use Configurable;

    /**
     * Directory the static feed files are written to, relative to the public web root (falling back to the base
     * folder when there is no {@code public/} web root).
     */
    private static string $output_dir = 'feeds';

    /**
     * How many products to load per chunk before resetting the ORM caches. Bounds peak memory on big catalogues.
     */
    private static int $chunk_size = 100;

    private readonly ChannelFeedRegistry $registry;

    private readonly ProductFeedSelector $selector;

    private readonly CatalogProductDataFactory $factory;

    public function __construct(
        ?ChannelFeedRegistry $registry = null,
        ?ProductFeedSelector $selector = null,
        ?CatalogProductDataFactory $factory = null,
    ) {
        $this->registry = $registry ?? ChannelFeedRegistry::create();
        $this->selector = $selector ?? ProductFeedSelector::create();
        $this->factory = $factory ?? CatalogProductDataFactory::create();
    }

    /**
     * Generate one channel's feed to disk atomically and return a result describing the write.
     *
     * @return array{channel: string, path: string, items: int}
     */
    public function generate(ChannelFeed $channel): array
    {
        $this->ensureOutputDir();

        $finalPath = $this->filePathFor($channel);
        $tempPath = $finalPath . '.temp';

        $handle = fopen($tempPath, 'w');
        if ($handle === false) {
            throw new RuntimeException(sprintf('product-feed: cannot open "%s" for writing.', $tempPath));
        }

        $items = 0;
        try {
            $items = Versioned::withVersionedMode(function () use ($channel, $handle): int {
                Versioned::set_stage($this->selector->readingStage());

                return $this->stream($channel, $handle);
            });
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tempPath);
            throw $e;
        }

        fflush($handle);
        fclose($handle);

        // Atomic publish: a reader always sees either the previous complete file or the new complete file.
        if (!rename($tempPath, $finalPath)) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf('product-feed: cannot move the generated feed into "%s".', $finalPath));
        }

        return ['channel' => $channel->getChannelKey(), 'path' => $finalPath, 'items' => $items];
    }

    /**
     * Generate a feed for every enabled channel.
     *
     * @return array<string, array{channel: string, path: string, items: int}>
     */
    public function generateAll(): array
    {
        $results = [];
        foreach ($this->registry->enabled() as $key => $channel) {
            $results[$key] = $this->generate($channel);
        }

        return $results;
    }

    /**
     * Generate one named channel (enabled or not — the CLI task can target any registered channel). Returns null
     * when the key is not registered.
     *
     * @return array{channel: string, path: string, items: int}|null
     */
    public function generateChannel(string $key): ?array
    {
        $channel = $this->registry->get($key);

        return $channel ? $this->generate($channel) : null;
    }

    /**
     * Absolute path of the static file for a channel (whether or not it has been generated yet).
     */
    public function filePathFor(ChannelFeed $channel): string
    {
        return $this->outputDir() . '/' . $channel->getFileName();
    }

    /**
     * Absolute path of the output directory. {@code output_dir} is normally relative to the public web root; an
     * absolute value (leading {@code /}) is used as-is.
     */
    public function outputDir(): string
    {
        $dir = (string) static::config()->get('output_dir');
        if ($dir !== '' && str_starts_with($dir, '/')) {
            return rtrim($dir, '/');
        }

        $base = Director::publicFolder() ?: Director::baseFolder();
        $dir = trim($dir, '/');

        return rtrim($base, '/') . ($dir !== '' ? '/' . $dir : '');
    }

    /**
     * Stream the whole feed into an open file handle: header, one block per eligible buyable (chunked for memory),
     * footer. Returns the number of items written.
     *
     * @param resource $handle
     */
    private function stream(ChannelFeed $channel, $handle): int
    {
        $format = $channel->getFormat();
        $siteConfig = SiteConfig::current_site_config();

        fwrite($handle, $format->renderHeader($siteConfig, $channel));

        $items = 0;
        $chunkSize = max(1, (int) static::config()->get('chunk_size'));
        $productIds = $this->selector->eligibleProducts($siteConfig)->sort('ID')->column('ID');

        foreach (array_chunk($productIds, $chunkSize) as $chunk) {
            $products = Product::get()->byIDs($chunk)->sort('ID');
            foreach ($products as $product) {
                foreach ($this->selector->buyablesFor($product) as $buyable) {
                    if (!$this->selector->isEligible($buyable, $siteConfig)) {
                        continue;
                    }
                    $block = $this->renderItem($channel, $format, $buyable);
                    if ($block !== '') {
                        fwrite($handle, $block);
                        $items++;
                    }
                }
            }
            // Keep ORM caches bounded across a large catalogue.
            DataObject::reset();
        }

        fwrite($handle, $format->renderFooter());

        return $items;
    }

    private function renderItem(ChannelFeed $channel, FeedFormat $format, Buyable $buyable): string
    {
        $data = $this->factory->forBuyable($buyable);
        $mapped = $channel->mapItem($data, $buyable);
        if ($mapped === []) {
            return '';
        }

        return $format->renderItem($mapped);
    }

    private function ensureOutputDir(): void
    {
        $dir = $this->outputDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('product-feed: cannot create the output directory "%s".', $dir));
        }
    }
}
