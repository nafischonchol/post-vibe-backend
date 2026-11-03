<?php

namespace App\Http\Middleware;

use App\Services\Customer\VisitorService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class HandleVisitorSession
{
    public function __construct(
        protected VisitorService $visitorService
    ) {}

    /**
     * Handle an incoming request.
     * Zero DB queries during request cycle for maximum performance.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $visitorId = $request->header('X-Visitor-Id');

        if (! $visitorId) {
            $visitorId = (string) Str::ulid();
            $request->headers->set('X-Visitor-Id', $visitorId);
            $request->attributes->set('is_new_visitor_session', true);
        } else {
            $request->attributes->set('is_new_visitor_session', false);
        }

        /** @var Response $response */
        $response = $next($request);

        // Pass X-Visitor-Id in response header
        $response->headers->set('X-Visitor-Id', $visitorId);

        return $response;
    }

    /**
     * Perform any final tasks after the response has been sent to the browser.
     * All DB operations occur post-response asynchronously.
     */
    public function terminate(Request $request, Response $response): void
    {
        $visitorId = $request->header('X-Visitor-Id');
        if (! $visitorId) {
            return;
        }

        try {
            $isNew = $request->attributes->get('is_new_visitor_session', false);

            if ($isNew) {
                // New visitor session -> insert into DB post-response
                $this->visitorService->recordSession($request);
            } else {
                // Existing visitor session -> touch last_active_at if > 5 minutes ago
                $this->visitorService->touchSessionLastActive($visitorId);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
