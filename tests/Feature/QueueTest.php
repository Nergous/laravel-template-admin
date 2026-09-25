<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Services\QueueStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_summary_without_database_queue_reports_pending_as_unavailable(): void
    {
        config(['queue.default' => 'sync']);
        $this->failedJob();

        $this->assertSame(
            ['pending' => null, 'oldest_pending_at' => null, 'failed' => 1],
            app(QueueStats::class)->summary(),
        );
    }

    public function test_summary_with_database_queue_counts_waiting_jobs(): void
    {
        config(['queue.default' => 'database']);
        $this->queuedJob(availableAt: now()->subMinutes(5));
        $this->queuedJob(availableAt: now()->subMinutes(2));
        $this->queuedJob(availableAt: now()->addHour()); // delayed: waiting, but not overdue
        $this->queuedJob(availableAt: now()->subHour(), reserved: true); // a worker holds it

        $this->assertSame(
            ['pending' => 3, 'oldest_pending_at' => '2026-09-24T11:55:00+00:00', 'failed' => 0],
            app(QueueStats::class)->summary(),
        );
    }

    public function test_health_check_fails_on_a_stale_backlog(): void
    {
        config(['queue.default' => 'database']);

        $this->queuedJob(availableAt: now()->subMinutes(5));
        $this->get('/up')->assertOk();

        $this->queuedJob(availableAt: now()->subMinutes(20));
        $this->get('/up')->assertStatus(500);
    }

    public function test_index_requires_queue_view(): void
    {
        $this->actingAsUserWith([]);
        $this->get('/admin/queue')->assertForbidden();
    }

    public function test_index_lists_failed_jobs(): void
    {
        $uuid = $this->failedJob();
        $this->actingAsUserWith(['queue.view']);

        $this->get('/admin/queue')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Queue/Index')
                ->where('summary.failed', 1)
                ->where('failedJobs.total', 1)
                ->where('failedJobs.data.0.uuid', $uuid)
                ->where('failedJobs.data.0.name', 'App\\Jobs\\UploadMedia')
                ->where('failedJobs.data.0.queue', 'default')
                ->where('failedJobs.data.0.exception', 'RuntimeException: boom')
                ->where('failedJobs.data.0.failed_at', '2026-09-24T11:00:00+00:00')
            );
    }

    public function test_managing_failed_jobs_requires_queue_manage(): void
    {
        $uuid = $this->failedJob();
        $this->actingAsUserWith(['queue.view']);

        $this->post("/admin/queue/failed/{$uuid}/retry")->assertForbidden();
        $this->delete("/admin/queue/failed/{$uuid}")->assertForbidden();
        $this->post('/admin/queue/failed/retry-all')->assertForbidden();
        $this->delete('/admin/queue/failed')->assertForbidden();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_retry_pushes_the_job_back_and_logs_it(): void
    {
        $uuid = $this->failedJob();
        $this->actingAsUserWith(['queue.view', 'queue.manage']);

        $this->post("/admin/queue/failed/{$uuid}/retry")->assertRedirect()->assertSessionHas('success');

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('App\\Jobs\\UploadMedia', ActivityLog::where('action', 'job_retried')->value('subject_label'));
    }

    public function test_destroy_forgets_the_job_and_logs_it(): void
    {
        $uuid = $this->failedJob();
        $other = $this->failedJob();
        $this->actingAsUserWith(['queue.view', 'queue.manage']);

        $this->delete("/admin/queue/failed/{$uuid}")->assertRedirect()->assertSessionHas('success');

        $this->assertSame([$other], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('App\\Jobs\\UploadMedia', ActivityLog::where('action', 'job_deleted')->value('subject_label'));
    }

    public function test_unknown_uuid_returns_not_found(): void
    {
        $this->actingAsUserWith(['queue.view', 'queue.manage']);
        $uuid = (string) Str::uuid();

        $this->post("/admin/queue/failed/{$uuid}/retry")->assertNotFound();
        $this->delete("/admin/queue/failed/{$uuid}")->assertNotFound();
        $this->assertSame(0, ActivityLog::whereIn('action', ['job_retried', 'job_deleted'])->count());
    }

    public function test_retry_all_and_flush_handle_every_failed_job(): void
    {
        $this->actingAsUserWith(['queue.view', 'queue.manage']);

        $this->failedJob();
        $this->failedJob();
        $this->post('/admin/queue/failed/retry-all')->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame('Все упавшие задачи (2)', ActivityLog::where('action', 'job_retried')->value('subject_label'));

        $this->failedJob();
        $this->delete('/admin/queue/failed')->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame('Все упавшие задачи (1)', ActivityLog::where('action', 'job_deleted')->value('subject_label'));

        // Nothing left: no-op without a log entry.
        $this->delete('/admin/queue/failed')->assertRedirect()->assertSessionHas('info');
        $this->assertSame(1, ActivityLog::where('action', 'job_deleted')->count());
    }

    private function failedJob(): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\UploadMedia',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'attempts' => 3,
            ]),
            'exception' => "RuntimeException: boom\n#0 /app/Jobs/UploadMedia.php(10): handle()",
            'failed_at' => '2026-09-24 11:00:00',
        ]);

        return $uuid;
    }

    private function queuedJob(Carbon $availableAt, bool $reserved = false): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => $reserved ? 1 : 0,
            'reserved_at' => $reserved ? now()->getTimestamp() : null,
            'available_at' => $availableAt->getTimestamp(),
            'created_at' => $availableAt->getTimestamp(),
        ]);
    }
}
