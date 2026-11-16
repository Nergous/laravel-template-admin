<?php

namespace App\Services\System;

use App\Models\User;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Admin page links that respect the viewer's permissions. The shared rule of
 * the global search and the activity log: an entity the viewer may edit links
 * to its edit page, otherwise to the list filtered by its name.
 */
class AdminLinks
{
    public function __construct(private readonly UrlGenerator $url) {}

    /**
     * The edit page with the {section}.edit permission, otherwise the list
     * admin.{section}.index searched by $search. The caller checks the
     * {section}.view permission.
     */
    public function editOrList(User $viewer, string $section, int $id, ?string $search): string
    {
        return $viewer->can("{$section}.edit")
            ? $this->url->route("admin.{$section}.edit", $id)
            : $this->url->route("admin.{$section}.index", ['search' => $search]);
    }

    /** URL of a named route. */
    public function route(string $name, mixed $parameters = []): string
    {
        return $this->url->route($name, $parameters);
    }
}
