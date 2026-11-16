<?php

namespace App\View\Composers;

use App\Services\Site\SeoMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Meta tags of the public layout (public.blade.php). A page overrides the
 * title, description and image with sections; the layout renders after the
 * page, so its sections are already set here.
 *
 * @section('name', $value) escapes the value and the meta tags escape it again
 * on output, so the sections are decoded back to text first: every value ends
 * up escaped exactly once.
 */
class SeoMetaComposer
{
    public function __construct(
        private readonly SeoMeta $seo,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $section = fn (string $name): string => html_entity_decode(
            trim($view->getFactory()->yieldContent($name)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $view->with('seo', $this->seo->for(
            $this->request->getSchemeAndHttpHost(),
            $this->request->path(),
            $this->request->query('page'),
            $section('title'),
            $section('description'),
            $section('og_image'),
        ));
    }
}
