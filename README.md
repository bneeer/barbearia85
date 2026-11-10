# Barbearia 85

Aplicação web para apresentar a Barbearia 85 e permitir que clientes agendem serviços nas unidades disponíveis. A área administrativa autenticada reúne o gerenciamento das unidades, barbeiros, serviços e produtos.

## Recursos

- Página inicial pública com apresentação da barbearia e galeria de fotos.
- Agendamento público com escolha de unidade, um ou mais serviços, barbeiro, data e horário.
- Consulta de horários disponíveis considerando a duração somada dos serviços, expediente, horários de trabalho dos barbeiros, bloqueios e agendamentos existentes.
- Cadastro do cliente e criação do agendamento com notificações registradas para processamento.
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

## Tecnologias

O gráfico do dashboard mostra, para o intervalo e unidade selecionados, serviços concluídos (linha contínua) e agendados (tracejada), com dias sem movimento zerados, tooltips, legenda interativa e tabela acessível alternativa. Conta cada serviço uma vez por atendimento, preservando os itens do fechamento e usando o cadastro apenas para registros antigos sem fechamento. Clicar em um ponto de concluídos filtra a tabela pelo serviço e dia, preservando os filtros globais. O gráfico respeita o acesso do perfil: dados globais para administradores e somente os próprios atendimentos para barbeiros.

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

O comando inicia o servidor Laravel, o listener da fila e o servidor de desenvolvimento do Vite. A aplicação fica disponível, por padrão, em `http://localhost:8000`.

## Dados de demonstracao

Com as migracoes aplicadas e pelo menos uma unidade e um administrador cadastrados, execute em ambiente `local`:

```bash
php artisan db:seed --class=DemoDataSeeder
```

O seeder usa Eloquent e Faker (`pt_BR`) para adicionar, por unidade existente, cinco barbeiros, oito servicos, quatro produtos e 60 clientes ficticios. Nomes de servicos ja cadastrados sao reaproveitados sem alterar seus dados. Gera agendamentos desde 59 dias atras ate 21 dias a frente, respeitando o fuso, os dias de funcionamento, os horarios da equipe e os conflitos com reservas e bloqueios. O volume depende do expediente disponivel.

O historico inclui atendimentos concluidos com fechamento, cancelamentos, faltas e algumas pendencias; reservas futuras permanecem agendadas. Os fechamentos incluem combinacoes de servicos, produtos opcionais, descontos e as quatro formas de pagamento presencial, sem processar pagamentos. Nenhuma unidade e criada ou alterada, nenhum registro e apagado e nenhuma notificacao e enviada.

Pode ser executado novamente: os cadastros de demonstracao sao reaproveitados e os agendamentos ja gerados (identificados pelo marcador `[demo:...]` nas observacoes) nao sao duplicados nem sobrescritos. Uma nova execucao em outro dia estende a janela de datas. Os contatos usam `example.test` e telefones ficticios; os novos barbeiros recebem senhas aleatorias, sem credencial compartilhada. Acesse com o administrador existente para testar. O comando recusa ambientes diferentes de `local` e `testing`. Nao use `migrate:fresh` nem o seeder padrao para esta populacao.

## Testes

```bash
php artisan test
```

Para executar as verificações de lint, formatação, tipos e testes configuradas no projeto:

```bash
composer run ci:check
```
