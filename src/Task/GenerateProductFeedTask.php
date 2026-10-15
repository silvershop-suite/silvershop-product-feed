<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Task;

use SilverShop\ProductFeed\Job\GenerateProductFeedJob;
use SilverShop\ProductFeed\Registry\ChannelFeedRegistry;
use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * CLI / HTTP runner for feed generation — the same work the recurring {@link GenerateProductFeedJob} does, runnable
 * on demand. With no options it regenerates every enabled channel; {@code --channel=<key>} targets one registered
 * channel (enabled or not); {@code --schedule} (re-)arms the recurring job.
 *
 * Note: core's {@code sellingPrice()} can touch the cart session, so prefer running this over HTTP
 * ({@code dev/tasks/ProductFeed-Generate}) rather than bare CLI on sites that hit that path.
 */
class GenerateProductFeedTask extends BuildTask
{
    protected static string $commandName = 'ProductFeed-Generate';

    protected string $title = 'Generate product feeds';

    protected static string $description =
        'Writes the static product-feed file(s). No options = all enabled channels; --channel=<key> targets one.';

    public function getOptions(): array
    {
        return [
            new InputOption('channel', null, InputOption::VALUE_REQUIRED, 'Generate only this channel key (e.g. google).'),
            new InputOption('schedule', null, InputOption::VALUE_NONE, 'Queue the recurring regeneration job if not already scheduled.'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if ($input->getOption('schedule')) {
            GenerateProductFeedJob::scheduleNext();
            $output->writeln('Recurring regeneration job scheduled (if it was not already).');
        }

        $generator = ProductFeedGenerator::create();
        $channelKey = (string) ($input->getOption('channel') ?? '');

        if ($channelKey !== '') {
            if (!ChannelFeedRegistry::create()->has($channelKey)) {
                $output->writeln(sprintf('Unknown channel "%s". Registered: %s', $channelKey, $this->registeredList()));
                return Command::FAILURE;
            }
            $result = $generator->generateChannel($channelKey);
            if ($result === null) {
                $output->writeln(sprintf('Channel "%s" could not be generated.', $channelKey));
                return Command::FAILURE;
            }
            $output->writeln(sprintf('Generated "%s": %d item(s) → %s', $channelKey, (int) $result['items'], (string) $result['path']));
            return Command::SUCCESS;
        }

        $results = $generator->generateAll();
        if ($results === []) {
            $output->writeln('No channels enabled. Enable one in ChannelFeedRegistry.enabled_channels, or pass --channel=<key>.');
            return Command::SUCCESS;
        }
        foreach ($results as $key => $result) {
            $output->writeln(sprintf('Generated "%s": %d item(s) → %s', $key, (int) $result['items'], (string) $result['path']));
        }

        return Command::SUCCESS;
    }

    private function registeredList(): string
    {
        $keys = array_keys(ChannelFeedRegistry::create()->all());

        return $keys === [] ? '(none)' : implode(', ', $keys);
    }
}
