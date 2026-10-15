<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Tests;

use SilverShop\ProductFeed\Control\ProductFeedController;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverShop\ProductFeed\Tests\Fake\FakeChannelFeed;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;

/**
 * The serving route {@code feed/<channel>}: a generated file is served with the right content-type, an unknown
 * channel or a not-yet-generated file 404s, and the optional access token gates the response.
 */
class ProductFeedControllerTest extends FunctionalTest
{
    protected $usesDatabase = true;

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(ChannelFeedRegistry::class, 'channels', ['fake' => FakeChannelFeed::class]);
        Config::modify()->set(ChannelFeedRegistry::class, 'enabled_channels', ['fake']);
        Config::modify()->set(ProductFeedController::class, 'access_token', '');

        $this->outputDir = sys_get_temp_dir() . '/pf-ctrl-' . uniqid('', true);
        Config::modify()->set(ProductFeedGenerator::class, 'output_dir', $this->outputDir);
        mkdir($this->outputDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            foreach (glob($this->outputDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->outputDir);
        }
        parent::tearDown();
    }

    private function writeFakeFeed(): string
    {
        $path = $this->outputDir . '/fake.txt';
        file_put_contents($path, "<<HEAD channel=fake>>\nITEM id=X price=1.00 EUR\n<<FOOT>>\n");

        return $path;
    }

    public function testServesGeneratedFileWithContentType(): void
    {
        $this->writeFakeFeed();

        $response = $this->get('feed/fake');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', (string) $response->getHeader('Content-Type'));
        $this->assertStringContainsString('ITEM id=X', (string) $response->getBody());
    }

    public function testServesWithExtensionInUrl(): void
    {
        $this->writeFakeFeed();

        $response = $this->get('feed/fake.txt');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testServedFeedCarriesCacheHeaders(): void
    {
        Config::modify()->set(ProductFeedController::class, 'cache_max_age', 1800);
        $this->writeFakeFeed();

        $response = $this->get('feed/fake');
        $this->assertStringContainsString('max-age=1800', (string) $response->getHeader('Cache-Control'));
        $this->assertNotEmpty($response->getHeader('Last-Modified'));
    }

    public function testUnknownChannelIs404(): void
    {
        $response = $this->get('feed/nope');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testNotYetGeneratedIs404(): void
    {
        // Registered channel, but no file written.
        $response = $this->get('feed/fake');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAccessTokenGate(): void
    {
        Config::modify()->set(ProductFeedController::class, 'access_token', 's3cret');
        $this->writeFakeFeed();

        $this->assertSame(404, $this->get('feed/fake')->getStatusCode());
        $this->assertSame(404, $this->get('feed/fake?token=wrong')->getStatusCode());
        $this->assertSame(200, $this->get('feed/fake?token=s3cret')->getStatusCode());
    }
}
