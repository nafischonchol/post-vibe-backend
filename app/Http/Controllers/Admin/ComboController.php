<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Combo\StoreComboRequest;
use App\Http\Requests\Admin\Combo\UpdateComboRequest;
use App\Models\Combo;
use App\Services\Admin\ComboService;
use Illuminate\Http\Request;

class ComboController extends Controller
{
    public function __construct(
        protected ComboService $comboService
    ) {}

    public function index(Request $request)
    {
        try {
            return $this->comboService->index($request);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch combos: '.$th->getMessage(), 500, $th);
        }
    }

    public function store(StoreComboRequest $request)
    {
        try {
            $combo = $this->comboService->store($request);

            return responseSuccess($combo, 'Combo created successfully', 201);
        } catch (\Throwable $th) {
            return responseError('Failed to create combo: '.$th->getMessage(), 500, $th);
        }
    }

    public function show(Combo $combo)
    {
        try {
            return $this->comboService->show($combo);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch combo details: '.$th->getMessage(), 500, $th);
        }
    }

    public function editPayload(Combo $combo)
    {
        try {
            return $this->comboService->editPayload($combo);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch combo edit payload: '.$th->getMessage(), 500, $th);
        }
    }

    public function update(UpdateComboRequest $request, Combo $combo)
    {
        try {
            $updated = $this->comboService->update($request, $combo);

            return responseSuccess($updated, 'Combo updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update combo: '.$th->getMessage(), 500, $th);
        }
    }

    public function toggleStatus(Combo $combo)
    {
        try {
            return $this->comboService->toggleStatus($combo);
        } catch (\Throwable $th) {
            return responseError('Failed to update combo status: '.$th->getMessage(), 500, $th);
        }
    }

    public function destroy(Combo $combo)
    {
        try {
            return $this->comboService->destroy($combo);
        } catch (\Throwable $th) {
            return responseError('Failed to delete combo: '.$th->getMessage(), 500, $th);
        }
    }
}
