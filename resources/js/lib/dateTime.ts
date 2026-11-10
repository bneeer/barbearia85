export const DISPLAY_TIMEZONE = 'America/Sao_Paulo';

export function formatDateTime(value: string) {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: DISPLAY_TIMEZONE,
    }).format(new Date(value));
}
