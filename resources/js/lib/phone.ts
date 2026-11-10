import type { CountryCode } from 'libphonenumber-js';
import { AsYouType, parsePhoneNumberFromString } from 'libphonenumber-js/max';

export function internationalPrefix(value: string): string {
    return value.trim().replace(/^00/, '+');
}

export function parsePhone(value: string, country: CountryCode | null = 'BR') {
    const input = internationalPrefix(value);

    if (input.length > 64 || !/^\+?[\d\s().-]+$/.test(input)) {
        return undefined;
    }

    const phone = parsePhoneNumberFromString(input, {
        defaultCountry: country ?? undefined,
        extract: false,
    });

    return phone?.isValid() &&
        !phone.ext &&
        /^\+[1-9]\d{1,14}$/.test(phone.number)
        ? phone
        : undefined;
}

export function formatPhone(
    value: string,
    country: CountryCode | null = 'BR',
): string {
    const input = internationalPrefix(value);

    if (!/^\+?[\d\s().-]*$/.test(input) || (input && !/\d/.test(input))) {
        return value;
    }

    return new AsYouType(country ?? undefined).input(input);
}
