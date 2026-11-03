<?php

namespace App\Services\Admin;

use App\Http\Resources\UnitResource;
use App\Models\Unit;

class UnitService
{
    public function index()
    {
        $units = Unit::latest()->get();

        return responseSuccess(UnitResource::collection($units));
    }

    public function show(string $id)
    {
        $unit = Unit::findOrFail($id);

        return responseSuccess(UnitResource::make($unit));
    }

    public function store($request)
    {
        try {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);

            $unit = Unit::create($data);

            return responseSuccess(UnitResource::make($unit), 'Unit created successfully');
        } catch (\Exception $e) {
            return responseError('Failed to create unit: '.$e->getMessage(), 500);
        }
    }

    public function update(Unit $unit, $request)
    {
        try {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);

            $unit->update($data);

            return responseSuccess(UnitResource::make($unit), 'Unit updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update unit: '.$e->getMessage(), 500);
        }
    }
}
