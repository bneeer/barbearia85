export type Service = {
    id: number;
    name: string;
    description: string | null;
    price: string;
    duration: number;
    is_active: boolean;
    commission_percent: string;
    supplies?: {
        id: number;
        name: string;
        unit: string;
        pivot: { quantity: string };
    }[];
};

export type SupplyOption = { id: number; name: string; unit: string };
