<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class InternationalPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (! is_string($value)) {
                throw new InvalidArgumentException;
            }
            PhoneNumber::normalize($value);
        } catch (InvalidArgumentException) {
            $fail('Informe um telefone válido com código do país e DDD, sem ramal.');
        }
    }
}
