<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\LocationService;

class LocationController extends Controller
{
    public function __construct(public LocationService $locationService) {}

    public function divisions()
    {
        return $this->locationService->getDivisions();
    }

    public function districts(int $divisionId)
    {
        return $this->locationService->getDistricts($divisionId);
    }

    public function upazilas(int $districtId)
    {
        return $this->locationService->getUpazilas($districtId);
    }

    public function areas(int $upazilaId)
    {
        return $this->locationService->getAreas($upazilaId);
    }
}
