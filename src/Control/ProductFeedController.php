<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Control;

use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;

/**
 * Serves a channel's pre-generated static feed file over HTTP at {@code feed/<channel>} (configurable). This is a
 * convenience: the files live under the public web root ({@code public/feeds/<channel>.<ext>} by default) so your
 * web server can serve them directly at zero request-time cost — the recurring job owns freshness, there is no
 * per-request render. The controller adds the correct {@code Content-Type}, cache headers, an optional access token,
 * and a clean 404 when a channel is unknown or not yet generated.
 *
 * The URL segment is {@code feed} by default; override the route rule in {@code _config/routes.yml} (and
 * {@code ProductFeedController.url_segment}) to change it.
 */
class ProductFeedController extends Controller
{
    use Configurable;

    private static string $url_segment = 'feed';

    /**
     * {@code max-age} (seconds) sent on the served feed so clients / CDNs may cache it. The static file is already
     * pre-rendered, so this is purely an HTTP caching hint; 0 disables it.
     */
    private static int $cache_max_age = 3600;

    /**
     * Optional shared secret. When set, a request must carry {@code ?token=<value>} or it 404s — a lightweight way
     * to obfuscate a feed that exposes the whole catalogue. Empty (default) serves without a token.
     */
    private static string $access_token = '';

    private static array $allowed_actions = [
        'index',
    ];

    private static array $url_handlers = [
        '$Channel!' => 'index',
        '' => 'index',
    ];

    public function index(HTTPRequest $request): HTTPResponse
    {
        $token = (string) static::config()->get('access_token');
        if ($token !== '' && (string) $request->getVar('token') !== $token) {
            return $this->notFound();
        }

        // Accept both `feed/google` and `feed/google.xml`; the channel key carries no dot.
        $key = (string) preg_replace('/\..*$/', '', (string) $request->param('Channel'));
        if ($key === '') {
            return $this->notFound();
        }

        $channel = ChannelFeedRegistry::create()->get($key);
        if ($channel === null) {
            return $this->notFound();
        }

        $path = ProductFeedGenerator::create()->filePathFor($channel);
        if (!is_file($path)) {
            return $this->notFound();
        }

        $body = file_get_contents($path);
        if ($body === false) {
            return $this->notFound();
        }

        $response = HTTPResponse::create($body, 200);
        $response->addHeader('Content-Type', $channel->getFormat()->getContentType());

        $maxAge = (int) static::config()->get('cache_max_age');
        if ($maxAge > 0) {
            $response->addHeader('Cache-Control', 'public, max-age=' . $maxAge);
        }
        $mtime = filemtime($path);
        if ($mtime !== false) {
            $response->addHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }

        return $response;
    }

    public function Link($action = null): string
    {
        return Controller::join_links(
            Director::baseURL(),
            (string) static::config()->get('url_segment'),
            $action
        );
    }

    /**
     * Public URL for a channel's feed, e.g. {@code https://shop.example/feed/google}.
     */
    public function LinkForChannel(string $channelKey): string
    {
        return $this->Link($channelKey);
    }

    private function notFound(): HTTPResponse
    {
        return HTTPResponse::create('Feed not found', 404);
    }
}
