<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui';

defineProps<{ id: string }>();
const checked = defineModel<boolean>({ default: false });
</script>

<template>
    <SwitchRoot
        :id="id"
        v-model="checked"
        class="inline-flex h-6 w-11 shrink-0 items-center rounded-full border-2 border-transparent bg-input transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none data-[state=checked]:bg-primary"
    >
        <SwitchThumb
            class="pointer-events-none block size-5 translate-x-0 rounded-full bg-background shadow transition-transform data-[state=checked]:translate-x-5"
        />
    </SwitchRoot>
</template>
