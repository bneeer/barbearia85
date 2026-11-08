# Barbearia 85

Aplicação web para apresentar a Barbearia 85 e permitir que clientes agendem serviços nas unidades disponíveis. A área administrativa autenticada reúne o gerenciamento das unidades, barbeiros e serviços.

## Recursos

- Página inicial pública com apresentação da barbearia e galeria de fotos.
- Agendamento público com escolha de unidade, serviço, barbeiro, data e horário.
- Consulta de horários disponíveis considerando duração do serviço, expediente, horários de trabalho dos barbeiros, bloqueios e agendamentos existentes.
- Cadastro do cliente e criação do agendamento com notificações registradas para processamento.
- Área administrativa para gerenciar unidades, horários e equipes, barbeiros, serviços e fotos das unidades.
- Autenticação, verificação de e-mail, recuperação de senha e configurações de perfil e segurança.

## Tecnologias

- **Backend:** PHP 8.3+, Laravel 13 e Laravel Fortify.
- **Interface:** Vue 3, TypeScript, Inertia.js 3, Tailwind CSS 4 e Vite.
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
