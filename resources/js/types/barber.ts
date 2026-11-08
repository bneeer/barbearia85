export type BarberService = {
    id: number;
    name: string;
    is_active: boolean;
};

export type Barber = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    avatar: string | null;
    barbershop_id: number | null;
    barbershops: { id: number; name: string }[];
    services: BarberService[];
};
