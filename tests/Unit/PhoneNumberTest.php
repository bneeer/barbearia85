<?php

namespace Tests\Unit;

use App\Rules\InternationalPhone;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public static function validPhones(): array
    {
        return [
            ['(11) 99999-9999', '+5511999999999'],
            ['11 3333-4444', '+551133334444'],
            ['+1 (202) 555-0123', '+12025550123'],
            ['+44 20 7946 0018', '+442079460018'],
            ['00351 912 345 678', '+351912345678'],
            ['+33 1 42 68 53 00', '+33142685300'],
            ['+91 98765 43210', '+919876543210'],
            ['+81 90 1234 5678', '+819012345678'],
            ['+61 412 345 678', '+61412345678'],
            ['+27 82 123 4567', '+27821234567'],
            ['+800 1234 5678', '+80012345678'],
            ['+290 21234', '+29021234'],
            ['+39 02 3661 8300', '+390236618300'],
            ['+49 30 12345678901', '+493012345678901'],
        ];
    }

    #[DataProvider('validPhones')]
    public function test_normalizes_and_preserves_e164(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
        $this->assertSame($expected, PhoneNumber::normalize($expected));
        $this->assertTrue(Validator::make(['phone' => $input], [
            'phone' => ['required', new InternationalPhone],
        ])->passes());
    }

    public static function invalidPhones(): array
    {
        return array_map(fn ($value) => [$value], [
            '', '123', '(())------', '+999123456789', '+1202555012345678',
            '+1 202 555 0123 ext 123', '+1 800 FLOWERS', 'Tel: +5511999999999',
            '+5511999999999#123', '+4930123456789012', str_repeat('1', 65),
        ]);
    }

    #[DataProvider('invalidPhones')]
    public function test_rejects_invalid_numbers_and_extensions(string $input): void
    {
        $this->assertTrue(Validator::make(['phone' => $input], [
            'phone' => ['required', new InternationalPhone],
        ])->fails());
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::normalize($input);
    }
}
