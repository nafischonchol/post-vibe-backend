<?php

namespace App\Services\Admin;

use App\Http\Resources\AreaResource;
use App\Http\Resources\DistrictResource;
use App\Http\Resources\DivisionResource;
use App\Http\Resources\UpazilaResource;
use App\Models\Area;
use App\Models\District;
use App\Models\Division;
use App\Models\Upazila;

class LocationService
{
    public function getDivisions()
    {
        $divisions = Division::orderBy('name')->get();

        return responseSuccess(DivisionResource::collection($divisions));
    }

    public function getDistricts(int $divisionId)
    {
        $districts = District::where('division_id', $divisionId)
            ->orderBy('name')
            ->get();

        return responseSuccess(DistrictResource::collection($districts));
    }

    public function getUpazilas(int $districtId)
    {
        $upazilas = Upazila::where('district_id', $districtId)
            ->orderBy('name')
            ->get();

        return responseSuccess(UpazilaResource::collection($upazilas));
    }

    public function getAreas(int $upazilaId)
    {
        $areas = Area::where('upazila_id', $upazilaId)
            ->orderBy('name')
            ->get();

        return responseSuccess(AreaResource::collection($areas));
    }
}
