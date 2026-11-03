<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\VideoSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = VideoSource::withCount('videos')->latest()->get();

        return response()->json([
            'status' => 'success',
            'resources' => $sources,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:instagram,facebook,youtube,tiktok'],
            'source_identifier' => ['required', 'string'],
            'source_url' => ['required', 'url'],
            'name' => ['required', 'string', 'max:255'],
            'scan_frequency' => ['nullable', 'integer', 'min:15', 'max:1440'],
        ]);

        $source = VideoSource::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Video source added successfully.',
            'resources' => $source,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $source = VideoSource::findOrFail($id);
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,paused'],
            'scan_frequency' => ['nullable', 'integer', 'min:15', 'max:1440'],
        ]);

        $source->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Video source updated.',
            'resources' => $source,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $source = VideoSource::findOrFail($id);
        $source->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Video source deleted.',
        ]);
    }
}
