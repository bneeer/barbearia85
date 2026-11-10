export type CompletedAppointment = {
    id: number;
    unit: string | null;
    timezone: string;
    barber: string | null;
    client: { name: string; email: string | null; phone: string | null } | null;
    start_time: string;
    end_time: string;
    notes: string | null;
    services: { name: string; duration: number; price_cents?: number }[];
    checkout: {
        products: {
            name: string;
            brand: string;
            quantity: number;
            price_cents: number;
        }[];
        services_total_cents: number;
        products_total_cents: number;
        discount_cents: number;
        total_cents: number;
        payment_method: string;
        notes: string | null;
        created_at: string;
    } | null;
};

export type AppointmentPage = {
    data: CompletedAppointment[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

export type AppointmentFilters = Record<string, string | number | null>;

export type DashboardPeriod = 'day' | 'week' | 'month';

export type ServicesTimeline = {
    timezone: string;
    dates: string[];
    future_dates: string[];
    series: {
        id: number;
        name: string;
        counts: number[];
        scheduled_counts: number[];
        revenue_cents?: number | null;
    }[];
};

export type PeriodIndicator = {
    period: DashboardPeriod;
    completed: number;
    scheduled: number;
    total: number;
    financials?: {
        completed_cents: number;
        scheduled_cents: number;
        total_cents: number;
        missing_completed: number;
        missing_scheduled: number;
    };
};

export type BarberIndicator = {
    id: number;
    name: string;
    avatar: string | null;
    periods: PeriodIndicator[];
};

export type DashboardIndicators = {
    reference_at: string;
    periods: PeriodIndicator[];
    barbers: BarberIndicator[];
};

export type DashboardFinancialSummary = {
    checkout_count: number;
    average_ticket_cents: number;
    discount_cents: number;
    payment_methods: Record<string, { count: number; total_cents: number }>;
};

export type DashboardRangeMetrics = {
    completed: number;
    scheduled: number;
    cancelled: number;
    no_show: number;
    total: number;
    retention: {
        unique_clients: number;
        new_clients: number;
        returning_clients: number;
    };
    financials?: DashboardFinancialSummary;
    capacity: {
        available_minutes: number;
        occupied_minutes: number;
        free_minutes: number;
        occupancy_percent: number;
        barbers: {
            id: number;
            name: string;
            available_minutes: number;
            occupied_minutes: number;
            free_minutes: number;
            occupancy_percent: number;
        }[];
    } | null;
};

export type DashboardSummary = {
    from: string;
    to: string;
    previous_from: string;
    previous_to: string;
    current: DashboardRangeMetrics & {
        barbers?: Record<
            number,
            {
                completed: number;
                scheduled: number;
                cancelled: number;
                no_show: number;
                financials: DashboardFinancialSummary;
            }
        >;
    };
    previous: DashboardRangeMetrics & {
        barbers?: Record<
            number,
            {
                completed: number;
                scheduled: number;
                cancelled: number;
                no_show: number;
                financials: DashboardFinancialSummary;
            }
        >;
    };
    barbers: {
        id: number;
        name: string;
        avatar: string | null;
        current: {
            completed: number;
            scheduled: number;
            cancelled: number;
            no_show: number;
            financials: DashboardFinancialSummary;
        };
        previous: {
            completed: number;
            scheduled: number;
            cancelled: number;
            no_show: number;
            financials: DashboardFinancialSummary;
        };
    }[];
};

export type ScheduledAppointmentPage = Omit<AppointmentPage, 'data'> & {
    data: {
        id: number;
        unit: string;
        timezone: string;
        barber: string | null;
        client: { name: string; phone: string | null } | null;
        start_time: string;
        end_time: string;
        services: string[];
        expected_cents?: number | null;
    }[];
};
