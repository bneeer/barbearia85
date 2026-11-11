<?php

namespace App\Http\Controllers;

use App\Models\Barbershop;
use App\Models\Notification;
use App\Services\RetryEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class NotificationController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    public function index(Request $request, RetryEmailNotification $retry)
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate([
            'recipient' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['pending', 'processing', 'sent', 'failed', 'skipped', 'simulated'])],
            'template' => ['nullable', Rule::in(['verify_email', 'reset_password', 'password_changed', 'booking_confirmation', 'booking_rescheduled', 'booking_cancellation', 'booking_cancellation_request'])],
            'barbershop_id' => ['nullable', 'integer', 'exists:barbershops,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $query = Notification::where('type', 'email')
            ->when($filters['recipient'] ?? null, fn ($query, $value) => $query->where('recipient', 'like', '%'.$value.'%'))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '<=', $value));
        foreach (['status', 'template', 'barbershop_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return Inertia::render('Notifications/Index', [
            'notifications' => $query->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(fn ($notification) => [...$this->summary($notification), 'can_retry' => $retry->allowed($notification)]),
            'filters' => $filters,
            'barbershops' => Barbershop::orderBy('name')->get(['id', 'name']),
            'emailEnabled' => (bool) config('notifications.email_enabled'),
        ]);
    }

    public function show(Request $request, Notification $notification, RetryEmailNotification $retry)
    {
        $this->authorizeAdmin($request);
        abort_unless($notification->type === 'email', 404);

        return response()->json([
            ...$this->summary($notification),
            'can_retry' => $retry->allowed($notification),
            'attempts_history' => $notification->attemptsHistory()->get([
                'id', 'number', 'result', 'code', 'requested_by', 'created_at', 'finished_at',
            ]),
            'webhooks' => $notification->provider_id
                ? DB::table('notification_webhook_events')->where('provider_id', $notification->provider_id)
                    ->orderBy('occurred_at')->get(['event_id', 'type', 'occurred_at'])
                : [],
        ]);
    }

    public function retry(Request $request, Notification $notification, RetryEmailNotification $retry)
    {
        $this->authorizeAdmin($request);
        $retry->retry($notification->id, $request->user()->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reprocessamento registrado. Aguarde o próximo ciclo da fila.']);

        return back();
    }

    private function summary(Notification $notification): array
    {
        return $notification->only([
            'id', 'appointment_id', 'user_id', 'barbershop_id', 'recipient', 'audience',
            'template', 'status', 'delivery_status', 'attempts', 'failure_code',
            'provider_id', 'created_at', 'sent_at', 'next_attempt_at', 'expires_at', 'delivery_updated_at',
        ]);
    }
}
