<?php

namespace App\Services\Customer;

use App\Enums\OrderStatusEnum;
use App\Http\Requests\Customer\StoreReviewRequest;
use App\Http\Resources\Customer\ProductReviewResource;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReviewService
{
    /**
     * Get list of reviews and review statistics for a product.
     */
    public function getProductReviews(Request $request, string $slug_url): JsonResponse
    {
        $productId = extractIdFromSlug($slug_url);

        $product = Product::find($productId);
        if (! $product) {
            return responseError('Product not found', 404);
        }

        $baseQuery = ProductReview::where('product_id', $productId)
            ->where('status', 'approved');

        $totalReviews = (clone $baseQuery)->count();
        $avgRating = $totalReviews > 0
            ? round((float) (clone $baseQuery)->avg('rating'), 1)
            : 0;

        // Calculate rating breakdown for 5, 4, 3, 2, 1 stars
        $breakdownCounts = (clone $baseQuery)
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating')
            ->toArray();

        $ratingBreakdown = [
            5 => $breakdownCounts[5] ?? 0,
            4 => $breakdownCounts[4] ?? 0,
            3 => $breakdownCounts[3] ?? 0,
            2 => $breakdownCounts[2] ?? 0,
            1 => $breakdownCounts[1] ?? 0,
        ];

        // Apply filters & sorting
        $query = (clone $baseQuery)->with('client');

        if ($request->filled('rating') && is_numeric($request->rating)) {
            $query->where('rating', (int) $request->rating);
        }

        $sortBy = $request->input('sort', 'recent');
        if ($sortBy === 'highest') {
            $query->orderByDesc('rating')->orderByDesc('id');
        } elseif ($sortBy === 'lowest') {
            $query->orderBy('rating')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $reviews = $query->paginate($perPage);

        return responseSuccess([
            'reviews' => ProductReviewResource::collection($reviews),
            'summary' => [
                'average_rating' => $avgRating,
                'total_reviews' => $totalReviews,
                'breakdown' => $ratingBreakdown,
            ],
            'pagination' => pagination($reviews),
        ]);
    }

    /**
     * Store a new product review.
     */
    public function storeReview(StoreReviewRequest $request, string $slug_url): JsonResponse
    {
        $client = auth('sanctum')->user();
        if (! $client) {
            return responseError('You must be logged in to review a product.', 401);
        }

        $productId = extractIdFromSlug($slug_url);

        $product = Product::find($productId);
        if (! $product) {
            return responseError('Product not found', 404);
        }

        $order = Order::where('id', $request->order_id)
            ->where('client_id', $client->id)
            ->first();

        if (! $order) {
            return responseError('Order not found or does not belong to you', 404);
        }

        $statusValue = is_object($order->status) ? $order->status->value : (string) $order->status;
        if ($statusValue !== OrderStatusEnum::DELIVERED->value && $statusValue !== 'Delivered') {
            return responseError('Reviews can only be submitted for delivered orders.', 422);
        }

        $orderItem = OrderItem::where('id', $request->order_item_id)
            ->where('order_id', $order->id)
            ->where('product_id', $productId)
            ->first();

        if (! $orderItem) {
            return responseError('Invalid order item for this product', 422);
        }

        if (ProductReview::where('order_item_id', $orderItem->id)->exists()) {
            return responseError('You have already submitted a review for this order item.', 422);
        }

        $review = ProductReview::create([
            'product_id' => $productId,
            'client_id' => $client->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'rating' => (int) $request->rating,
            'title' => $request->title,
            'content' => $request->content,
            'verified' => true,
            'helpful_count' => 0,
            'status' => 'approved',
        ]);

        // Invalidate product details cache so updated ratings/reviews show immediately
        Cache::forget("product_details_{$productId}");

        $review->setRelation('client', $client);

        return responseSuccess(
            ProductReviewResource::make($review),
            'Review submitted successfully',
            201
        );
    }

    /**
     * Vote helpful on a review.
     */
    public function voteHelpful(int $id): JsonResponse
    {
        $review = ProductReview::find($id);
        if (! $review) {
            return responseError('Review not found', 404);
        }

        $review->increment('helpful_count');

        return responseSuccess([
            'id' => (string) $review->id,
            'helpfulCount' => (int) $review->helpful_count,
        ], 'Vote recorded successfully');
    }

    /**
     * Check if the authenticated customer is eligible to review a product.
     */
    public function checkEligibility(string $slug_url): JsonResponse
    {
        $client = auth('sanctum')->user();
        if (! ($client instanceof Client)) {
            return responseSuccess([
                'can_review' => false,
                'order_id' => null,
                'order_item_id' => null,
            ]);
        }

        $productId = extractIdFromSlug($slug_url);
        $product = Product::find($productId);
        if (! $product) {
            return responseError('Product not found', 404);
        }

        // Find delivered order item for this product that has not been reviewed yet
        $eligibleItem = OrderItem::whereHas('order', function ($query) use ($client) {
            $query->where('client_id', $client->id)
                ->where('status', OrderStatusEnum::DELIVERED->value);
        })
        ->where('product_id', $productId)
        ->whereDoesntHave('review')
        ->first();

        return responseSuccess([
            'can_review' => ! is_null($eligibleItem),
            'order_id' => $eligibleItem?->order_id,
            'order_item_id' => $eligibleItem?->id,
        ]);
    }
}

