<?php

namespace App\Services\AiChat\Tools;

use App\Models\ShippingSetting;
use Illuminate\Support\Facades\Log;

class DeliveryChargeTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_delivery_charge',
                'description' => 'Get delivery timeline (how many days to deliver) or shipping charges for Mohima. Call this whenever a customer asks about delivery time ("koto dine pabo?", "kobe pabo?"), delivery charge ("delivery charge koto?"), or COD.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'inquiry_type' => [
                            'type' => 'string',
                            'enum' => ['delivery_time', 'delivery_charge', 'all'],
                            'description' => 'Pass "delivery_time" if customer ONLY asked about delivery days/time ("koto dine pabo", "kobe pabo"). Pass "delivery_charge" if customer asked about delivery fees/cost ("charge koto", "dam koto"). Pass "all" only if customer asked about both time and charge.',
                        ],
                        'location' => [
                            'type' => 'string',
                            'description' => 'Optional location specified by customer (e.g. "dhaka", "outside dhaka", "chittagong").',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args): array
    {
        Log::debug("DeliveryChargeTool executed", ['args' => $args]);

        $inquiryType = strtolower(trim($args['inquiry_type'] ?? 'all'));
        $location = mb_strtolower(trim($args['location'] ?? ''));

        $shipping = \Illuminate\Support\Facades\Cache::remember('active_shipping_setting', 3600, fn() => ShippingSetting::first());
        $insideCharge = $shipping ? (float) $shipping->inside_dhaka_charge : 70.0;
        $outsideCharge = $shipping ? (float) $shipping->outside_dhaka_charge : 130.0;
        $insideTime = ($shipping && $shipping->estimated_delivery_inside_dhaka) ? $shipping->estimated_delivery_inside_dhaka : '১-২ কার্যদিবস (ঢাকা সিটিতে)';
        $outsideTime = ($shipping && $shipping->estimated_delivery_outside_dhaka) ? $shipping->estimated_delivery_outside_dhaka : '২-৪ কার্যদিবস (ঢাকার বাইরে ৬৪ জেলায়)';
        $freeActive = $shipping ? (bool) $shipping->is_free_shipping_active : false;
        $freeThreshold = $shipping ? (float) $shipping->free_shipping_threshold : 0.0;

        if ($inquiryType === 'delivery_time') {
            return [
                'inquiry_type' => 'delivery_time',
                'delivery_timelines' => [
                    'inside_dhaka' => $insideTime,
                    'outside_dhaka' => $outsideTime,
                ],
                'courier_partners' => 'SteadFast / Pathao / RedX Courier',
                'cod_policy' => 'সারা বাংলাদেশে ক্যাশ অন ডেলিভারি সুবিধা এবং পার্সেল চেক করে পেমেন্ট করার সুযোগ রয়েছে।',
                'guidance' => 'Answer clearly how long delivery takes (Inside Dhaka: 1-2 days, Outside Dhaka: 2-4 days). You do not need to mention delivery charges unless asked.',
            ];
        }

        if ($inquiryType === 'delivery_charge') {
            return [
                'inquiry_type' => 'delivery_charge',
                'inside_dhaka' => "৳{$insideCharge} BDT",
                'outside_dhaka' => "৳{$outsideCharge} BDT",
                'free_shipping' => $freeActive && $freeThreshold > 0 ? "৳{$freeThreshold} টাকার অর্ডারে ফ্রি ডেলিভারি!" : null,
                'cod_policy' => 'ক্যাশ অন ডেলিভারি (COD) প্রযোজ্য। ডেলিভারিম্যানের সামনে পার্সেল চেক করে রিসিভ করতে পারবেন।',
                'guidance' => 'State the delivery charges clearly: Inside Dhaka ৳' . $insideCharge . ', Outside Dhaka ৳' . $outsideCharge . '.',
            ];
        }

        return [
            'inquiry_type' => 'all',
            'inside_dhaka_charge' => "৳{$insideCharge} BDT",
            'outside_dhaka_charge' => "৳{$outsideCharge} BDT",
            'inside_dhaka_time' => $insideTime,
            'outside_dhaka_time' => $outsideTime,
            'free_shipping' => $freeActive && $freeThreshold > 0 ? "৳{$freeThreshold} টাকার অর্ডারে ফ্রি ডেলিভারি!" : null,
            'cod_policy' => 'ক্যাশ অন ডেলিভারি (COD) সুবিধা আছে। পার্সেল চেক করে পেমেন্ট করার নিশ্চয়তা।',
        ];
    }
}
