<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The model factories produce rows the schema and model events accept. */
class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_and_user_factories_create_valid_rows(): void
    {
        $media = Media::factory()->create();
        $document = Media::factory()->document()->create();
        $user = User::factory()->create();

        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->assertDatabaseHas('media', ['id' => $document->id, 'type' => 'document']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
    }
}
