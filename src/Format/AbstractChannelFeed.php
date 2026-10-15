<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Format;

use SilverStripe\Core\Injector\Injectable;

/**
 * Convenience base for a {@link ChannelFeed}: derives the file name from the channel key and the format's file
 * extension, and the display title from the key, so a connector only implements {@see self::getChannelKey()},
 * {@see self::getFormat()} and {@see self::mapItem()}. Mirrors {@code silvershop/seo}'s
 * {@code AbstractStructuredDataProvider}.
 */
abstract class AbstractChannelFeed implements ChannelFeed
{
    use Injectable;

    public function getTitle(): string
    {
        return ucfirst($this->getChannelKey());
    }

    public function getFileName(): string
    {
        return $this->getChannelKey() . '.' . $this->getFormat()->getFileExtension();
    }
}
