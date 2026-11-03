<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->client?->name ?? 'Verified Customer',
            'rating' => (int) $this->rating,
            'date' => $this->created_at ? $this->created_at->format('F d, Y') : '',
            'title' => $this->title ?? '',
            'content' => $this->content,
            'verified' => (bool) $this->verified,
            'helpfulCount' => (int) $this->helpful_count,
            'reply' => $this->reply_author && $this->reply_content ? [
                'author' => $this->reply_author,
                'content' => $this->reply_content,
                'date' => $this->reply_date ? $this->reply_date->format('F d, Y') : '',
            ] : null,
        ];
    }
}
