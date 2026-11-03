<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformApplicationController extends Controller
{
    public function index(): JsonResponse
    {
        $apps = PlatformApplication::all()->map(function ($app) {
            return [
                'id' => $app->id,
                'platform' => $app->platform,
                'client_id' => $app->client_id,
                'redirect_uri' => $app->redirect_uri,
                'configuration' => $app->configuration,
                'status' => $app->status,
                'is_configured' => ! empty($app->client_id),
                'updated_at' => $app->updated_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'resources' => $apps,
        ]);
    }

    public function update(Request $request, string $platform): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string'],
            'client_secret' => ['nullable', 'string'],
            'redirect_uri' => ['required', 'url'],
            'configuration' => ['nullable', 'array'],
        ]);

        $app = PlatformApplication::firstOrNew(['platform' => $platform]);
        $app->client_id = $validated['client_id'];
        if (! empty($validated['client_secret'])) {
            $app->encrypted_client_secret = $validated['client_secret'];
        }
        $app->redirect_uri = $validated['redirect_uri'];
        $app->configuration = $validated['configuration'] ?? $app->configuration;
        $app->status = 'configured';
        $app->save();

        return response()->json([
            'status' => 'success',
            'message' => "Platform application for {$platform} configured successfully.",
            'resources' => [
                'id' => $app->id,
                'platform' => $app->platform,
                'client_id' => $app->client_id,
                'redirect_uri' => $app->redirect_uri,
                'status' => $app->status,
            ],
        ]);
    }
}
