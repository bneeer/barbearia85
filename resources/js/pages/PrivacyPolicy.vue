<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PublicFooter from '@/components/PublicFooter.vue';

defineProps<{
    barbershops: { name: string; phones: string[] }[];
}>();
</script>

<template>
    <Head title="Política de Privacidade" />
    <div class="flex min-h-dvh flex-col bg-background text-foreground">
        <header class="mx-auto w-full max-w-3xl px-5 pt-8">
            <Link href="/" class="text-sm underline underline-offset-4">
                Voltar ao início
            </Link>
        </header>
        <main class="mx-auto w-full max-w-3xl flex-1 space-y-8 px-5 py-10">
            <div class="space-y-4">
                <h1 class="text-3xl font-bold">Política de Privacidade</h1>
                <p class="rounded-lg border p-4 text-sm">
                    Este é um modelo em revisão. As informações indicadas como
                    pendentes devem ser confirmadas pelos responsáveis pela
                    barbearia antes da publicação definitiva.
                </p>
            </div>

            <section class="space-y-3">
                <h2 class="text-xl font-semibold">
                    Quais dados usamos e por quê
                </h2>
                <p>
                    Ao agendar, você informa nome, e-mail e telefone.
                    Registramos também unidade, serviço, profissional, data,
                    horário e situação do atendimento para administrar a agenda,
                    prestar o serviço e comunicar alterações ou cancelamentos.
                </p>
                <p>
                    Podemos registrar observações sobre o atendimento e dados do
                    fechamento, como serviços, produtos, valores, descontos e
                    forma de pagamento, para gestão e atendimento de obrigações
                    aplicáveis. O sistema registra o fechamento, mas não
                    processa pagamentos.
                </p>
                <p>
                    A aplicação também trata dados de contas da equipe, sessões
                    e informações técnicas e de notificações para permitir o
                    acesso, acompanhar envios e falhas e apoiar a segurança.
                    Cookies de sessão são usados no funcionamento do site.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-semibold">Acesso e compartilhamento</h2>
                <p>
                    Administradores e profissionais autorizados acessam os dados
                    necessários às suas funções. A operação pode envolver
                    fornecedores de hospedagem, armazenamento e envio de e-mail,
                    conforme os serviços contratados. Caso você escolha
                    adicionar uma reserva a um calendário externo, os dados
                    compartilhados dependerão do serviço escolhido.
                </p>
                <p>
                    Pendente de confirmação: identidade dos responsáveis e
                    fornecedores, locais de armazenamento e eventual
                    transferência internacional de dados, incluindo as
                    salvaguardas aplicáveis.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-semibold">
                    Por quanto tempo guardamos
                </h2>
                <p>
                    Os períodos de retenção devem considerar a finalidade de
                    cada registro, obrigações legais ou regulatórias e outras
                    hipóteses aplicáveis. Alguns dados podem precisar ser
                    mantidos após o atendimento ou uma solicitação de exclusão.
                    A eliminação de cópias de segurança depende da política de
                    rotação adotada.
                </p>
                <p>
                    Pendente de definição: prazos por categoria, política de
                    backups e procedimentos de exclusão. As bases legais de cada
                    tratamento também serão avaliadas; elas não dependem
                    necessariamente de consentimento.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-semibold">Seus direitos e contato</h2>
                <p>
                    Você pode solicitar confirmação do tratamento, acesso,
                    correção, anonimização, bloqueio ou exclusão de dados,
                    portabilidade e informações sobre compartilhamento, entre
                    outros direitos previstos na LGPD, observadas as condições e
                    exceções legais. Quando o tratamento se basear em
                    consentimento, você poderá solicitar sua revogação.
                </p>
                <p>
                    Para encaminhar uma solicitação, entre em contato com sua
                    unidade pelos telefones disponíveis abaixo. O canal
                    específico de privacidade e as informações sobre o
                    encarregado, quando aplicável, ainda estão pendentes de
                    definição.
                </p>
                <ul v-if="barbershops.length" class="space-y-2">
                    <li
                        v-for="(shop, shopIndex) in barbershops"
                        :key="shopIndex"
                    >
                        <span class="font-medium">{{ shop.name }}: </span>
                        <span v-if="shop.phones.length">
                            <span
                                v-for="(phone, index) in shop.phones"
                                :key="phone"
                            >
                                <span v-if="index"> · </span>
                                <a
                                    :href="`tel:${phone.replace(/[^\d+]/g, '')}`"
                                    class="break-all underline underline-offset-4"
                                    >{{ phone }}</a
                                >
                            </span>
                        </span>
                        <span v-else
                            >telefone não informado; canal pendente de
                            definição.</span
                        >
                    </li>
                </ul>
                <p v-else>
                    Não há contato de unidade disponível no momento. O canal
                    para solicitações está pendente de definição.
                </p>
            </section>

            <section class="space-y-3">
                <h2 class="text-xl font-semibold">Segurança e atualizações</h2>
                <p>
                    O sistema utiliza controles de acesso por perfil e protege
                    determinados dados de notificações e links de cancelamento.
                    As medidas operacionais e dos fornecedores devem ser
                    confirmadas pelos responsáveis.
                </p>
                <p>
                    Esta política deve ser atualizada quando os tratamentos,
                    fornecedores ou práticas mudarem.
                </p>
            </section>
        </main>
        <PublicFooter />
    </div>
</template>
