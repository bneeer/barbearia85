export type ComparisonMode = 'percent' | 'points';

const decimal = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
    signDisplay: 'exceptZero',
});

export function formatDashboardComparison(
    current: number | null | undefined,
    previous: number | null | undefined,
    mode: ComparisonMode = 'percent',
): string {
    if (
        current === null ||
        current === undefined ||
        previous === null ||
        previous === undefined ||
        !Number.isFinite(current) ||
        !Number.isFinite(previous)
    ) {
        return 'Comparação indisponível';
    }

    if (current === previous) {
        return 'Sem alteração';
    }

    if (mode === 'percent' && previous === 0) {
        return 'Sem base de comparação';
    }

    const change =
        mode === 'points'
            ? current - previous
            : ((current - previous) / previous) * 100;
    const rounded =
        (Math.round(Math.abs(change) * 10) / 10) * Math.sign(change);

    return `${decimal.format(rounded === 0 ? 0 : rounded)}${mode === 'points' ? ' p.p.' : '%'}`;
}
