<?php

namespace App\Enums;

enum BannerTypeEnum: string
{
    case HERO = 'hero';
    case PROMO = 'promo';
    case SIDEBAR = 'sidebar';
    case POPUP = 'popup';

    public function label(): string
    {
        return match ($this) {
            self::HERO => 'Hero Banner',
            self::PROMO => 'Promotional Banner',
            self::SIDEBAR => 'Sidebar Banner',
            self::POPUP => 'Popup Banner',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
