<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Registry;

use SilverShop\ProductFeed\Format\ChannelFeed;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Registry of the feed channels. Channels are declared in config (key => Injector service) so a connector plugs in
 * without the core knowing about it — the exact analogue of {@code silvershop/seo}'s {@code StructuredDataRegistry}
 * and {@code silvershop/newsletter}'s {@code NewsletterProviderRegistry}:
 *
 *   SilverShop\ProductFeed\Registry\ChannelFeedRegistry:
 *     channels:
 *       google: '%$SilverShop\ProductFeed\Google\GoogleChannelFeed'
 *     enabled_channels:
 *       - google
 *
 * {@see self::all()} lists every registered channel; {@see self::enabled()} lists only those a shop has turned on
 * (which the generator writes and the recurring job regenerates). A channel is keyed by its own
 * {@link ChannelFeed::getChannelKey()}, so a mis-keyed config entry can't shadow another channel.
 */
class ChannelFeedRegistry
{
    use Configurable;
    use Injectable;

    /**
     * @var array<string, string> channel key => Injector service name / class. The shipped {@code debug} channel is
     *      a non-production diagnostic format and is intentionally NOT in {@see self::$enabled_channels}.
     */
    private static array $channels = [];

    /**
     * @var array<int, string> keys of the channels a shop has turned on. Empty by default: a fresh install writes
     *      no feed until a connector is installed and its key enabled here.
     */
    private static array $enabled_channels = [];

    /**
     * Every registered channel, keyed by its channel key, in config order.
     *
     * @return array<string, ChannelFeed>
     */
    public function all(): array
    {
        $channels = [];
        foreach ((array) static::config()->get('channels') as $service) {
            if (!$service) {
                continue;
            }
            $channel = Injector::inst()->get($service);
            if ($channel instanceof ChannelFeed) {
                $channels[$channel->getChannelKey()] = $channel;
            }
        }

        return $channels;
    }

    /**
     * One registered channel by key, or null when the key is unknown.
     */
    public function get(string $key): ?ChannelFeed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether the channel key is registered at all.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * The enabled channels (registered AND listed in {@see self::$enabled_channels}), keyed by channel key. These
     * are the channels {@link \SilverShop\ProductFeed\Service\ProductFeedGenerator::generateAll()} writes.
     *
     * @return array<string, ChannelFeed>
     */
    public function enabled(): array
    {
        $enabledKeys = array_values(array_unique(array_map(
            static fn ($key): string => (string) $key,
            (array) static::config()->get('enabled_channels')
        )));

        $all = $this->all();
        $enabled = [];
        foreach ($enabledKeys as $key) {
            if (isset($all[$key])) {
                $enabled[$key] = $all[$key];
            }
        }

        return $enabled;
    }

    /**
     * Whether a channel is both registered and enabled.
     */
    public function isEnabled(string $key): bool
    {
        return isset($this->enabled()[$key]);
    }
}
