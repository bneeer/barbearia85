import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatPhone, parsePhone } from '../../resources/js/lib/phone.ts';

test('normalizes international, national, non-geographic and short country plans', () => {
    for (const [input, country, expected] of [
        ['(11) 99999-9999', 'BR', '+5511999999999'],
        ['(202) 555-0123', 'US', '+12025550123'],
        ['020 7946 0018', 'GB', '+442079460018'],
        ['912 345 678', 'PT', '+351912345678'],
        ['+91 98765 43210', 'BR', '+919876543210'],
        ['00351 912 345 678', 'BR', '+351912345678'],
        ['+800 1234 5678', null, '+80012345678'],
        ['+290 21234', 'BR', '+29021234'],
        ['+39 02 3661 8300', 'BR', '+390236618300'],
        ['+49 30 12345678901', 'BR', '+493012345678901'],
    ]) {
        assert.equal(parsePhone(input, country)?.number, expected);
        assert.equal(parsePhone(expected)?.number, expected);
    }
});

test('formats while typing according to the selected country', () => {
    assert.equal(formatPhone('11999999999'), '(11) 99999-9999');
    assert.equal(formatPhone('2025550123', 'US'), '(202) 555-0123');
    assert.equal(formatPhone('02079460018', 'GB'), '020 7946 0018');
    assert.equal(formatPhone('+351912345678'), '+351 912 345 678');
    assert.equal(formatPhone('invalid'), 'invalid');
    assert.equal(formatPhone('(())------'), '(())------');
    assert.equal(formatPhone('+'), '+');
    assert.equal(formatPhone(''), '');
});

test('rejects invalid numbers without silently removing extensions or text', () => {
    for (const input of [
        '',
        '123',
        '+999123456789',
        '(())------',
        '+1202555012345678',
        '+1 202 555 0123 ext 123',
        '+1 800 FLOWERS',
        'Tel: +5511999999999',
        '+5511999999999#123',
        '+4930123456789012',
        '1'.repeat(65),
    ]) {
        assert.equal(parsePhone(input), undefined, input);
    }

    assert.equal(parsePhone('11999999999', null), undefined);
});
