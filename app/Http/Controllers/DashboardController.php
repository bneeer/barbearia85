<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Barbershop;
use App\Models\Scopes\BarbershopScope;
use App\Models\User;
use App\Services\DashboardAppointments;
use App\Services\DashboardStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardAppointments $dashboard, DashboardStock $stock)
    {
        if (! $request->user()->isBarber()) {
            return Inertia::render('Dashboard');
        }

        $filters = $this->validatedFilters($request);
        $filters = $this->withDefaultRange($filters);
        $query = $this->appointmentsQuery($request, $dashboard, $filters);
        $timezone = config('app.display_timezone');
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['range_from'], $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['range_to'], $timezone);
        $barbershopId = isset($filters['barbershop_id']) ? (int) $filters['barbershop_id'] : null;

        $props = [
            'filters' => $filters,
            'barbershops' => fn () => $this->availableBarbershops($request->user()),
            'shortcuts' => fn () => $this->rangeShortcuts(),
            'indicators' => fn () => $dashboard->indicators(CarbonImmutable::now(new \DateTimeZone('America/Sao_Paulo')), $request->user()),
            'summary' => fn () => $dashboard->rangeSummary($start, $end, $barbershopId, $request->user()),
            'servicesTimeline' => fn () => $dashboard->servicesTimeline(CarbonImmutable::now(new \DateTimeZone('America/Sao_Paulo')), $request->user()),
            'rangeTimeline' => fn () => $dashboard->servicesTimelineForRange($start, $end, $barbershopId, $request->user()),
            'appointments' => $query->orderByDesc('start_time')->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 10)->withQueryString()
                ->through(fn (Appointment $appointment) => $this->appointmentData($appointment, $request->user())),
        ];
        if ($request->user()->isAdmin()) {
            $props['stockSummary'] = fn () => $stock->summary(
                $start,
                $end,
                $barbershopId,
                $request->user(),
            );
        }

        return Inertia::render('Dashboard', $props);
    }

    public function export(Request $request, DashboardAppointments $dashboard)
    {
        abort_unless($request->user()->isBarber(), 403);
        $filters = $this->withDefaultRange($this->validatedFilters($request));
        $query = $this->appointmentsQuery($request, $dashboard, $filters)
            ->orderByDesc('start_time')->orderByDesc('id');
        $admin = $request->user()->isAdmin();
        $headers = [
            'Nº', 'Unidade', 'Barbeiro', 'Cliente', 'E-mail', 'Telefone',
            'Início', 'Fim', 'Serviços', 'Observações do agendamento',
        ];
        if ($admin) {
            $headers = [
                ...$headers, 'Produtos', 'Total de serviços',
                'Total de produtos', 'Desconto',
                'Total do fechamento', 'Forma de pagamento',
                'Observações do fechamento',
            ];
        }

        $paymentMethods = [
            'credit_card' => 'Cartão de Crédito',
            'debit_card' => 'Cartão de Débito',
            'pix' => 'PIX',
            'cash' => 'Dinheiro',
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Atendimentos');
        $this->writeSpreadsheetRow($sheet, 1, $headers);
        $row = 2;
        $query->chunk(500, function ($appointments) use ($sheet, &$row, $admin, $paymentMethods) {
            foreach ($appointments as $appointment) {
                $client = $appointment->client;
                $checkout = $appointment->checkout;
                $services = $checkout
                    ? collect($checkout->services)->pluck('name')->implode(', ')
                    : ($appointment->services->isNotEmpty() ? $appointment->services : collect([$appointment->service])->filter())
                        ->pluck('name')->implode(', ');
                $values = [
                    $appointment->id,
                    $appointment->barbershop?->name ?? 'Unidade indisponível',
                    $appointment->barber?->name ?? 'Barbeiro indisponível',
                    $client?->name ?? 'Cliente indisponível',
                    $client?->email ?? '',
                    $client?->phone ?? '',
                    $appointment->start_time->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i'),
                    $appointment->end_time->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i'),
                    $services,
                    $appointment->notes ?? '',
                ];
                if ($admin) {
                    $products = collect($checkout?->products ?? [])
                        ->map(fn ($product) => ($product['quantity'] ?? 1).'x '.$product['name'])
                        ->implode(', ');
                    $values = [
                        ...$values,
                        $products,
                        '', '', '', '',
                        $paymentMethods[$checkout?->payment_method] ?? '',
                        $checkout?->notes ?? '',
                    ];
                }
                $this->writeSpreadsheetRow($sheet, $row, $values);
                if ($admin && $checkout) {
                    foreach (['services_total_cents', 'products_total_cents', 'discount_cents', 'total_cents'] as $index => $field) {
                        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex(12 + $index).$row);
                        $cell->setValueExplicit($checkout->{$field} / 100, DataType::TYPE_NUMERIC);
                        $cell->getStyle()->getNumberFormat()->setFormatCode('[$R$-416] #,##0.00');
                    }
                }
                $row++;
            }
        });

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            'atendimentos-'.$filters['range_from'].'-'.$filters['range_to'].'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function writeSpreadsheetRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($index + 1).$row,
                (string) $value,
                DataType::TYPE_STRING,
            );
        }
    }

    private function validatedFilters(Request $request): array
    {
        $user = $request->user();
        $rules = [
            'id' => ['nullable', 'integer', 'min:1'],
            'barber_id' => $user->isAdmin() ? ['nullable', 'integer', 'exists:users,id'] : ['nullable', 'integer', 'in:'.$user->id],
            'barbershop_id' => [
                'nullable',
                'integer',
                ...$this->barbershopRules($user),
            ],
            'service_id' => ['nullable', 'integer', 'min:1'],
            'chart_day' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'in:credit_card,debit_card,pix,cash'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'range_from' => ['nullable', 'required_with:range_to', 'date_format:Y-m-d'],
            'range_to' => ['nullable', 'required_with:range_from', 'date_format:Y-m-d', ...($request->filled('range_from') ? ['after_or_equal:range_from'] : [])],
            'period' => ['nullable', 'in:day,week,month'],
            'period_at' => ['required_with:period', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
        ];
        foreach (['unit', 'barber', 'client', 'email', 'phone', 'services', 'products', 'notes'] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:100'];
        }
        foreach (['services_total', 'products_total', 'discount', 'total'] as $field) {
            $rules[$field.'_min'] = ['nullable', 'integer', 'min:0', 'max:99999999999'];
            $rules[$field.'_max'] = ['nullable', 'integer', 'min:0', 'max:99999999999',
                ...($request->filled($field.'_min') ? ['gte:'.$field.'_min'] : [])];
        }

        $filters = $request->validate($rules);
        if (isset($filters['range_from'], $filters['range_to'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['range_from'], config('app.display_timezone'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters['range_to'], config('app.display_timezone'));
            if ($from->diffInDays($to) > 364) {
                throw ValidationException::withMessages([
                    'range_to' => 'O intervalo do dashboard não pode exceder 365 dias.',
                ]);
            }
        }
        if (! $user->isAdmin()) {
            $privateFilters = ['products', 'payment_method'];
            foreach (['services_total', 'products_total', 'discount', 'total'] as $field) {
                $privateFilters[] = $field.'_min';
                $privateFilters[] = $field.'_max';
            }
            $request->validate(array_fill_keys($privateFilters, ['prohibited']));
        }

        return $filters;
    }

    private function withDefaultRange(array $filters): array
    {
        if (! isset($filters['range_from'], $filters['range_to'])) {
            $timezone = config('app.display_timezone');
            if (isset($filters['period'], $filters['period_at'])) {
                $reference = CarbonImmutable::parse($filters['period_at'])->setTimezone($timezone);
                $start = match ($filters['period']) {
                    'day' => $reference->startOfDay(),
                    'week' => $reference->startOfWeek(CarbonImmutable::MONDAY),
                    'month' => $reference->startOfMonth(),
                };
                $end = match ($filters['period']) {
                    'day' => $start,
                    'week' => $start->addWeek()->subDay(),
                    'month' => $start->addMonth()->subDay(),
                };
            } elseif (isset($filters['chart_day'])) {
                $start = $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['chart_day'], $timezone);
            } elseif (isset($filters['date_from'], $filters['date_to'])) {
                $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], $timezone);
                $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], $timezone);
            } else {
                $start = CarbonImmutable::now($timezone)->startOfMonth();
                $end = CarbonImmutable::now($timezone)->startOfDay();
            }
            $filters['range_from'] = $start->format('Y-m-d');
            $filters['range_to'] = $end->format('Y-m-d');
        }

        return $filters;
    }

    private function availableBarbershops(User $user): array
    {
        return Barbershop::query()
            ->when(! $user->isAdmin(), fn ($query) => $query->where(
                fn ($shops) => $shops->whereHas('users', fn ($users) => $users->whereKey($user->id))
                    ->when($user->barbershop_id, fn ($shops) => $shops->orWhere('barbershops.id', $user->barbershop_id)),
            ))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (Barbershop $shop) => ['id' => $shop->id, 'name' => $shop->name])
            ->all();
    }

    private function barbershopRules(User $user): array
    {
        if ($user->isAdmin()) {
            return [Rule::exists('barbershops', 'id')];
        }

        $ids = Barbershop::query()
            ->where(fn ($query) => $query->whereHas('users', fn ($users) => $users->whereKey($user->id))
                ->when($user->barbershop_id, fn ($query) => $query->orWhere('barbershops.id', $user->barbershop_id)))
            ->pluck('id')->all();

        return [Rule::in($ids)];
    }

    private function rangeShortcuts(): array
    {
        $today = CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $monthStart = $today->startOfMonth();

        return [
            'day' => ['from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            'week' => ['from' => $weekStart->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            'month' => ['from' => $monthStart->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
        ];
    }

    private function appointmentsQuery(Request $request, DashboardAppointments $dashboard, array $filters): Builder
    {
        $relations = [
            'barbershop',
            'barber',
            'client' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
            'service' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
            'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
            'checkout' => fn ($query) => $request->user()->isAdmin()
                ? $query
                : $query->select('appointment_id', 'services'),
        ];
        $query = $dashboard->query($request->user())
            ->where('status', 'completed')
            ->with($relations);

        if (! empty($filters['id'])) {
            $query->whereKey($filters['id']);
        }
        if (isset($filters['barber_id'])) {
            $query->where('user_id', $filters['barber_id']);
        }
        if (isset($filters['barbershop_id'])) {
            $query->where('barbershop_id', $filters['barbershop_id']);
        }
        if (isset($filters['service_id'])) {
            $dashboard->forService($query, (int) $filters['service_id']);
        }
        if (isset($filters['chart_day'])) {
            $start = CarbonImmutable::parse($filters['chart_day'], config('app.display_timezone'));
            $query->where('start_time', '>=', $start->utc())->where('start_time', '<', $start->addDay()->utc());
        }
        foreach (['unit' => ['barbershop', 'name'], 'barber' => ['barber', 'name'],
            'client' => ['client', 'name'], 'email' => ['client', 'email'], 'phone' => ['client', 'phone']] as $filter => [$relation, $column]) {
            if (isset($filters[$filter])) {
                $query->whereHas($relation, function (Builder $related) use ($relation, $column, $filters, $filter) {
                    if ($relation === 'client') {
                        $related->withoutGlobalScope(BarbershopScope::class)->withTrashed();
                    }
                    $related->whereLike($column, '%'.$filters[$filter].'%');
                });
            }
        }
        if (isset($filters['period'])) {
            $dashboard->inPeriod($query, $filters['period'], CarbonImmutable::parse($filters['period_at']));
        }
        $timezone = config('app.display_timezone');
        if (isset($filters['range_from'])) {
            $query->where('start_time', '>=', CarbonImmutable::parse($filters['range_from'], $timezone)->utc())
                ->where('start_time', '<', CarbonImmutable::parse($filters['range_to'], $timezone)->addDay()->utc());
        }
        if (isset($filters['date_from'])) {
            $query->where('start_time', '>=', CarbonImmutable::parse($filters['date_from'], $timezone)->utc());
        }
        if (isset($filters['date_to'])) {
            $query->where('start_time', '<', CarbonImmutable::parse($filters['date_to'], $timezone)->addDay()->utc());
        }
        foreach (['services', 'products'] as $field) {
            if (isset($filters[$field])) {
                $query->where(function (Builder $items) use ($field, $filters) {
                    $items->whereHas('checkout', function (Builder $checkout) use ($field, $filters) {
                        $term = substr(json_encode($filters[$field], JSON_THROW_ON_ERROR), 1, -1);
                        $checkout->where(function (Builder $text) use ($field, $filters, $term) {
                            $text->whereLike($field, '%'.$term.'%')
                                ->orWhereLike($field, '%'.$filters[$field].'%');
                        });
                    });
                    if ($field === 'services') {
                        $items->orWhere(function (Builder $legacy) use ($filters) {
                            $legacy->whereDoesntHave('checkout')->where(function (Builder $services) use ($filters) {
                                foreach (['services', 'service'] as $relation) {
                                    $services->orWhereHas($relation, fn (Builder $service) => $service
                                        ->withoutGlobalScope(BarbershopScope::class)->withTrashed()
                                        ->whereLike('name', '%'.$filters['services'].'%'));
                                }
                            });
                        });
                    }
                });
            }
        }
        if (isset($filters['payment_method'])) {
            $query->whereHas('checkout', fn (Builder $checkout) => $checkout->where('payment_method', $filters['payment_method']));
        }
        foreach (['services_total', 'products_total', 'discount', 'total'] as $field) {
            foreach (['min' => '>=', 'max' => '<='] as $bound => $operator) {
                if (isset($filters[$field.'_'.$bound])) {
                    $query->whereHas('checkout', fn (Builder $checkout) => $checkout
                        ->where($field.'_cents', $operator, $filters[$field.'_'.$bound]));
                }
            }
        }
        if (isset($filters['notes'])) {
            $query->where(fn (Builder $notes) => $notes->whereLike('notes', '%'.$filters['notes'].'%')
                ->when($request->user()->isAdmin(), fn (Builder $notes) => $notes
                    ->orWhereHas('checkout', fn (Builder $checkout) => $checkout->whereLike('notes', '%'.$filters['notes'].'%'))));
        }

        return $query;
    }

    private function appointmentData(Appointment $appointment, User $viewer): array
    {
        return [
            'id' => $appointment->id,
            'unit' => $appointment->barbershop?->name,
            'timezone' => config('app.display_timezone'),
            'barber' => $appointment->barber?->name,
            'client' => $appointment->client?->only(['name', 'email', 'phone']),
            'start_time' => $appointment->start_time->toIso8601String(),
            'end_time' => $appointment->end_time->toIso8601String(),
            'notes' => $appointment->notes,
            'services' => $appointment->checkout
                ? collect($appointment->checkout->services)->map(fn ($service) => $viewer->isAdmin()
                    ? $service : array_intersect_key($service, array_flip(['id', 'name', 'duration'])))->values()
                : ($appointment->services->isNotEmpty() ? $appointment->services : collect([$appointment->service])->filter())
                    ->map(fn ($service) => ['name' => $service->name, 'duration' => $service->duration])->values(),
            'checkout' => $viewer->isAdmin() && $appointment->checkout ? [
                ...$appointment->checkout->only([
                    'products', 'services_total_cents', 'products_total_cents',
                    'discount_cents', 'total_cents', 'payment_method', 'notes',
                ]),
                'created_at' => $appointment->checkout->created_at->toIso8601String(),
            ] : null,
        ];
    }

    public function scheduled(Request $request, DashboardAppointments $dashboard)
    {
        abort_unless($request->user()->isBarber(), 403);
        $user = $request->user();
        $filters = $request->validate([
            'period' => ['nullable', 'required_without:range_from', 'in:day,week,month'],
            'period_at' => ['required_with:period', 'date_format:Y-m-d\TH:i:sP'],
            'range_from' => ['nullable', 'required_with:range_to', 'date_format:Y-m-d'],
            'range_to' => ['nullable', 'required_with:range_from', 'date_format:Y-m-d', ...($request->filled('range_from') ? ['after_or_equal:range_from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'barbershop_id' => [
                'nullable',
                'integer',
                ...$this->barbershopRules($user),
            ],
            'barber_id' => $user->isAdmin() ? ['nullable', 'integer', 'exists:users,id'] : ['nullable', 'integer', 'in:'.$user->id],
        ]);
        if (isset($filters['range_from'], $filters['range_to'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['range_from'], config('app.display_timezone'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters['range_to'], config('app.display_timezone'));
            if ($from->diffInDays($to) > 364) {
                throw ValidationException::withMessages([
                    'range_to' => 'O intervalo do dashboard não pode exceder 365 dias.',
                ]);
            }
            $start = $from->utc();
            $end = $to->addDay()->utc();
        } else {
            $reference = CarbonImmutable::parse($filters['period_at']);
        }

        $query = $dashboard->query($user)
            ->where('status', 'scheduled')
            ->when(isset($filters['barbershop_id']), fn (Builder $query) => $query->where('barbershop_id', $filters['barbershop_id']))
            ->when(isset($filters['barber_id']), fn (Builder $query) => $query->where('user_id', $filters['barber_id']));
        if (isset($filters['range_from'])) {
            $query->where('start_time', '>=', $start)->where('start_time', '<', $end);
        } else {
            $query = $dashboard->inPeriod($query, $filters['period'], $reference);
        }
        if ($user->isAdmin()) {
            $dashboard->withExpectedAmount($query->select('appointments.*'));
        }

        return $query
            ->with([
                'barbershop', 'barber',
                'client' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
                'service' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class)->withTrashed(),
                'services' => fn ($query) => $query->withoutGlobalScope(BarbershopScope::class),
            ])
            ->orderBy('start_time')->orderBy('id')->paginate(10)->withQueryString()
            ->through(fn (Appointment $appointment) => [
                'id' => $appointment->id, 'unit' => $appointment->barbershop->name,
                'timezone' => config('app.display_timezone'), 'barber' => $appointment->barber?->name,
                'client' => $appointment->client?->only(['name', 'phone']),
                'start_time' => $appointment->start_time->toIso8601String(),
                'end_time' => $appointment->end_time->toIso8601String(),
                'services' => ($appointment->services->isNotEmpty()
                    ? $appointment->services : collect([$appointment->service])->filter())->pluck('name'),
                ...($user->isAdmin() ? [
                    'expected_cents' => $appointment->expected_cents === null ? null : (int) $appointment->expected_cents,
                ] : []),
            ]);
    }
}
