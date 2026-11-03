<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Models\Client;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    /**
     * Display a listing of customer wishlists.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search');

        // Overall stats
        $stats = [
            'total_wishlists' => Wishlist::count(),
            'total_customers' => Wishlist::whereNotNull('client_id')->distinct('client_id')->count('client_id'),
            'total_guests' => Wishlist::whereNull('client_id')->distinct('guest_token')->count('guest_token'),
            'total_products' => Wishlist::distinct('product_id')->count('product_id'),
        ];

        // Group by client_id or guest_token
        $query = Wishlist::query()
            ->select(
                DB::raw('COALESCE(CONCAT("c_", client_id), CONCAT("g_", guest_token)) as session_id'),
                'client_id',
                'guest_token',
                DB::raw('COUNT(id) as total_items'),
                DB::raw('MAX(updated_at) as last_updated_at')
            )
            ->groupBy(DB::raw('COALESCE(CONCAT("c_", client_id), CONCAT("g_", guest_token))'), 'client_id', 'guest_token');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('client', function ($clientQ) use ($search) {
                    $clientQ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('product', function ($prodQ) use ($search) {
                    $prodQ->where('name', 'like', "%{$search}%");
                });
            });
        }

        $paginated = $query->orderByDesc('last_updated_at')->paginate($perPage);

        $clientIds = collect($paginated->items())->pluck('client_id')->filter()->unique()->toArray();
        $clients = Client::whereIn('id', $clientIds)->get()->keyBy('id');

        $guestTokens = collect($paginated->items())->whereNull('client_id')->pluck('guest_token')->filter()->unique()->toArray();

        $allWishlistItems = Wishlist::with(['product.category', 'variant'])
            ->where(function ($q) use ($clientIds, $guestTokens) {
                if (!empty($clientIds)) {
                    $q->whereIn('client_id', $clientIds);
                }
                if (!empty($guestTokens)) {
                    $q->orWhereIn('guest_token', $guestTokens);
                }
            })
            ->get();

        $formattedItems = collect($paginated->items())->map(function ($row) use ($clients, $allWishlistItems) {
            $client = $row->client_id ? $clients->get($row->client_id) : null;

            $items = $allWishlistItems->filter(function ($item) use ($row) {
                if ($row->client_id) {
                    return $item->client_id === $row->client_id;
                }
                return $item->guest_token === $row->guest_token;
            })->values();

            $totalValue = $items->sum(function ($item) {
                $variant = $item->variant ?? $item->product?->variants->first();
                return (float) ($variant?->discount_price ?? $variant?->price ?? 0);
            });

            return [
                'session_id' => $row->session_id,
                'is_guest' => empty($row->client_id),
                'client' => $client ? [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'email' => $client->email,
                    'avatar' => $client->avatar,
                ] : null,
                'guest_token' => $row->guest_token,
                'total_items' => (int) $row->total_items,
                'total_estimated_value' => (float) $totalValue,
                'last_updated_at' => $row->last_updated_at,
                'items' => $items->map(function ($item) {
                    $product = $item->product;
                    $variant = $item->variant ?? $product?->variants->first();
                    $price = (float) ($variant?->discount_price ?? $variant?->price ?? 0);
                    $stock = (int) ($variant?->stock ?? 0);

                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $product?->name ?? 'Product Unavailable',
                        'product_slug' => $product?->slug ?? '',
                        'product_image' => $product?->image ? (str_starts_with($product->image, 'http') ? $product->image : asset('storage/' . ltrim($product->image, '/'))) : '',
                        'category_name' => $product?->category?->name ?? '',
                        'variant_id' => $item->product_variant_id,
                        'price' => $price,
                        'stock' => $stock,
                        'is_in_stock' => $stock > 0,
                    ];
                }),
            ];
        });

        return responseSuccess([
            'summary' => $stats,
            'items' => $formattedItems,
            'pagination' => pagination($paginated),
        ]);
    }

    /**
     * Product demand in wishlists.
     */
    public function demand(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search');

        $query = Wishlist::query()
            ->select(
                'product_id',
                DB::raw('COUNT(id) as wishlist_count'),
                DB::raw('MAX(updated_at) as last_added_at')
            )
            ->groupBy('product_id');

        if (!empty($search)) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $paginated = $query->orderByDesc('wishlist_count')->paginate($perPage);

        $productIds = collect($paginated->items())->pluck('product_id')->toArray();
        $products = Product::with(['category', 'brand', 'variants'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $items = collect($paginated->items())->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);
            $totalStock = $product ? (int) $product->variants->sum('stock') : 0;
            $minPrice = $product ? (float) $product->variants->min('price') : 0;

            return [
                'product_id' => $row->product_id,
                'name' => $product?->name ?? 'Unknown Product',
                'slug' => $product?->slug ?? '',
                'image' => $product?->image ? (str_starts_with($product->image, 'http') ? $product->image : asset('storage/' . ltrim($product->image, '/'))) : '',
                'category_name' => $product?->category?->name ?? '',
                'brand_name' => $product?->brand?->name ?? '',
                'wishlist_count' => (int) $row->wishlist_count,
                'current_stock' => $totalStock,
                'unit_price' => $minPrice,
                'stock_status' => $totalStock <= 0 ? 'out_of_stock' : ($totalStock <= 10 ? 'low_stock' : 'in_stock'),
                'last_added_at' => $row->last_added_at,
            ];
        });

        return responseSuccess([
            'items' => $items,
            'pagination' => pagination($paginated),
        ]);
    }

    /**
     * Delete wishlist records for a session or client.
     */
    public function destroy($sessionId)
    {
        if (str_starts_with($sessionId, 'c_')) {
            $clientId = substr($sessionId, 2);
            Wishlist::where('client_id', $clientId)->delete();
        } elseif (str_starts_with($sessionId, 'g_')) {
            $guestToken = substr($sessionId, 2);
            Wishlist::where('guest_token', $guestToken)->delete();
        } else {
            Wishlist::where('id', $sessionId)->delete();
        }

        return responseSuccess(null, 'Wishlist cleared successfully');
    }
}
