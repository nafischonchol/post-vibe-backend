<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = SystemSetting::all()->pluck('value', 'key');

        return response()->json([
            'status' => 'success',
            'resources' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $adminId = $request->user()?->id;

        foreach ($validated['settings'] as $key => $value) {
            SystemSetting::set($key, $value, 'general', $adminId);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'System settings updated successfully.',
            'resources' => SystemSetting::all()->pluck('value', 'key'),
        ]);
    }
}
