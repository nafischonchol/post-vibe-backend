<?php

namespace App\Services\AiChat\Tools;

use Illuminate\Support\Facades\Log;

class SupportFaqTool
{
    public static function getDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_support_faq',
                'description' => 'Retrieve official Mohima store policies, authenticity guarantee, payment methods, delivery guarantees, parcel checking facility, and return policies. Call this whenever a customer asks about authenticity ("product authentic ki?", "original kina?"), checking parcels, return policies, or store contact info.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search keyword or customer question (e.g. "authentic", "original", "return policy", "payment", "checking parcel").',
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function execute(array $args): array
    {
        Log::debug("SupportFaqTool executed", $args);
        $rawQuery = mb_strtolower(trim($args['query'] ?? ''));

        $faqs = [
            [
                'topic' => 'Authenticity & Genuine Guarantee',
                'keywords' => ['authentic', 'original', 'fake', 'asol', 'অরিজিনাল', 'নকল', 'আসল', 'authenticity', 'guarantee'],
                'answer' => "Mohima-র প্রতিটি পণ্য ১০০% অরিজিনাল ও অথেনটিক। আমরা সরাসরি সাউথ কোরিয়া এবং অফিশিয়াল ব্র্যান্ড ডিস্ট্রিবিউটরদের থেকে পণ্য আমদানি করি। আমাদের প্রতিটি পণ্যের বারকোড এবং ব্যাচ কোড যাচাইযোগ্য। কোনো প্রকার নকল বা ডুপ্লিকেট পণ্য আমরা বিক্রয় করি না।",
            ],
            [
                'topic' => 'Parcel Checking & Cash on Delivery',
                'keywords' => ['check', 'parcel', 'চেক', 'পেমেন্ট', 'delivery', 'cod', 'cash on delivery', 'পার্সেল'],
                'answer' => "সারা বাংলাদেশে ক্যাশ অন ডেলিভারি (COD) সুবিধা রয়েছে। ডেলিভারিম্যান পার্সেল নিয়ে আসলে আপনি পার্সেল খুলে প্রোডাক্টটি ঠিক আছে কিনা দেখে নিশ্চিন্ত হয়ে মূল্য পরিশোধ করতে পারবেন।",
            ],
            [
                'topic' => 'Return & Replacement Policy',
                'keywords' => ['return', 'replacement', 'damaged', 'ভাঙা', 'রিটার্ন', 'ফেরত', 'চেঞ্জ', 'change'],
                'answer' => "ডেলিভারি গ্রহণের সময় যদি কোনো পণ্য ভাঙা বা ভুল আসে, ডেলিভারিম্যানের সামনে তাৎক্ষণিক রিটার্ন করার সুযোগ রয়েছে। এছাড়া আনবক্সিং ভিডিও সহ ২৪-৪৮ ঘণ্টার মধ্যে আমাদের সাপোর্ট সেন্টারে যোগাযোগ করলে দ্রুত রিপ্লেসমেন্ট প্রদান করা হয়।",
            ],
            [
                'topic' => 'Payment Methods',
                'keywords' => ['payment', 'bkash', 'nagad', 'card', 'পেমেন্ট', 'বিকাশ', 'নগদ', 'টাকা'],
                'answer' => "আমরা ক্যাশ অন ডেলিভারি (COD), বিকাশ, নগদ এবং অনলাইন কার্ড পেমেন্ট গ্রহণ করি।",
            ],
            [
                'topic' => 'Customer Care & Hotlines',
                'keywords' => ['contact', 'phone', 'whatsapp', 'যোগাযোগ', 'ফোন', 'নাম্বার', 'ঠিকানা', 'হটলাইন', 'helpline'],
                'answer' => "আমাদের কাস্টমার কেয়ারে যেকোনো সময় মেসেজ দিতে পারেন অথবা হেল্পলাইনে সরাসরি কথা বলতে পারেন। আমাদের প্রতিনিধিরা সব সময় আপনাকে সেবা দিতে প্রস্তুত।",
            ],
        ];

        $matchedFaqs = [];
        if ($rawQuery !== '') {
            foreach ($faqs as $faq) {
                foreach ($faq['keywords'] as $kw) {
                    if (str_contains($rawQuery, $kw) || str_contains($kw, $rawQuery)) {
                        $matchedFaqs[] = $faq;
                        break;
                    }
                }
            }
        }

        if (empty($matchedFaqs)) {
            $matchedFaqs = array_slice($faqs, 0, 3);
        }

        return [
            'faqs' => $matchedFaqs,
            'guidance' => 'Answer the customer with reassuring, polite tone affirming 100% genuine imported Korean products, parcel check facility before payment, and excellent customer service.',
        ];
    }
}
