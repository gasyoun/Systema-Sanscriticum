<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\BuildLectureHtmlJob;
use App\Jobs\GenerateCertificatesArchive;
use App\Jobs\PreprocessLectureDraftJob;
use App\Jobs\PublishSocialPostJob;
use App\Jobs\RunLectureAiJob;
use App\Jobs\SendContentOneShotMailJob;
use App\Jobs\SendLeadMagnet;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_every_horizon_timeout_is_below_its_redis_retry_after(): void
    {
        $this->assertSame(150, config('queue.connections.redis.retry_after'));
        $this->assertSame(660, config('queue.connections.redis-long.retry_after'));
        $this->assertSame(1020, config('queue.connections.redis-lectures.retry_after'));

        foreach (['defaults', 'environments.production', 'environments.local'] as $path) {
            foreach (config("horizon.{$path}") as $name => $supervisor) {
                $retryAfter = config("queue.connections.{$supervisor['connection']}.retry_after");

                $this->assertIsInt($retryAfter, "{$path}.{$name} must use a queue connection with retry_after");
                $this->assertLessThan(
                    $retryAfter,
                    $supervisor['timeout'],
                    "{$path}.{$name} timeout must be strictly below retry_after"
                );
            }
        }
    }

    public function test_each_existing_queue_has_exactly_one_supervisor_in_every_environment(): void
    {
        $expectedQueues = [
            'certificates',
            'default',
            'imports',
            'lectures',
            'mailing',
            'messages',
            'tracking',
            'webhooks',
        ];

        foreach (['production', 'local'] as $environment) {
            $queues = collect(config("horizon.environments.{$environment}"))
                ->flatMap(fn (array $supervisor): array => $supervisor['queue'])
                ->sort()
                ->values()
                ->all();

            $this->assertSame($expectedQueues, $queues, "{$environment} must not strand or double-consume a queue");
        }
    }

    public function test_jobs_use_the_queue_connection_matching_their_runtime_class(): void
    {
        config(['queue.default' => 'redis']);

        $archive = new GenerateCertificatesArchive(1, 2, operationId: 'a165018d-d5e0-43f9-8cd2-06a832adb8d4');
        $this->assertSame('redis-long', $archive->connection);
        $this->assertSame('certificates', $archive->queue);
        $this->assertLessThan(config('queue.connections.redis-long.retry_after'), $archive->timeout);

        $lectureJobs = [
            new BuildLectureHtmlJob(1),
            new PreprocessLectureDraftJob(1, 'transcript.txt', null, 1),
            new RunLectureAiJob(1, 'correct'),
        ];

        foreach ($lectureJobs as $job) {
            $this->assertSame('redis-lectures', $job->connection);
            $this->assertSame('lectures', $job->queue);
            $this->assertLessThan(config('queue.connections.redis-lectures.retry_after'), $job->timeout);
        }

        $mail = new SendContentOneShotMailJob(10);
        $this->assertSame('redis-long', $mail->connection);
        $this->assertSame('mailing', $mail->queue);
        $this->assertInstanceOf(ShouldBeUnique::class, $mail);

        $social = new PublishSocialPostJob(11);
        $this->assertSame('redis-long', $social->connection);
        $this->assertSame('messages', $social->queue);
        $this->assertInstanceOf(ShouldBeUnique::class, $social);

        $magnet = new SendLeadMagnet(12);
        $this->assertNull($magnet->connection);
        $this->assertSame('webhooks', $magnet->queue);
    }
}
