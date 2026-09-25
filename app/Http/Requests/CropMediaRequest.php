<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crop area of an image as fractions of its width and height (0..1), so the
 * client can work with the scaled preview instead of the real pixel size.
 */
class CropMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    public function rules(): array
    {
        return [
            'x' => ['required', 'numeric', 'min:0', 'max:1'],
            'y' => ['required', 'numeric', 'min:0', 'max:1'],
            'width' => ['required', 'numeric', 'gt:0', 'max:1', $this->withinImage('x')],
            'height' => ['required', 'numeric', 'gt:0', 'max:1', $this->withinImage('y')],
        ];
    }

    /** The area must end inside the image (with a small rounding tolerance). */
    private function withinImage(string $offset): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($offset): void {
            if ((float) $this->input($offset) + (float) $value > 1.001) {
                $fail('Область обрезки выходит за границы изображения');
            }
        };
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    public function area(): array
    {
        return array_map('floatval', $this->safe()->only(['x', 'y', 'width', 'height']));
    }
}
