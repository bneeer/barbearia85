# Barbearia 85

Aplicação web para apresentar a Barbearia 85 e permitir que clientes agendem serviços nas unidades disponíveis. A área administrativa autenticada reúne o gerenciamento das unidades, barbeiros, serviços e produtos.

## Recursos

- Página inicial pública com apresentação da barbearia e galeria de fotos.
- Agendamento público com escolha de unidade, um ou mais serviços, barbeiro, data e horário.
- Consulta de horários disponíveis considerando a duração somada dos serviços, expediente, horários de trabalho dos barbeiros, bloqueios e agendamentos existentes.
- Cadastro do cliente e criação do agendamento com notificações registradas para processamento.
- Área administrativa para gerenciar unidades, horários e equipes, barbeiros, serviços e fotos das unidades.
- Seção de Produtos (`/products`) para administradores cadastrarem, consultarem, editarem e excluírem produtos da própria unidade, com nome, marca, descrição e preço em reais. Inclui busca por nome ou marca e listagem paginada.
- Calendário de agendamentos (`/schedule`) com seleção de unidades, filtro por barbeiro, visualizações de mês/semana/dia/lista e detalhes em dialog.
- Administradores consultam todas as unidades e podem cancelar ou reagendar reservas por arraste; barbeiros consultam somente suas reservas nas unidades associadas.
- Administradores finalizam atendimentos no calendário, incluindo produtos da unidade, desconto, forma de pagamento presencial e observações opcionais. O fechamento preserva os itens, valores e observações para consulta; não processa nem confirma pagamentos. Atendimentos concluídos ficam em azul-escuro e não podem ser editados.
- Administradores também podem cadastrar agendamentos recebidos por outros canais, informando cliente, barbeiro, serviços, data e período; os horários são consultados novamente e validados antes da confirmação.
- Ao selecionar uma faixa no calendário, administradores escolhem entre criar um agendamento ou um bloqueio. Agendamentos precisam caber na faixa selecionada, mas ocupam na agenda somente a duração somada dos serviços selecionados.
- Bloqueios por intervalo ou dia inteiro: administradores gerenciam toda a equipe e barbeiros apenas a própria agenda. Bloqueios são globais por profissional e não podem atingir reservas existentes, inclusive em outra unidade.
- Reagendamentos respeitam o expediente da unidade e do barbeiro, a duração somada dos serviços e os conflitos de agenda. A grade pública de 40 minutos permanece inalterada; ajustes administrativos podem usar outros horários.
- Autenticação, verificação de e-mail, recuperação de senha e configurações de perfil e segurança.

## Tecnologias

- **Backend:** PHP 8.3+, Laravel 13 e Laravel Fortify.
- **Interface:** Vue 3, TypeScript, Inertia.js 3, Tailwind CSS 4 e Vite.
- **Calendário:** FullCalendar 6 (Vue 3), plugins padrão MIT, com Luxon para respeitar o fuso de cada unidade. Não utiliza plugins comerciais de recursos/timeline.
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

## Testes

```bash
php artisan test
```

Para executar as verificações de lint, formatação, tipos e testes configuradas no projeto:

```bash
composer run ci:check
```
