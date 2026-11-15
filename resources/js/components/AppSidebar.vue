<script setup lang="ts">
import { Group, Home, Scissor, Shop, Coins } from '@iconoir/vue';
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    ClipboardList,
    Boxes,
    CircleDollarSign,
    Mail,
    Package,
    Users,
} from 'lucide-vue-next';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAuth } from '@/composables/useAuth';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const { isAdmin, isBarber } = useAuth();

const standaloneNavItems: NavItem[] = [
    {
        title: 'Painel Geral',
        href: dashboard(),
        icon: Home,
    },
    {
        title: 'Agendamentos',
        href: '/schedule',
        icon: CalendarDays,
    },
];

const operationNavItems: NavItem[] = [
    {
        title: 'Barbearias',
        href: '/barbershops',
        icon: Shop,
        requiresAdmin: true,
    },
    {
        title: 'Barbeiros',
        href: '/barbers',
        icon: Group,
        requiresAdmin: true,
    },
    {
        title: 'Serviços',
        href: '/services',
        icon: Scissor,
        requiresAdmin: true,
    },
    {
        title: 'Produtos',
        href: '/products',
        icon: Package,
        requiresAdmin: true,
    },
    {
        title: 'Estoque',
        href: '/inventory',
        icon: Boxes,
        requiresAdmin: true,
    },
    {
        title: 'Clientes',
        href: '/clients',
        icon: Users,
        requiresAdmin: true,
    },
];

const managementNavItems: NavItem[] = [
    {
        title: 'Despesas e Faturamento',
        href: '/finance',
        icon: CircleDollarSign,
    },
    {
        title: 'Caixa',
        href: '/cash-register',
        icon: Coins,
        requiresAdmin: true,
    },
];

const bottomNavItems: NavItem[] = [
    {
        title: 'Notificações',
        href: '/notifications',
        icon: Mail,
        requiresAdmin: true,
    },
    {
        title: 'Solicitações LGPD',
        href: '/privacy-requests',
        icon: ClipboardList,
        requiresAdmin: true,
    },
];

const filterNavItems = (items: NavItem[]) =>
    items.filter((item) => {
        if (item.href === '/finance') {
            return isBarber.value;
        }

        if (item.requiresAdmin) {
            return isAdmin.value;
        }

        return true;
    });

const filteredStandaloneNavItems = filterNavItems(standaloneNavItems);
const filteredOperationNavItems = filterNavItems(operationNavItems);
const filteredManagementNavItems = filterNavItems(managementNavItems);
const filteredBottomNavItems = filterNavItems(bottomNavItems);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="filteredStandaloneNavItems" />
            <NavMain
                v-if="filteredOperationNavItems.length"
                label="Operação"
                :items="filteredOperationNavItems"
            />
            <NavMain
                v-if="filteredManagementNavItems.length"
                label="Contabilidade"
                :items="filteredManagementNavItems"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavMain
                v-if="filteredBottomNavItems.length"
                label="Outros"
                :items="filteredBottomNavItems"
            />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
