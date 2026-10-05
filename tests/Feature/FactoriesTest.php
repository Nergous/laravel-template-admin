<?php

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The model factories produce rows the schema and model events accept. */
class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_factory_creates_valid_rows(): void
    {
        $image = Media::factory()->create();
        $document = Media::factory()->document()->create();

        $this->assertDatabaseHas('media', ['id' => $image->id, 'type' => 'image']);
        $this->assertDatabaseHas('media', ['id' => $document->id, 'type' => 'document']);
    }
}
