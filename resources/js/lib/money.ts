const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

export function formatMoney(cents: number | null | undefined): string {
    return cents === null || cents === undefined
        ? 'Não registrado'
        : currency.format(cents / 100);
}
