<script setup lang="ts">
import { Group, Home, Scissor, Shop } from '@iconoir/vue';
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Mail, Package } from 'lucide-vue-next';
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

const { isAdmin } = useAuth();

const mainNavItems: NavItem[] = [
    {
        title: 'Início',
        href: dashboard(),
        icon: Home,
    },
    {
        title: 'Agendamentos',
        href: '/schedule',
        icon: CalendarDays,
    },
    {
        title: 'Barbeiros',
        href: '/barbers',
        icon: Group,
        requiresAdmin: true,
    },
    {
        title: 'Barbearias',
        href: '/barbershops',
        icon: Shop,
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
        title: 'Notificações',
        href: '/notifications',
        icon: Mail,
        requiresAdmin: true,
    },
];

const filteredNavItems = mainNavItems.filter((item) => {
    if (item.requiresAdmin) {
        return isAdmin.value;
    }

    return true;
});
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
            <NavMain :items="filteredNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
