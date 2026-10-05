<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A media library row. Only the row: tests that need the file put it on the
 * faked media disk themselves (Storage::fake(Media::diskName())).
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::random(12).'.jpg';

        return [
            'filename' => 'media/'.$name,
            'original_name' => $name,
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => fake()->numberBetween(1_000, 500_000),
            'has_thumb' => false,
        ];
    }

    /** A PDF document. */
    public function document(): static
    {
        return $this->state(function () {
            $name = Str::random(12).'.pdf';

            return [
                'filename' => 'media/'.$name,
                'original_name' => $name,
                'mime_type' => 'application/pdf',
                'type' => 'document',
            ];
        });
    }
}
