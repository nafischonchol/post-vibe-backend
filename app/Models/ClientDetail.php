<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'country',
        'website_or_fb',
        'trade_license',
        'company_address',
        'contact_name',
        'position',
        'business_type',
        'hear_about_us',
        'interested_categories',
        'business_introduction',
        'nda_agreed',
    ];

    protected $casts = [
        'interested_categories' => 'array',
        'nda_agreed' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
