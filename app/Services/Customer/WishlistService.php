<?php

namespace App\Services\Customer;

use App\Http\Requests\Customer\MergeWishlistRequest;
use App\Http\Requests\Customer\WishlistToggleRequest;
use App\Http\Resources\WishlistResource;
use App\Models\Client;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WishlistService
{
    public function __construct(
        protected VisitorService $visitorService
    ) {}

    /**
     * Get wishlist collection wrapped in responseSuccess helper.
     */
    public function getWishlist(Request $request): JsonResponse
    {
        $client = auth('sanctum')->user();
        $guestToken = $request->header('X-Visitor-Id') ?: $request->input('guest_token');

        $query = Wishlist::with(['product.images', 'variant']);

        if ($client) {
            $items = $query->where('client_id', $client->id)->get();
        } elseif ($guestToken) {
            $items = $query->where('guest_token', $guestToken)->get();
        } else {
            $items = new Collection;
        }

        return responseSuccess(WishlistResource::collection($items), 'Wishlist items retrieved successfully');
    }

    /**
     * Toggle product in wishlist and return responseSuccess JsonResponse.
     */
    public function toggleWishlist(WishlistToggleRequest $request): JsonResponse
    {
        $client = auth('sanctum')->user();
        $guestToken = $request->header('X-Visitor-Id') ?: $request->input('guest_token');
        $productId = (int) $request->validated('product_id');
        $variantId = $request->validated('product_variant_id') ? (int) $request->validated('product_variant_id') : null;

        // Record visitor session activity
        if ($guestToken || $client) {
            $this->visitorService->recordSession($request, $client);
        }

        $result = DB::transaction(function () use ($client, $guestToken, $productId, $variantId) {
            $existing = $this->findExistingWishlistItem($client, $guestToken, $productId, $variantId);

            if ($existing) {
                $existing->delete();

                return [
                    'attached' => false,
                    'message' => 'Product removed from wishlist.',
                ];
            }

            Wishlist::create([
                'client_id' => $client?->id,
                'guest_token' => $client ? null : $guestToken,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
            ]);

            return [
                'attached' => true,
                'message' => 'Product added to wishlist.',
            ];
        });

        return responseSuccess(['attached' => (bool) $result['attached']], $result['message']);
    }

    /**
     * Merge guest wishlist into client wishlist and return responseSuccess or responseError.
     */
    public function mergeGuestWishlist(MergeWishlistRequest $request): JsonResponse
    {
        $client = auth('sanctum')->user();

        if (! $client) {
            return responseError('Unauthenticated.', 401);
        }

        $guestToken = $request->validated('guest_token');

        // Associate visitor log with authenticated client
        $this->visitorService->associateClientWithToken($client, $guestToken);

        // Perform merge in a DB transaction
        $mergedItems = DB::transaction(function () use ($client, $guestToken) {
            $guestItems = Wishlist::where('guest_token', $guestToken)->get();

            foreach ($guestItems as $guestItem) {
                $alreadyExists = Wishlist::where('client_id', $client->id)
                    ->where('product_id', $guestItem->product_id)
                    ->where('product_variant_id', $guestItem->product_variant_id)
                    ->exists();

                if (! $alreadyExists) {
                    Wishlist::create([
                        'client_id' => $client->id,
                        'guest_token' => null,
                        'product_id' => $guestItem->product_id,
                        'product_variant_id' => $guestItem->product_variant_id,
                    ]);
                }

                $guestItem->delete();
            }

            return Wishlist::with(['product.images', 'variant'])
                ->where('client_id', $client->id)
                ->get();
        });

        return responseSuccess(WishlistResource::collection($mergedItems), 'Wishlist merged successfully');
    }

    /**
     * Helper to find an existing wishlist item.
     */
    private function findExistingWishlistItem(?Client $client, ?string $guestToken, int $productId, ?int $variantId = null): ?Wishlist
    {
        $query = Wishlist::query();

        if ($client) {
            $query->where('client_id', $client->id);
        } elseif ($guestToken) {
            $query->where('guest_token', $guestToken);
        } else {
            return null;
        }

        return $query->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();
    }
}
