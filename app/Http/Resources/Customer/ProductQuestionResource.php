<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'askedBy' => $this->author_name ?: ($this->client?->name ?? 'Customer'),
            'date' => $this->created_at ? $this->created_at->format('F d, Y') : '',
            'likes' => (int) $this->likes_count,
            'answeredBy' => $this->answered_by,
            'answeredAt' => $this->answered_at ? $this->answered_at->format('F d, Y') : null,
        ];
    }
}
