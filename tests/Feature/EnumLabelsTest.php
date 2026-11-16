<?php

namespace Tests\Feature;

use App\Enums\Concerns\HasLabel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Every case of a HasLabel enum has a label in lang/ru/enums.php: without it
 * trans() silently returns the key and the admin shows "enums.X.Y".
 */
class EnumLabelsTest extends TestCase
{
    public function test_every_enum_case_has_a_label(): void
    {
        $enums = collect(File::files(app_path('Enums')))
            ->map(fn ($file) => 'App\\Enums\\'.$file->getFilenameWithoutExtension())
            ->filter(fn (string $class) => enum_exists($class) && in_array(HasLabel::class, class_uses($class), true));

        $this->assertNotEmpty($enums);

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $key = 'enums.'.class_basename($enum).'.'.$case->name;
                $this->assertTrue(Lang::has($key, 'ru'), "Нет перевода {$key} в lang/ru/enums.php");
            }
        }
    }
}
