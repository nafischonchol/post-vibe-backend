<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Attribute\StoreAttributeRequest;
use App\Http\Requests\Admin\Attribute\UpdateAttributeRequest;
use App\Models\Attribute;
use App\Services\Admin\AttributeService;

class AttributeController extends Controller
{
    public function __construct(public AttributeService $attributeService) {}

    public function index()
    {
        return $this->attributeService->index();
    }

    public function store(StoreAttributeRequest $request)
    {
        return $this->attributeService->store($request);
    }

    public function show(Attribute $attribute)
    {
        return $this->attributeService->show($attribute->id);
    }

    public function update(UpdateAttributeRequest $request, Attribute $attribute)
    {
        return $this->attributeService->update($attribute, $request);
    }
}
