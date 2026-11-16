<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    private const DOCUMENTS = [
        [
            'id' => 'primeiros-passos',
            'title' => 'Primeiros passos e navegação',
            'category' => 'Geral',
            'roles' => ['admin', 'barber'],
            'file' => 'docs/help/primeiros-passos.md',
        ],
        [
            'id' => 'painel-e-agendamentos',
            'title' => 'Painel e agendamentos',
            'category' => 'Geral',
            'roles' => ['admin', 'barber'],
            'file' => 'docs/help/painel-e-agendamentos.md',
        ],
        [
            'id' => 'agendamento-publico',
            'title' => 'Agendamento público',
            'category' => 'Geral',
            'roles' => ['admin', 'barber'],
            'file' => 'docs/help/agendamento-publico.md',
        ],
        [
            'id' => 'area-do-cliente',
            'title' => 'Área do cliente',
            'category' => 'Geral',
            'roles' => ['admin', 'barber'],
            'file' => 'docs/help/area-do-cliente.md',
        ],
        [
            'id' => 'minhas-comissoes',
            'title' => 'Minhas comissões',
            'category' => 'Barbeiros',
            'roles' => ['admin', 'barber'],
            'file' => 'docs/help/minhas-comissoes.md',
        ],
        [
            'id' => 'gestao-administrativa',
            'title' => 'Unidades, equipe, serviços e clientes',
            'category' => 'Administração',
            'roles' => ['admin'],
            'file' => 'docs/help/gestao-administrativa.md',
        ],
        [
            'id' => 'estoque',
            'title' => 'Estoque',
            'category' => 'Administração',
            'roles' => ['admin'],
            'file' => 'docs/help/estoque.md',
        ],
        [
            'id' => 'financeiro',
            'title' => 'Despesas e faturamento',
            'category' => 'Administração',
            'roles' => ['admin'],
            'file' => 'docs/help/financeiro.md',
        ],
        [
            'id' => 'caixa',
            'title' => 'Caixa',
            'category' => 'Administração',
            'roles' => ['admin'],
            'file' => 'docs/help/caixa.md',
        ],
        [
            'id' => 'notificacoes-e-privacidade',
            'title' => 'Notificações e solicitações LGPD',
            'category' => 'Administração',
            'roles' => ['admin'],
            'file' => 'docs/help/notificacoes-e-privacidade.md',
        ],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isBarber(), 403);

        $documents = collect(self::DOCUMENTS)
            ->filter(fn (array $document) => in_array($user->role, $document['roles'], true))
            ->map(fn (array $document) => [
                'id' => $document['id'],
                'title' => $document['title'],
                'category' => $document['category'],
                'content' => File::get(base_path($document['file'])),
            ])
            ->values();

        return Inertia::render('Help/Index', [
            'documents' => $documents,
        ]);
    }
}
