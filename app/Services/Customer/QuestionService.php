<?php

namespace App\Services\Customer;

use App\Http\Requests\Customer\StoreQuestionRequest;
use App\Http\Resources\Customer\ProductQuestionResource;
use App\Models\Product;
use App\Models\ProductQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class QuestionService
{
    /**
     * Get list of questions and answers for a product.
     */
    public function getProductQuestions(Request $request, string $slug_url): JsonResponse
    {
        $productId = extractIdFromSlug($slug_url);

        $product = Product::find($productId);
        if (! $product) {
            return responseError('Product not found', 404);
        }

        $baseQuery = ProductQuestion::where('product_id', $productId)
            ->where('status', 'approved');

        $totalQuestions = (clone $baseQuery)->count();

        $query = (clone $baseQuery)->with('client');

        $sortBy = $request->input('sort', 'recent');
        if ($sortBy === 'popular') {
            $query->orderByDesc('likes_count')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $questions = $query->paginate($perPage);

        return responseSuccess([
            'questions' => ProductQuestionResource::collection($questions),
            'total' => $totalQuestions,
            'pagination' => pagination($questions),
        ]);
    }

    /**
     * Store a new product question.
     */
    public function storeQuestion(StoreQuestionRequest $request, string $slug_url): JsonResponse
    {
        $productId = extractIdFromSlug($slug_url);

        $product = Product::find($productId);
        if (! $product) {
            return responseError('Product not found', 404);
        }

        $client = auth('sanctum')->user();
        $authorName = $client?->name ?: ($request->input('author_name') ?: 'Customer');
        $authorEmail = $client?->email ?: $request->input('author_email');

        $question = ProductQuestion::create([
            'product_id' => $productId,
            'client_id' => $client?->id,
            'author_name' => $authorName,
            'author_email' => $authorEmail,
            'question' => trim($request->input('question')),
            'likes_count' => 0,
            'status' => 'approved',
        ]);

        Cache::forget("product_details_{$productId}");

        return responseSuccess(
            ProductQuestionResource::make($question),
            'Question submitted successfully',
            201
        );
    }

    /**
     * Like a question.
     */
    public function likeQuestion(int $id): JsonResponse
    {
        $question = ProductQuestion::find($id);
        if (! $question) {
            return responseError('Question not found', 404);
        }

        $question->increment('likes_count');

        return responseSuccess([
            'id' => (string) $question->id,
            'likes' => (int) $question->likes_count,
        ], 'Liked question successfully');
    }
}
