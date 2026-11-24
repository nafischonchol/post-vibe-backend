<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformOAuthService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OAuthController extends Controller
{
    public function __construct(
        protected PlatformOAuthService $oauthService
    ) {}

    /**
     * Generate OAuth Redirect URL for the authenticated tenant.
     */
    public function redirect(Request $request, string $platform): JsonResponse
    {
        $tenant = $request->user()->tenant ?? $request->attributes->get('tenant');
        $tenantId = $tenant?->id ?? $request->user()->tenant_id;

        if (! $tenantId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Active tenant context required.',
            ], 403);
        }

        try {
            $redirectUrl = $this->oauthService->getAuthorizationUrl($tenantId, $platform);

            return response()->json([
                'status' => 'success',
                'authorization_url' => $redirectUrl,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle Public OAuth callback from provider.
     */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        $frontendUrl = config('app.frontend_url', 'https://postvibe.mohima.skin');

        if ($error || empty($code) || empty($state)) {
            $errorDesc = $request->query('error_description') ?: $error ?: 'Missing OAuth authorization parameters.';
            return redirect("{$frontendUrl}/tenant/platform-accounts?error=" . urlencode($errorDesc));
        }

        try {
            $result = $this->oauthService->handleCallback($platform, $code, $state);
            $account = $result['account'];

            return redirect("{$frontendUrl}/tenant/platform-accounts?connected=" . urlencode($platform) . "&account=" . urlencode($account->account_name));
        } catch (Exception $e) {
            return redirect("{$frontendUrl}/tenant/platform-accounts?error=" . urlencode($e->getMessage()));
        }
    }
}
