<?php

namespace App\Providers;

use App\Services\Site\Sitemap;
use App\Support\Html\HtmlSanitizer;
use App\View\Composers\SeoMetaComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Public side: the SEO composer of the public layout, /sitemap.xml sources and
 * Blade directives that print sanitized HTML.
 */
class SiteServiceProvider extends ServiceProvider
{
    /** Directive => HtmlSanitizer method the value is printed through. */
    private const HTML_DIRECTIVES = [
        'html' => 'clean',
        'htmlText' => 'cleanText',
        'htmlWithoutLinks' => 'cleanWithoutLinks',
        'htmlInline' => 'cleanInline',
    ];

    /**
     * Sitemap sources are tagged and resolved only when the sitemap is built.
     * None are registered by default: a project adds its own by tagging them
     * with Sitemap::SOURCES, e.g. $this->app->tag([PagesSource::class], Sitemap::SOURCES).
     */
    public function register(): void
    {
        $this->app->when(Sitemap::class)->needs('$sources')->giveTagged(Sitemap::SOURCES);
    }

    public function boot(): void
    {
        View::composer('public', SeoMetaComposer::class);

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade) {
            foreach (self::HTML_DIRECTIVES as $directive => $method) {
                $blade->directive($directive, fn (string $expression) => '<?php echo app(\\'.HtmlSanitizer::class.'::class)->'.$method.'('.$expression.'); ?>');
            }
        });
    }
}
