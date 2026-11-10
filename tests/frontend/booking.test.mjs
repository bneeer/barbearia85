import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    endTime,
    overlapsDraft,
    reconcileDrafts,
} from '../../resources/js/lib/booking.ts';

test('preserves defined items when adding or removing unrelated services', () => {
    const [first, second] = reconcileDrafts([1, 2], []);
    Object.assign(first, {
        preference: 'any',
        barber_id: 7,
        date: '2026-11-10',
        time: '09:00',
    });
    const expanded = reconcileDrafts([2, 1, 3], [first, second]);
    assert.equal(expanded[0], second);
    assert.equal(expanded[1], first);
    assert.equal(expanded[1].barber_id, 7);
    assert.equal(expanded[2].preference, null);
    assert.deepEqual(reconcileDrafts([1], expanded), [first]);
    assert.deepEqual(reconcileDrafts([], expanded), []);
    assert.notEqual(reconcileDrafts([2], [first])[0], second);
});

test('rejects full and partial overlaps using each services own duration', () => {
    const first = { date: '2026-11-10', time: '09:00' };

    for (const [time, duration, expected] of [
        ['09:00', 40, true],
        ['09:40', 40, true],
        ['08:20', 80, true],
        ['08:20', 40, false],
        ['10:20', 60, false],
        ['11:00', 60, false],
    ]) {
        assert.equal(
            overlapsDraft(first, 80, { date: first.date, time }, duration),
            expected,
            time,
        );
    }

    assert.equal(
        overlapsDraft(first, 80, { date: '2026-11-11', time: '09:00' }, 40),
        false,
    );
    assert.equal(
        overlapsDraft(first, 80, { date: first.date, time: '' }, 40),
        false,
    );
    assert.equal(endTime('09:40', 60), '10:40');
    assert.equal(endTime('18:20', 100), '20:00');
});
