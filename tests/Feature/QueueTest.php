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

    public function test_liveness_check_ignores_the_backlog_and_full_check_reports_it(): void
    {
        config(['queue.default' => 'database']);

        $this->queuedJob(availableAt: now()->subMinutes(5));
        $this->get('/up')->assertOk();
        $this->get('/up?full=1')->assertOk();

        $this->queuedJob(availableAt: now()->subMinutes(20));
        // A long backlog must not mark the web container unhealthy: the worker
        // and scheduler containers wait for /up before they start.
        $this->get('/up')->assertOk();
        $this->get('/up?full=1')->assertStatus(500);
    }

    public function test_index_flags_a_stalled_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->actingAsUserWith(['queue.view']);

        $this->queuedJob(availableAt: now()->subMinutes(5));
        $this->get('/admin/queue')->assertInertia(fn (Assert $page) => $page->where('stalled', false));

        $this->queuedJob(availableAt: now()->subMinutes(20));
        $this->get('/admin/queue')->assertInertia(fn (Assert $page) => $page
            ->where('stalled', true)
            ->where('stalledAfterMinutes', QueueStats::STALLED_AFTER_MINUTES));
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
                ->where('counts', ['running' => null, 'pending' => null, 'delayed' => null, 'failed' => 1])
                ->where('jobs.total', 1)
                ->where('jobs.data.0.uuid', $uuid)
                ->where('jobs.data.0.status', 'failed')
                ->where('jobs.data.0.name', 'App\\Jobs\\UploadMedia')
                ->where('jobs.data.0.queue', 'default')
                ->where('jobs.data.0.exception', 'RuntimeException: boom')
                ->where('jobs.data.0.failed_at', '2026-09-24T11:00:00+00:00')
            );
    }

    public function test_index_lists_queued_jobs_with_their_status(): void
    {
        config(['queue.default' => 'database']);
        $this->queuedJob(availableAt: now()->subHour(), reserved: true, name: 'App\\Jobs\\CreateBackup');
        $this->queuedJob(availableAt: now()->subMinutes(5));
        $this->queuedJob(availableAt: now()->addHour());
        $failed = $this->failedJob();
        $this->actingAsUserWith(['queue.view']);

        $this->get('/admin/queue')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', null)
                ->where('counts', ['running' => 1, 'pending' => 1, 'delayed' => 1, 'failed' => 1])
                ->where('jobs.total', 4)
                ->where('jobs.data.0.status', 'running')
                ->where('jobs.data.0.name', 'App\\Jobs\\CreateBackup')
                ->where('jobs.data.0.attempts', 1)
                ->where('jobs.data.0.uuid', null)
                ->where('jobs.data.0.reserved_at', '2026-09-24T12:00:00+00:00')
                ->where('jobs.data.1.status', 'pending')
                ->where('jobs.data.1.available_at', '2026-09-24T11:55:00+00:00')
                ->where('jobs.data.2.status', 'delayed')
                ->where('jobs.data.3.status', 'failed')
                ->where('jobs.data.3.uuid', $failed)
            );

        $this->get('/admin/queue?status=delayed')
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', 'delayed')
                ->where('jobs.total', 1)
                ->where('jobs.data.0.status', 'delayed')
                ->where('jobs.data.0.available_at', '2026-09-24T13:00:00+00:00')
            );

        $this->get('/admin/queue?status=failed')
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.total', 1)
                ->where('jobs.data.0.uuid', $failed)
            );

        // Unknown status falls back to the full list.
        $this->get('/admin/queue?status=bogus')
            ->assertInertia(fn (Assert $page) => $page
                ->where('status', null)
                ->where('jobs.total', 4)
            );
    }

    public function test_pages_continue_from_queued_into_failed_jobs(): void
    {
        config(['queue.default' => 'database']);
        foreach (range(1, 15) as $minutes) {
            $this->queuedJob(availableAt: now()->subMinutes($minutes));
        }
        foreach (range(1, 10) as $ignored) {
            $this->failedJob();
        }
        $this->actingAsUserWith(['queue.view']);

        $this->get('/admin/queue?page=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.total', 25)
                ->where('jobs.last_page', 2)
                ->has('jobs.data', 20)
                ->where('jobs.data.14.status', 'pending')
                ->where('jobs.data.15.status', 'failed')
            );

        $this->get('/admin/queue?page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 5)
                ->where('jobs.data.0.status', 'failed')
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

    private function queuedJob(Carbon $availableAt, bool $reserved = false, string $name = 'App\\Jobs\\UploadMedia'): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $name]),
            'attempts' => $reserved ? 1 : 0,
            'reserved_at' => $reserved ? now()->getTimestamp() : null,
            'available_at' => $availableAt->getTimestamp(),
            'created_at' => $availableAt->getTimestamp(),
        ]);
    }
}
