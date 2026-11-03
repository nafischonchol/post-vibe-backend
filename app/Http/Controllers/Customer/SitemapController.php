<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\SitemapService;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    public function __construct(public SitemapService $sitemap_service) {}

    public function index()
    {
        try {
            return $this->sitemap_service->index();
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function products(Request $request)
    {
        try {
            return $this->sitemap_service->products($request);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function categories()
    {
        try {
            return $this->sitemap_service->categories();
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function brands()
    {
        try {
            return $this->sitemap_service->brands();
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }
}
