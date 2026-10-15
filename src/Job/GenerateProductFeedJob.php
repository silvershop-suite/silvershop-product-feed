<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Job;

use SilverShop\ProductFeed\Service\ProductFeedGenerator;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Recurring feed regeneration: writes every enabled channel's static file, then re-queues itself
 * {@code regenerate_interval_mins} later (one run scheduled at a time). This is the scheduling answer — the job
 * owns feed freshness so serving stays a zero-cost static-file read.
 *
 * Mirrors {@code silvershop/abandoned-cart}'s {@code ScanAbandonedCartsJob}. Arm it with
 * {@code GenerateProductFeedTask --schedule} (it stays dormant until then).
 */
class GenerateProductFeedJob extends AbstractQueuedJob
{
    use Configurable;

    /**
     * Minutes between regenerations (the job re-queues itself this far ahead). Default: nightly.
     */
    private static int $regenerate_interval_mins = 1440;

    public function getTitle(): string
    {
        return _t(self::class . '.TITLE', 'Regenerate product feeds');
    }

    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        $this->currentStep++;

        $results = ProductFeedGenerator::create()->generateAll();

        if ($results === []) {
            $this->addMessage('No channels enabled — nothing to generate.');
        } else {
            foreach ($results as $key => $result) {
                $this->addMessage(sprintf('Generated "%s": %d item(s) → %s', $key, (int) $result['items'], (string) $result['path']));
            }
        }

        $this->isComplete = true;

        // Re-arm the recurring regeneration.
        self::scheduleNext();
    }

    /**
     * Schedule the next regeneration {@code regenerate_interval_mins} from now, unless one is already queued/running.
     */
    public static function scheduleNext(): void
    {
        if (self::isScheduled()) {
            return;
        }

        $mins = max(1, (int) static::config()->get('regenerate_interval_mins'));
        $startAfter = date('Y-m-d H:i:s', strtotime('+' . $mins . ' minutes', strtotime(DBDatetime::now()->getValue())));

        QueuedJobService::singleton()->queueJob(new GenerateProductFeedJob(), $startAfter);
    }

    /**
     * Whether a regeneration job is already queued or running (so duplicates never pile up).
     */
    public static function isScheduled(): bool
    {
        return QueuedJobDescriptor::get()
            ->filter([
                'Implementation' => self::class,
                'JobStatus' => [
                    QueuedJob::STATUS_NEW,
                    QueuedJob::STATUS_INIT,
                    QueuedJob::STATUS_RUN,
                    QueuedJob::STATUS_WAIT,
                    QueuedJob::STATUS_PAUSED,
                ],
            ])
            ->exists();
    }
}
