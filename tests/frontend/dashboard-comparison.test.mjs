import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatDashboardComparison } from '../../resources/js/lib/dashboardComparison.ts';

test('formats relative changes in counts and cents with one decimal', () => {
    assert.equal(formatDashboardComparison(15, 10), '+50,0%');
    assert.equal(formatDashboardComparison(5, 10), '-50,0%');
    assert.equal(formatDashboardComparison(0, 10), '-100,0%');
    assert.equal(formatDashboardComparison(10000, 7500), '+33,3%');
    assert.equal(formatDashboardComparison(2, 3), '-33,3%');
    assert.equal(formatDashboardComparison(10, 10), 'Sem alteração');
});

test('handles zero baselines without misleading percentages', () => {
    assert.equal(formatDashboardComparison(1, 0), 'Sem base de comparação');
    assert.equal(formatDashboardComparison(0, 0), 'Sem alteração');
});

test('does not convert missing or invalid values to zero', () => {
    for (const missing of [null, undefined, NaN, Infinity, -Infinity]) {
        for (const mode of ['percent', 'points']) {
            assert.equal(
                formatDashboardComparison(missing, 10, mode),
                'Comparação indisponível',
            );
            assert.equal(
                formatDashboardComparison(10, missing, mode),
                'Comparação indisponível',
            );
        }
    }
});

test('compares occupancy in percentage points, including a zero baseline', () => {
    assert.equal(formatDashboardComparison(60, 40, 'points'), '+20,0 p.p.');
    assert.equal(formatDashboardComparison(40, 60, 'points'), '-20,0 p.p.');
    assert.equal(formatDashboardComparison(10, 0, 'points'), '+10,0 p.p.');
    assert.equal(formatDashboardComparison(0, 10, 'points'), '-10,0 p.p.');
    assert.equal(formatDashboardComparison(0, 0, 'points'), 'Sem alteração');
    assert.equal(formatDashboardComparison(50, 50, 'points'), 'Sem alteração');
});

test('rounds consistently without a signed zero', () => {
    assert.equal(formatDashboardComparison(9999, 10000), '0,0%');
    assert.equal(formatDashboardComparison(10001, 10000), '0,0%');
    assert.equal(formatDashboardComparison(50, 50.01, 'points'), '0,0 p.p.');
    assert.equal(formatDashboardComparison(50.04, 50, 'points'), '0,0 p.p.');
});
