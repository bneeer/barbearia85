<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ClientMatch } from '@/types/privacy';

defineProps<{ matches: ClientMatch[] }>();
</script>

<template>
    <div class="space-y-2">
        <p class="text-sm text-muted-foreground">
            Possíveis correspondências por e-mail ou telefone exato. Confirme a
            identidade antes de vincular: contatos compartilhados não provam que
            são a mesma pessoa. Outros dados e sistemas exigem busca adicional.
        </p>
        <ul v-if="matches.length" class="space-y-2">
            <li
                v-for="match in matches.slice(0, 50)"
                :key="match.id"
                class="rounded border p-3 text-sm"
            >
                <Link
                    :href="`/clients/${match.id}`"
                    class="font-medium text-primary underline"
                >
                    {{ match.name }} ({{
                        match.unit ?? 'Unidade indisponível'
                    }})
                </Link>
                <span v-if="match.archived"> — cadastro arquivado</span>
                <p>{{ match.email ?? 'Sem e-mail' }} · {{ match.phone }}</p>
                <p>
                    Coincidência:
                    {{
                        [
                            match.email_match ? 'e-mail' : '',
                            match.phone_match ? 'telefone' : '',
                        ]
                            .filter(Boolean)
                            .join(' e ')
                    }}
                </p>
            </li>
        </ul>
        <p v-else class="text-sm">Nenhuma correspondência exata encontrada.</p>
        <p v-if="matches.length > 50" class="text-sm">
            Há mais resultados; refine a busca de clientes.
        </p>
    </div>
</template>
