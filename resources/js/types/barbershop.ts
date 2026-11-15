export type OpeningDay = {
    open: string;
    close: string;
    closed: boolean;
};

export type OpeningHours = Record<
    | 'monday'
    | 'tuesday'
    | 'wednesday'
    | 'thursday'
    | 'friday'
    | 'saturday'
    | 'sunday',
    OpeningDay
>;

export type BarbershopBarber = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
};

export type BarbershopPhoto = {
    id: number;
    url: string;
};

export type Barbershop = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    address: string | null;
    zip_code: string | null;
    phones: string[] | null;
    opening_hours: OpeningHours | null;
    is_open: boolean;
    cancellation_notice_hours: number;
    no_show_tolerance_minutes: number;
    loyalty_enabled: boolean;
    loyalty_stamp_goal: number;
    loyalty_reward_description: string | null;
    users_count?: number;
    users?: BarbershopBarber[];
    photos?: BarbershopPhoto[];
};
