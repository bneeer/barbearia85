export type PageLink = { url: string | null; label: string; active: boolean };
export type Page<T> = {
    data: T[];
    links: PageLink[];
    total: number;
};
export type Admin = { id: number; name: string };
export type ClientSummary = {
    id: number;
    name: string;
    email: string | null;
    phone: string;
    unit: string | null;
    archived: boolean;
};
export type ClientMatch = ClientSummary & {
    email_match: boolean;
    phone_match: boolean;
};
export type RequestSummary = {
    id: number;
    protocol: string;
    type: string;
    status: string;
    due_date: string;
    assigned_to: string | null;
    overdue: boolean;
};
export const requestTypes: Record<string, string> = {
    full_access: 'Confirmação/acesso — declaração completa',
    simple_access: 'Confirmação/acesso — formato simplificado',
    correction: 'Correção',
    deletion: 'Exclusão',
    other: 'Outro direito',
};
export const channels: Record<string, string> = {
    phone: 'Telefone',
    email: 'E-mail',
    in_person: 'Presencial',
    other: 'Outro canal',
};
export const results: Record<string, string> = {
    fulfilled: 'Atendida',
    partial: 'Parcial',
    denied: 'Negada',
    impossible: 'Impossível',
};
