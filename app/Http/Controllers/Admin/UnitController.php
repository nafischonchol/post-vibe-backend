<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Unit\StoreUnitRequest;
use App\Http\Requests\Admin\Unit\UpdateUnitRequest;
use App\Models\Unit;
use App\Services\Admin\UnitService;

class UnitController extends Controller
{
    public function __construct(public UnitService $unitService) {}

    public function index()
    {
        return $this->unitService->index();
    }

    public function store(StoreUnitRequest $request)
    {
        return $this->unitService->store($request);
    }

    public function show(Unit $unit)
    {
        return $this->unitService->show($unit->id);
    }

    public function update(UpdateUnitRequest $request, Unit $unit)
    {
        return $this->unitService->update($unit, $request);
    }
}
