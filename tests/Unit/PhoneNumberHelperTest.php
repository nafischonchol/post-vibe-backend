<?php

namespace Tests\Unit;

use App\Helpers\PhoneNumberHelper;
use PHPUnit\Framework\TestCase;

class PhoneNumberHelperTest extends TestCase
{
    public function test_normalizes_whatsapp_phone_with_country_code(): void
    {
        // 13-digit format starting with 8801
        $this->assertEquals('01712345678', PhoneNumberHelper::toLocalBd('8801712345678'));
        $this->assertEquals('01899887766', PhoneNumberHelper::toLocalBd('+8801899887766'));
        $this->assertEquals('01911223344', PhoneNumberHelper::toLocalBd('880 1911-223344'));
    }

    public function test_normalizes_standard_11_digit_bd_numbers(): void
    {
        $this->assertEquals('01712345678', PhoneNumberHelper::toLocalBd('01712345678'));
        $this->assertEquals('01600000000', PhoneNumberHelper::toLocalBd('01600-000000'));
    }

    public function test_rejects_invalid_numbers(): void
    {
        $this->assertNull(PhoneNumberHelper::toLocalBd('01212345678')); // Invalid operator '012'
        $this->assertNull(PhoneNumberHelper::toLocalBd('12345'));        // Too short
        $this->assertNull(PhoneNumberHelper::toLocalBd(null));
        $this->assertNull(PhoneNumberHelper::toLocalBd(''));
    }

    public function test_converts_local_to_whatsapp_e164(): void
    {
        $this->assertEquals('8801712345678', PhoneNumberHelper::toWhatsAppE164('01712345678'));
        $this->assertEquals('8801712345678', PhoneNumberHelper::toWhatsAppE164('8801712345678'));
    }
}
