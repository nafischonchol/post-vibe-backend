<?php

namespace App\Jobs;

use App\Models\Wishlist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class MergeWishlistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $clientId,
        public string $guestToken
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            $guestItems = Wishlist::where('guest_token', $this->guestToken)->get();

            foreach ($guestItems as $guestItem) {
                $alreadyExists = Wishlist::where('client_id', $this->clientId)
                    ->where('product_id', $guestItem->product_id)
                    ->where('product_variant_id', $guestItem->product_variant_id)
                    ->exists();

                if (! $alreadyExists) {
                    Wishlist::create([
                        'client_id' => $this->clientId,
                        'guest_token' => null,
                        'product_id' => $guestItem->product_id,
                        'product_variant_id' => $guestItem->product_variant_id,
                    ]);
                }

                $guestItem->delete();
            }
        });
    }
}
