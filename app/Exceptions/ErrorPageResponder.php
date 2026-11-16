<?php

namespace App\Exceptions;

use App\Support\Site\SiteRequest;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Inertia\ResponseFactory as InertiaResponseFactory;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders every error page. Hooked up in bootstrap/app.php via
 * $exceptions->respond(); the container builds it per error, so app.debug is
 * always read fresh.
 *
 * There is no public site: an unknown address outside the admin panel (a page
 * request, not JSON) redirects to /admin, even with APP_DEBUG=true. When
 * debugging is off (as in production) a styled Inertia Error page replaces the
 * default Symfony screen; in local/dev (APP_DEBUG=true) nothing is substituted —
 * a real stack trace is needed. Pure JSON responses (search, polling) are left
 * as is: an Inertia page instead of JSON would break the client. Inertia XHR
 * (X-Inertia) is handled here — the client switches the page itself.
 */
class ErrorPageResponder
{
    /** Statuses that have a dedicated Error page in the admin panel. */
    private const ADMIN_PAGES = [403, 404, 419, 429, 500, 503];

    public function __construct(
        #[Config('app.debug')] private readonly bool $debug,
        private readonly Redirector $redirect,
        private readonly InertiaResponseFactory $inertia,
    ) {}

    public function __invoke(Response $response, Throwable $e, Request $request): Response
    {
        $status = $response->getStatusCode();
        $json = $request->expectsJson() && ! $request->header('X-Inertia');

        // No public site: an unknown page outside the admin panel leads to it.
        if ($status === 404 && SiteRequest::isPage($request)) {
            return $this->redirect->to('/admin');
        }

        // An expired CSRF token (the page sat open past the session lifetime):
        // return to the page with a hint; the reload brings a fresh token.
        if ($status === 419 && ! $json) {
            return $this->redirect->back()->with('warning', 'Страница устарела, повторите действие');
        }

        if ($this->debug || $json) {
            return $response;
        }

        if (in_array($status, self::ADMIN_PAGES, true)) {
            // Maintenance mode (503) stops the request before the web group,
            // so HandleInertiaRequests never sets the root view.
            $this->inertia->setRootView('admin');

            return $this->inertia->render('Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        }

        return $response;
    }
}
