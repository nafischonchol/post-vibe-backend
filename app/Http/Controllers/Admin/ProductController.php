<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Product\CreateProductAction;
use App\Actions\Product\GetProductEditPayloadAction;
use App\Actions\Product\UpdateProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\Admin\ProductService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(public ProductService $productService) {}

    public function index(Request $request)
    {
        return $this->productService->index($request);
    }

    public function store(StoreProductRequest $request, CreateProductAction $action)
    {
        try {
            $action->execute($request);

            return responseSuccess(null, 'Product created successfully', 201);
        } catch (\Throwable $th) {
            return responseError('Failed to create product: ', 500, $th);
        }
    }

    public function editPayload(Product $product, GetProductEditPayloadAction $action)
    {
        try {
            return $action->execute($product);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch product edit payload: '.$th->getMessage(), 500);
        }
    }

    public function show(Product $product)
    {
        try {
            return $this->productService->show($product);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch product details: '.$th->getMessage(), 500);
        }
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductAction $action)
    {
        try {
            $action->execute($product, $request);

            return responseSuccess(null, 'Product updated successfully');
        } catch (\Throwable $th) {
            $code = $th->getCode();
            $statusCode = in_array($code, [400, 401, 403, 404, 422]) ? $code : 500;

            return responseError('Failed to update product: '.$th->getMessage(), $statusCode);
        }
    }

    public function stockMovements(Product $product)
    {
        return $this->productService->stockMovements($product);
    }

    public function checkByBarcode(Request $request)
    {
        try {
            $barcode = $request->query('barcode');
            if (! $barcode) {
                return responseError('Barcode is required.', 400);
            }

            return $this->productService->checkByBarcode($barcode);
        } catch (\Throwable $th) {
            return responseError('Failed to check product by barcode: '.$th->getMessage(), 500, $th);
        }
    }
}
