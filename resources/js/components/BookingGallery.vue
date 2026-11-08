<script setup lang="ts">
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{ photos: { url: string }[]; name: string }>();
const index = ref(0);
const expanded = ref(false);

function move(direction: number) {
    index.value =
        (index.value + direction + props.photos.length) % props.photos.length;
}
</script>

<template>
    <div v-if="photos.length" class="relative">
        <button
            type="button"
            class="block w-full overflow-hidden rounded-xl focus-visible:outline-2 focus-visible:outline-primary"
            :aria-label="`Ampliar fotos da unidade ${name}`"
            @click="expanded = true"
        >
            <img
                :src="photos[index].url"
                :alt="`${name} — foto ${index + 1}`"
                class="h-48 w-full object-cover"
                loading="lazy"
            />
            <span
                class="absolute right-3 bottom-3 rounded-full bg-black/75 px-3 py-1 text-xs text-white"
                >Ver fotos · {{ index + 1 }}/{{ photos.length }}</span
            >
        </button>
        <div
            v-if="photos.length > 1"
            class="mt-2 flex items-center justify-between gap-3"
        >
            <button
                type="button"
                class="rounded-lg border px-3 py-1 hover:bg-accent"
                aria-label="Foto anterior"
                @click="move(-1)"
            >
                ←
            </button>
            <span class="text-xs text-muted-foreground" aria-live="polite"
                >{{ index + 1 }} de {{ photos.length }}</span
            >
            <button
                type="button"
                class="rounded-lg border px-3 py-1 hover:bg-accent"
                aria-label="Próxima foto"
                @click="move(1)"
            >
                →
            </button>
        </div>
        <Dialog v-model:open="expanded">
            <DialogContent class="sm:max-w-4xl">
                <DialogTitle>{{ name }}</DialogTitle>
                <DialogDescription
                    >Conheça o espaço. Use as setas para navegar pelas
                    fotos.</DialogDescription
                >
                <div
                    tabindex="0"
                    aria-label="Galeria de fotos"
                    @keydown.left.prevent="move(-1)"
                    @keydown.right.prevent="move(1)"
                >
                    <img
                        :src="photos[index].url"
                        :alt="`${name} — foto ${index + 1}`"
                        class="max-h-[65dvh] w-full rounded-lg object-contain"
                    />
                    <div class="mt-4 flex items-center justify-between">
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 hover:bg-accent"
                            :disabled="photos.length < 2"
                            aria-label="Foto anterior"
                            @click="move(-1)"
                        >
                            ← Anterior
                        </button>
                        <span aria-live="polite"
                            >{{ index + 1 }} / {{ photos.length }}</span
                        >
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 hover:bg-accent"
                            :disabled="photos.length < 2"
                            aria-label="Próxima foto"
                            @click="move(1)"
                        >
                            Próxima →
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
    <div
        v-else
        class="flex h-48 items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground"
    >
        Fotos em breve
    </div>
</template>
