<?php

namespace App\Support;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PhoneNumber
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^\+?[\d\s().-]+$/D', $value) || strlen($value) > 64) {
            throw new InvalidArgumentException('Telefone inválido.');
        }

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($value, 'BR');
        } catch (NumberParseException $exception) {
            throw new InvalidArgumentException('Telefone inválido.', previous: $exception);
        }

        if (! $util->isValidNumber($number) || $number->hasExtension()) {
            throw new InvalidArgumentException('Telefone inválido.');
        }

        $formatted = $util->format($number, PhoneNumberFormat::E164);
        if (! preg_match('/^\+[1-9]\d{1,14}$/D', $formatted)) {
            throw new InvalidArgumentException('Telefone fora do padrão E.164.');
        }

        return $formatted;
    }
}
