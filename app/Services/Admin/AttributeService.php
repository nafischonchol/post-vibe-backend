<?php

namespace App\Services\Admin;

use App\Http\Requests\Admin\Attribute\StoreAttributeRequest;
use App\Http\Requests\Admin\Attribute\UpdateAttributeRequest;
use App\Http\Resources\AttributeResource;
use App\Models\Attribute;
use App\Traits\UploadAble;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttributeService
{
    use UploadAble;

    public function index()
    {
        $attributes = Attribute::with('attributeValues')->latest()->get();

        return responseSuccess(AttributeResource::collection($attributes));
    }

    public function show(string $id)
    {
        $attribute = Attribute::with('attributeValues')->findOrFail($id);

        return responseSuccess(AttributeResource::make($attribute));
    }

    public function store(StoreAttributeRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            $data['is_active'] = $request->boolean('is_active', true);
            $data['is_default_specification'] = $request->boolean('is_default_specification', false);

            $rawValues = $request->input('values');
            if (is_string($rawValues)) {
                $decoded = json_decode($rawValues, true);
                if (is_array($decoded)) {
                    $rawValues = $decoded;
                }
            }

            $attribute = Attribute::create([
                'name' => $data['name'],
                'slug' => ! empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']),
                'type' => $data['type'],
                'values' => null,
                'is_active' => $data['is_active'],
                'is_default_specification' => $data['is_default_specification'],
            ]);

            if ($data['type'] === Attribute::TYPE['TEXT'] || $data['type'] === Attribute::TYPE['RICH_TEXT']) {
                $syncedValues = null;
            } else {
                $syncedValues = $this->syncAttributeValues($attribute, $rawValues, $request);
            }

            $attribute->update(['values' => $syncedValues]);

            DB::commit();

            return responseSuccess(AttributeResource::make($attribute->load('attributeValues')), 'Attribute created successfully');
        } catch (\Exception $e) {
            DB::rollBack();

            return responseError('Failed to create attribute: '.$e->getMessage(), 500);
        }
    }

    public function update(Attribute $attribute, UpdateAttributeRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            $data['is_active'] = $request->boolean('is_active', true);
            $data['is_default_specification'] = $request->boolean('is_default_specification', false);

            $rawValues = $request->input('values');
            if (is_string($rawValues)) {
                $decoded = json_decode($rawValues, true);
                if (is_array($decoded)) {
                    $rawValues = $decoded;
                }
            }

            $attribute->update([
                'name' => $data['name'],
                'slug' => ! empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']),
                'type' => $data['type'],
                'is_active' => $data['is_active'],
                'is_default_specification' => $data['is_default_specification'],
            ]);

            if ($data['type'] === Attribute::TYPE['TEXT'] || $data['type'] === Attribute::TYPE['RICH_TEXT']) {
                $syncedValues = null;
            } else {
                $syncedValues = $this->syncAttributeValues($attribute, $rawValues, $request);
            }

            $attribute->update(['values' => $syncedValues]);

            DB::commit();

            return responseSuccess([], 'Attribute updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();

            return responseError('Failed to update attribute: '.$e->getMessage(), 500);
        }
    }

    private function syncAttributeValues(Attribute $attribute, $valuesInput, $request): array
    {
        if (is_null($valuesInput) || $attribute->type === Attribute::TYPE['TEXT'] || $attribute->type === Attribute::TYPE['RICH_TEXT']) {
            foreach ($attribute->attributeValues as $oldVal) {
                if ($oldVal->image) {
                    $this->deleteFile($oldVal->image);
                }
                $oldVal->delete();
            }

            return [];
        }

        if (! is_array($valuesInput)) {
            $valuesInput = [];
        }

        $existingValues = $attribute->attributeValues()->withTrashed()->get();
        $existingById = $existingValues->keyBy('id');
        $existingByValue = $existingValues->keyBy('value');

        $processedIds = [];
        $savedValuesList = [];

        foreach ($valuesInput as $index => $item) {
            $valString = '';
            $id = null;
            $removeImage = false;
            $metaTitle = null;
            $metaDescription = null;
            $isActive = true;

            if (is_array($item)) {
                $valString = trim($item['value'] ?? '');
                $id = ! empty($item['id']) ? (int) $item['id'] : null;
                $removeImage = ! empty($item['remove_image']);
                $metaTitle = isset($item['meta_title']) ? trim((string) $item['meta_title']) : null;
                $metaDescription = isset($item['meta_description']) ? trim((string) $item['meta_description']) : null;
                if (isset($item['is_active'])) {
                    $isActive = filter_var($item['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
                }
                if ($metaTitle === '') {
                    $metaTitle = null;
                }
                if ($metaDescription === '') {
                    $metaDescription = null;
                }
            } else {
                $valString = trim((string) $item);
            }

            if ($valString === '') {
                continue;
            }

            // Check if file was uploaded for this value index
            $uploadedFile = null;
            if ($request->hasFile("value_image_{$index}")) {
                $uploadedFile = $request->file("value_image_{$index}");
            } elseif ($request->hasFile("value_images.{$index}")) {
                $uploadedFile = $request->file("value_images.{$index}");
            }

            // Find existing record
            $existingVal = null;
            if ($id && $existingById->has($id)) {
                $existingVal = $existingById->get($id);
            } elseif ($existingByValue->has($valString)) {
                $existingVal = $existingByValue->get($valString);
            }

            $imagePath = $existingVal ? $existingVal->image : null;

            // Handle image upload / removal
            if ($uploadedFile) {
                if ($imagePath) {
                    $this->deleteFile($imagePath);
                }
                $imagePath = $this->uploadFile($uploadedFile, 'attribute_values');
            } elseif ($removeImage) {
                if ($imagePath) {
                    $this->deleteFile($imagePath);
                }
                $imagePath = null;
            }

            if ($existingVal) {
                if ($existingVal->trashed()) {
                    $existingVal->restore();
                }
                $existingVal->update([
                    'value' => $valString,
                    'image' => $imagePath,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'is_active' => $isActive,
                ]);
                $processedIds[] = $existingVal->id;
                $savedValuesList[] = [
                    'id' => $existingVal->id,
                    'value' => $valString,
                    'image' => $imagePath,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'is_active' => $isActive,
                ];
            } else {
                $newVal = $attribute->attributeValues()->create([
                    'value' => $valString,
                    'image' => $imagePath,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'is_active' => $isActive,
                ]);
                $processedIds[] = $newVal->id;
                $savedValuesList[] = [
                    'id' => $newVal->id,
                    'value' => $valString,
                    'image' => $imagePath,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'is_active' => $isActive,
                ];
            }
        }

        // Clean up deleted values and their images
        $toDelete = $attribute->attributeValues()->whereNotIn('id', $processedIds)->get();
        foreach ($toDelete as $deletedVal) {
            if ($deletedVal->image) {
                $this->deleteFile($deletedVal->image);
            }
            $deletedVal->delete();
        }

        return $savedValuesList;
    }
}
