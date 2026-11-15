<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref, useId } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useSidebar } from '@/components/ui/sidebar/utils';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
    label?: string;
}>();

const { isCurrentUrl } = useCurrentUrl();
const { isMobile, state } = useSidebar();
const open = ref(true);
const iconMode = computed(() => !isMobile.value && state.value === 'collapsed');
const menuId = useId();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel v-if="label && !iconMode" as-child>
            <button
                type="button"
                class="w-full justify-between hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                :aria-expanded="open"
                :aria-controls="menuId"
                @click="open = !open"
            >
                {{ label }}
                <ChevronDown
                    aria-hidden="true"
                    class="transition-transform"
                    :class="{ '-rotate-90': !open }"
                />
            </button>
        </SidebarGroupLabel>
        <SidebarMenu :id="menuId" v-show="!label || open || iconMode">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" aria-hidden="true" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
