<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Deletes one user: to the trash, or permanently with force=1 ("Delete
 * permanently" in the delete dialog). The action depends on this flag, so the
 * record-level rule (UserPolicy::delete / forceDelete) is checked here rather
 * than on the route.
 */
class DestroyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && $this->user()?->can('users.delete') === true
            && $this->user()->can($this->force() ? 'forceDelete' : 'delete', $target);
    }

    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function force(): bool
    {
        return $this->boolean('force');
    }
}
