# Barbearia 85

Aplicação web para apresentar a Barbearia 85 e permitir que clientes agendem serviços nas unidades disponíveis. A área administrativa autenticada reúne o gerenciamento das unidades, barbeiros, serviços e produtos.

## Recursos

- Página inicial pública com apresentação da barbearia e galeria de fotos.
- Agendamento público com escolha de unidade e um ou mais serviços. Cada serviço tem seu próprio barbeiro e data/horário, inclusive em dias diferentes, sem exigir um profissional que realize todos os serviços. A opção sem preferência consulta os profissionais habilitados para cada serviço.
- Consulta de horários considerando a duração individual do serviço, expediente, horários de trabalho dos barbeiros, bloqueios e agendamentos existentes, inclusive em outras unidades. Os serviços do mesmo pedido não podem se sobrepor para o cliente; horários consecutivos são permitidos.
- Confirmação pública atômica: todos os atendimentos são revalidados e registrados juntos, com um único cadastro de cliente. Se qualquer item não estiver disponível, nenhum é criado. O resumo permite editar cada item sem perder os demais; cada reserva confirmada possui seu próprio evento para Google Calendar, Outlook ou Apple Calendar.
- Agendamentos combinados anteriores e a criação administrativa de vários serviços no mesmo atendimento continuam usando um único barbeiro e a duração somada. As novas reservas públicas podem ser canceladas, reagendadas e finalizadas individualmente na agenda.
- Cadastro do cliente e criação do agendamento com outbox de notificações por e-mail via SMTP, retentativas e acompanhamento de falhas.
- Área administrativa para gerenciar unidades, horários e equipes, barbeiros, serviços e fotos das unidades.
- Dashboard (`/dashboard`) com filtros globais por intervalo e unidade aplicados aos indicadores, gráfico e atendimentos concluídos. O padrão é do primeiro dia do mês atual até hoje; há atalhos para hoje, semana (segunda a domingo) e mês, além de intervalo personalizado de até 365 dias. Os indicadores comparam o intervalo selecionado ao intervalo imediatamente anterior de igual duração. O calendário do dashboard usa `app.display_timezone` (por padrão `America/Sao_Paulo`), mantendo armazenamento e serialização dos instantes em UTC.
- O resumo distingue concluídos, agendados, cancelados e faltas; cancelamentos e faltas não entram na ocupação. A ocupação soma minutos concluídos e agendados sobre minutos de expediente disponíveis, descontando bloqueios, e mostra a capacidade livre em minutos. Como os horários cadastrados são globais por barbeiro, a ocupação é consolidada por barbeiro entre unidades e não é apresentada quando uma unidade específica está selecionada.
- Indicadores de clientes únicos, novos e recorrentes: recorrentes já possuíam um atendimento concluído anterior ao início do intervalo. Administradores consultam o conjunto global ou a unidade filtrada; barbeiros veem somente seus próprios atendimentos e o histórico de recorrência correspondente.
- Resumo financeiro administrativo com ticket médio, descontos e distribuição por forma de pagamento, usando somente fechamentos registrados. Esses valores não confirmam processamento nem recebimento. O switch “Exibir valores financeiros” controla sua apresentação, os valores da tabela, indicadores por barbeiro e previsões no pop-up; informações financeiras não são enviadas a barbeiros e filtros financeiros continuam proibidos para esse perfil.
- Cards por barbeiro mostram concluídos, agendados, cancelados e faltas no intervalo selecionado. Clicar em concluídos filtra a tabela pelo período e pelo ID exato do profissional; clicar em agendados abre uma consulta rápida paginada. O pop-up também respeita intervalo, unidade e barbeiro.
- Tabela paginada no servidor (10, 25, 50 ou 100 registros), com filtros combináveis por coluna, faixas de valores em reais para administradores, snapshots do fechamento e registros antigos claramente identificados como sem fechamento. A exportação XLSX inclui todos os atendimentos concluídos que correspondem aos filtros ativos, independentemente da página atual, e acrescenta colunas financeiras apenas para administradores.
- Administradores consultam todas as unidades. Barbeiros consultam somente atendimentos atribuídos ao próprio usuário em todas as unidades às quais têm acesso; dados de colegas, fotos de colegas e valores financeiros não são consultados ou enviados. Outros perfis mantêm a página de boas-vindas.
- Seção de Produtos (`/products`) para administradores cadastrarem, consultarem, editarem e excluírem produtos da própria unidade, com nome, marca, descrição e preço em reais. Inclui busca por nome ou marca e listagem paginada.
- Calendário de agendamentos (`/schedule`) com seleção de unidades, filtro por barbeiro, visualizações de mês/semana/dia/lista e detalhes em dialog.
- Administradores consultam todas as unidades e podem cancelar ou reagendar reservas por arraste; barbeiros consultam somente suas reservas nas unidades associadas.
- Administradores finalizam atendimentos no calendário, incluindo produtos da unidade, desconto, forma de pagamento presencial e observações opcionais. O fechamento preserva os itens, valores e observações para consulta; não processa nem confirma pagamentos. Atendimentos concluídos ficam em azul-escuro e não podem ser editados.
- A finalização fica disponível somente a partir do horário de início do agendamento. Para reservas futuras, o botão não é exibido e o servidor também impede o fechamento.
- Novos agendamentos exigem data e horário futuros no fuso da unidade, tanto na área administrativa quanto na tela pública. Faixas passadas no calendário administrativo não permitem criar reservas; o histórico permanece disponível para consulta.
- Administradores também podem cadastrar agendamentos recebidos por outros canais, informando cliente, barbeiro, serviços, data e período; os horários são consultados novamente e validados antes da confirmação.
- Ao selecionar uma faixa no calendário, administradores escolhem entre criar um agendamento ou um bloqueio. Agendamentos precisam caber na faixa selecionada, mas ocupam na agenda somente a duração somada dos serviços selecionados.
- Bloqueios por intervalo ou dia inteiro: administradores gerenciam toda a equipe e barbeiros apenas a própria agenda. Bloqueios são globais por profissional e não podem atingir reservas existentes, inclusive em outra unidade.
- O calendário impede selecionar períodos passados e mover eventos para o passado. O servidor também rejeita criar ou reagendar bloqueios para períodos passados. “Fechar o dia” para hoje bloqueia somente de agora até o fim do dia, no fuso da unidade.
- Reagendamentos respeitam o expediente da unidade e do barbeiro, a duração somada dos serviços e os conflitos de agenda. A grade pública de 40 minutos permanece inalterada; ajustes administrativos podem usar outros horários.
- Autenticação, verificação de e-mail, recuperação de senha e configurações de perfil e segurança.
- Telefones internacionais em todos os formulários, com seleção de país, formatação durante a digitação e validação no frontend e no servidor. Clientes, barbeiros, unidades e novos destinatários de notificações são gravados em E.164 (por exemplo, `+5511999999999`), sem máscara ou ramal. O país inicial é Brasil; números de outros países devem ser selecionados no campo ou informados com `+` e código internacional. A cobertura segue os planos de numeração do libphonenumber, incluindo números não geográficos na opção “Internacional (+)”. A validade do formato não comprova que a linha existe ou recebe WhatsApp.

## Tecnologias

O gráfico do dashboard mostra, para o intervalo e unidade selecionados, serviços concluídos (linha contínua) e agendados (tracejada), com dias sem movimento zerados, tooltips, legenda interativa e tabela acessível alternativa. Conta cada serviço uma vez por atendimento, preservando os itens do fechamento e usando o cadastro apenas para registros antigos sem fechamento. Clicar em um ponto de concluídos filtra a tabela pelo serviço e dia, preservando os filtros globais. O gráfico respeita o acesso do perfil: dados globais para administradores e somente os próprios atendimentos para barbeiros.

Abaixo do gráfico, os destaques mostram os serviços mais e menos prestados entre os que tiveram atendimentos concluídos no intervalo e unidade selecionados, incluindo todos os empates. Administradores, ao ativar “Exibir valores financeiros”, também veem “Maior faturamento” e “Menor faturamento”, pelo faturamento total dos serviços nos fechamentos (antes do desconto geral, sem produtos e sem representar lucro líquido). Serviços com algum atendimento sem fechamento ficam fora do ranking financeiro, com aviso de dados incompletos; preços atuais do cadastro não substituem valores históricos. Filtros exclusivos da tabela e paginação não alteram esses destaques.

As comparações existentes no resumo geral e por barbeiro mantêm os valores anteriores e acrescentam a variação percentual com uma casa decimal, calculada sobre o valor anterior. A ocupação usa diferença em pontos percentuais (`p.p.`). Base anterior zero com valor atual positivo mostra “Sem base de comparação”; valores iguais, inclusive ambos zero, mostram “Sem alteração”; dados ausentes mostram “Comparação indisponível”. A apresentação é neutra, sem classificar aumentos ou quedas como bons ou ruins. Os destaques de serviços e os valores financeiros individuais que não tinham comparação anterior permanecem sem variação.

Administradores também consultam barras horizontais de atendimentos concluídos e agendados por barbeiro, ordenadas pelo total e limitadas aos profissionais com movimento no período e unidade selecionados. Com “Exibir valores financeiros” ativado, um gráfico de rosca apresenta o faturamento por forma de pagamento, acompanhado de tabela com valores absolutos em reais e participação percentual. A base é a soma dos totais dos fechamentos de atendimentos concluídos, incluindo produtos e após descontos, sem estimar valores de atendimentos sem fechamento. Percentuais usam sempre o total original, mesmo ao ocultar fatias pela legenda, e não se aplicam quando esse total é zero. Ambos os gráficos possuem alternativa tabular acessível e não são afetados por paginação ou filtros exclusivos da tabela de atendimentos.

- **Backend:** PHP 8.3+, Laravel 13, Laravel Fortify e PhpSpreadsheet para exportação XLSX.
- **Interface:** Vue 3, TypeScript, Inertia.js 3, Tailwind CSS 4 e Vite.
- **Gráficos:** Chart.js com vue-chartjs (MIT), responsivo e com consulta tabular alternativa.

No gráfico de serviços, a continuação tracejada de cada linha mostra os serviços já agendados para os próximos 7 dias, de amanhã até o sétimo dia em `America/Sao_Paulo`. Não se trata de projeção estatística. Tooltips e consulta tabular distinguem agendados de concluídos; somente pontos históricos filtram a tabela de atendimentos concluídos.
- **Calendário:** FullCalendar 6 (Vue 3), plugins padrão MIT, com Luxon e exibição em `America/Sao_Paulo`. Não utiliza plugins comerciais de recursos/timeline.
- **Banco de dados padrão:** SQLite.
- **Testes:** PHPUnit.

## Requisitos

- PHP 8.3 ou superior e Composer.
- Node.js e npm.
- SQLite habilitado no PHP.

## Configuração local

Na raiz do projeto, execute:

```bash
composer run setup
```

Esse comando instala as dependências PHP e JavaScript, cria a chave da aplicação, executa as migrações e compila os recursos do frontend. A configuração inicial usa SQLite; para outra base de dados, ajuste as variáveis `DB_*` no arquivo `.env` antes de executar as migrações.

Para iniciar a aplicação em ambiente de desenvolvimento:

```bash
composer run dev
```

O comando inicia o servidor Laravel, o listener das filas `notifications,default`, o scheduler e o servidor de desenvolvimento do Vite. A aplicação fica disponível, por padrão, em `http://localhost:8000`.

## Notificações por e-mail (SMTP)

O canal começa **desativado**. Nunca habilite o envio com dados de demonstração ou contatos que não possam receber mensagens reais. WhatsApp continua somente registrado, sem integração de envio.

1. Faça backup e aplique `php artisan migrate`. A migração amplia a outbox e preserva o histórico; não admite rollback destrutivo. No MySQL, uma falha pode deixar alterações de estrutura aplicadas sem registrar a migration como concluída. A migration permite retomar esse estado com o mesmo comando, verificando colunas, tabelas, índices e chaves estrangeiras existentes; não apague colunas nem use `migrate:fresh` para tentar novamente. Agendamentos existentes ou criados com o canal desativado ficam fora do envio por padrão, inclusive de alterações posteriores. Confirmações antigas pendentes são marcadas como ignoradas. A inclusão retroativa de reservas futuras exige o comando explícito descrito abaixo.
2. Configure `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` e `APP_URL` com a URL HTTPS pública correta. Use `MAIL_SCHEME=smtp` para STARTTLS (normalmente porta 587) ou `smtps` para TLS implícito (normalmente 465), conforme seu servidor. O transporte mantém a verificação de certificados; não desative TLS/verificação para contornar erros. `MAIL_TIMEOUT=20` limita operações de socket; mantenha-o abaixo do timeout do worker. Não coloque credenciais no repositório.
3. Execute um worker supervisionado: `php artisan queue:work database --queue=notifications,default --tries=1 --timeout=30`. `NOTIFICATIONS_QUEUE_CONNECTION` é `database` por padrão, independentemente da fila padrão da aplicação. Se usar outra conexão, altere também o worker e mantenha seu `retry_after` maior que o lease de 120 segundos; no database padrão, configure `DB_QUEUE_RETRY_AFTER=180`. O claim da outbox impede consumo concorrente.
4. Configure o scheduler para executar `php artisan schedule:run` a cada minuto. Localmente, `composer run dev` já inicia `schedule:work`. O comando `php artisan notifications:dispatch` também permite disparar uma varredura manual de mensagens pendentes e identificar envios interrompidos.
5. Somente depois de SMTP, fila e scheduler estarem prontos, defina `NOTIFICATIONS_EMAIL_ENABLED=true`, recarregue o cache de configuração (`php artisan config:cache`) e reinicie os workers (`php artisan queue:restart`). Reservas criadas com o canal habilitado passam a gerar e-mails transacionais; as anteriores só entram por inclusão retroativa explícita.

Para substituir uma instalação anterior, atualize as dependências com `composer install`, remova as variáveis antigas `RESEND_API_KEY` e `RESEND_WEBHOOK_SECRET` do ambiente e desative o webhook no painel antigo. A rota de webhook e o SDK foram removidos. As tabelas, IDs e eventos históricos existentes são preservados, sem nova migração destrutiva. Interrompa os workers antigos antes da troca para não misturar os transportes. Resultados incertos pendentes do transporte anterior não são reenviados automaticamente.

Verificação de conta e recuperação de senha mantêm os links assinados e o password broker do Laravel/Fortify. Há também aviso de senha alterada pelos dois caminhos existentes. Links e conteúdo renderizado ficam criptografados na outbox; jobs carregam apenas IDs e a administração não recebe tokens, links nem corpo das mensagens. Links vencidos, contas verificadas ou com e-mail alterado não são reenviados. Registro público de contas continua com a configuração existente do Fortify, sem ser habilitado por esta integração.

Cada confirmação, reagendamento ou cancelamento de uma nova reserva elegível registra mensagens independentes para o cliente, o barbeiro responsável e administradores vinculados à unidade pela associação de usuários. Endereços repetidos recebem uma única mensagem por evento. Não há cópia visível de outros destinatários; e-mails da equipe não incluem valores financeiros. Reagendamentos e cancelamentos preservam o histórico e substituem mensagens anteriores ainda pendentes; mensagens já aceitas pelo provedor não podem ser recolhidas. Não há envio de campanhas, lembretes, bloqueios ou fechamento de atendimento.

São até **5 tentativas totais por ciclo**, com esperas mínimas de **1, 5, 15 e 60 minutos**, realizadas pelo scheduler (portanto sujeitas à sua frequência). Somente recusas temporárias explícitas SMTP (códigos 4xx) repetem. Recusas permanentes (5xx) encerram o ciclo. Timeouts, desconexões e workers interrompidos após possível envio ficam bloqueados para análise: o servidor pode ter aceitado a mensagem antes da perda da resposta. A ausência de confirmação não é tratada como falha segura para reenvio.

Administradores acessam **Notificações** (`/notifications`) para consultar globalmente unidade, destinatário, evento, tentativas, próxima execução e falhas sanitizadas. “Aceito pelo servidor SMTP” não significa entrega na caixa de entrada. Não há webhooks, rastreamento de abertura ou confirmação automática de entrega/bounce/reclamação por SMTP. Eventos do mecanismo anterior aparecem apenas como histórico. O identificador da mensagem auxilia a análise nos logs do servidor de e-mail.

O reprocessamento manual exige confirmação, é auditado com o ID do administrador e só libera falhas elegíveis ainda válidas. Mensagens aceitas/entregues, expiradas, ignoradas e resultados ambíguos não podem ser reenviados pela tela. Corrija a causa antes de reprocessar; analise resultados incertos nos logs do servidor SMTP. Cada reprocessamento autorizado inicia outro ciclo de até 5 tentativas com o conteúdo e Message-ID estáveis. A chave da outbox evita duplicidade de registro/consumo local, mas **SMTP não oferece idempotência**: repetir o mesmo Message-ID não garante deduplicação no servidor. Por isso resultados incertos não são repetidos, mesmo manualmente.

Com `MAIL_MAILER=log` ou `array`, mensagens processadas são marcadas como **simulação**, nunca como entrega real, e seu conteúdo sensível não é escrito no log. As falhas operacionais são registradas somente com ID e código sanitizado. Sem `NOTIFICATIONS_EMAIL_ENABLED`, não há ativação retroativa de reservas.

### Confirmações retroativas

Para incluir reservas anteriores à ativação ou criadas enquanto o canal estava desativado, primeiro confira os IDs e a quantidade, sem alterar dados:

```bash
php artisan notifications:backfill
```

Após conferir os destinatários no cadastro e configurar SMTP, worker, scheduler e `NOTIFICATIONS_EMAIL_ENABLED=true`, execute explicitamente:

```bash
php artisan notifications:backfill --write
```

O comando enfileira somente **confirmações de agendamentos futuros com status `scheduled`, não excluídos e ainda não habilitados para e-mail**, em todas as unidades. Usa horário, serviços e contatos atuais, e os mesmos destinatários do fluxo normal: cliente, barbeiro responsável e administradores vinculados à unidade, sem repetir endereços. Não recupera eventos passados, links de conta, cancelamentos ou reagendamentos históricos, nem recria notificações WhatsApp. Registros antigos são preservados; pendências legadas substituídas são marcadas como ignoradas.

Cada reserva incluída passa também a receber futuras alterações e cancelamentos. A operação é transacional por agendamento e pode ser repetida ou retomada sem recriar suas confirmações. Reservas com notificações modernas ou histórico de tentativa, aceite, falha ou resultado incerto são excluídas do lote: o comando não contorna as regras de reenvio/reconciliação. Os envios usam a mesma outbox, validade, monitoramento e política de retentativas do fluxo normal. Com workers ativos, `--write` pode iniciar envios imediatamente após cada agendamento ser registrado; a simulação não enfileira nem habilita reservas.

## Dados de demonstracao

Com as migracoes aplicadas e pelo menos uma unidade e um administrador cadastrados, execute em ambiente `local`:

```bash
php artisan db:seed --class=DemoDataSeeder
```

O seeder usa Eloquent e Faker (`pt_BR`) para adicionar, por unidade existente, cinco barbeiros, oito servicos, quatro produtos e 60 clientes ficticios. Nomes de servicos ja cadastrados sao reaproveitados sem alterar seus dados. Gera agendamentos desde 59 dias atras ate 21 dias a frente, respeitando o fuso, os dias de funcionamento, os horarios da equipe e os conflitos com reservas e bloqueios. O volume depende do expediente disponivel.

O historico inclui atendimentos concluidos com fechamento, cancelamentos, faltas e algumas pendencias; reservas futuras permanecem agendadas. Os fechamentos incluem combinacoes de servicos, produtos opcionais, descontos e as quatro formas de pagamento presencial, sem processar pagamentos. Nenhuma unidade e criada ou alterada, nenhum registro e apagado e nenhuma notificacao e enviada.

Pode ser executado novamente: os cadastros de demonstracao sao reaproveitados e os agendamentos ja gerados (identificados pelo marcador `[demo:...]` nas observacoes) nao sao duplicados nem sobrescritos. Uma nova execucao em outro dia estende a janela de datas. Os contatos usam `example.test` e telefones ficticios; os novos barbeiros recebem senhas aleatorias, sem credencial compartilhada. Acesse com o administrador existente para testar. O comando recusa ambientes diferentes de `local` e `testing`. Nao use `migrate:fresh` nem o seeder padrao para esta populacao.

## Telefones já cadastrados

Os registros anteriores não são alterados automaticamente. Após atualizar as dependências PHP e JavaScript, verifique a conversão com `php artisan phones:normalize` (simulação, sem gravação) e aplique com `php artisan phones:normalize --write`. Faça backup antes de aplicar. Números nacionais antigos são interpretados como brasileiros; números estrangeiros sem código internacional precisam de revisão manual antes da conversão.

O comando percorre usuários, clientes (inclusive excluídos logicamente), telefones de unidades e payloads de notificações pendentes, preservando notificações já enviadas. Telefones equivalentes de uma unidade são deduplicados. Registros inválidos são preservados e identificados por tabela/ID, sem expor o número; o comando retorna falha enquanto houver pendências, mesmo quando os outros registros válidos foram gravados com `--write`. Revise essas pendências antes de ativar o envio de notificações.

## Testes

```bash
php artisan test
```

Os testes de formatação e normalização do frontend usam Node.js 22.6 ou superior: `npm run test:phone`.
As regras de seleção e sobreposição dos serviços públicos são cobertas por `npm run test:booking`. A outbox, o corte de ativação, as retentativas e os templates são cobertos por `EmailNotificationsTest`; o transporte SMTP e a classificação de erros são cobertos por `SmtpGatewayTest`, sem envio real a destinatários.

As regras de comparação do dashboard são cobertas por `node --experimental-strip-types --test tests/frontend/dashboard-comparison.test.mjs`.

Os gráficos de atendimentos por barbeiro e de faturamento por forma de pagamento são cobertos por `node --test tests/frontend/dashboard-charts.test.mjs`, incluindo ordenação, percentuais, atualização dos dados, estados vazios e tabelas acessíveis.

Para executar as verificações de lint, formatação, tipos e testes configuradas no projeto:

```bash
composer run ci:check
```
