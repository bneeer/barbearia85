import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type User = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'barber';
};

type PageProps = {
    auth?: {
        user?: User;
    };
};

export function useAuth() {
    const page = usePage<PageProps>();

    const user = computed(() => page.props.auth?.user ?? null);

    const isAdmin = computed(() => user.value?.role === 'admin');

    const isBarber = computed(() =>
        ['admin', 'barber'].includes(user.value?.role ?? ''),
    );

    return {
        user,
        isAdmin,
        isBarber,
    };
}
