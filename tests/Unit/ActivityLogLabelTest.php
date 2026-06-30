<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\User;
use Tests\TestCase;

class ActivityLogLabelTest extends TestCase
{
    public function test_subject_type_label_reads_from_config_registry(): void
    {
        $log = new ActivityLog(['subject_type' => User::class]);

        $this->assertSame(__('activity.subjects.user'), $log->subjectTypeLabel());
    }

    public function test_newly_registered_subject_uses_its_lang_key(): void
    {
        config(['audit.subjects' => ['App\\Models\\Widget' => 'widget']]);
        app('translator')->addLines(['activity.subjects.widget' => 'Виджет'], 'ru');

        $log = new ActivityLog(['subject_type' => 'App\\Models\\Widget']);

        $this->assertSame('Виджет', $log->subjectTypeLabel());
    }

    public function test_unregistered_subject_type_falls_back_to_class_basename(): void
    {
        $log = new ActivityLog(['subject_type' => 'App\\Models\\Unknown']);

        $this->assertSame('Unknown', $log->subjectTypeLabel());
    }
}
