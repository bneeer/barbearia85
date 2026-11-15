<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryEmailNotification
{
    public function allowed(Notification $notification): bool
    {
        return $notification->template !== 'client_portal_access'
            && $notification->type === 'email' && $notification->status === 'failed'
            && $notification->deduplication_key && ! $notification->provider_id
            && in_array($notification->failure_code, ['rate_limited', 'provider_unavailable', 'missing_api_key', 'unsupported_mailer', 'attempts_exhausted', 'smtp_temporary_rejection'])
            && ! app(EmailDelivery::class)->ineligible($notification);
    }

    public function retry(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId) {
            $notification = Notification::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $this->allowed($notification)) {
                throw ValidationException::withMessages([
                    'notification' => 'Este envio não pode ser repetido. Verifique validade, resultado e necessidade de reconciliação.',
                ]);
            }
            NotificationAttempt::create([
                'notification_id' => $id, 'number' => $notification->attempts,
                'result' => 'retry_requested', 'requested_by' => $actorId,
                'created_at' => now(new \DateTimeZone('America/Sao_Paulo')), 'finished_at' => now(new \DateTimeZone('America/Sao_Paulo')),
            ]);
            $notification->update([
                'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(new \DateTimeZone('America/Sao_Paulo')),
                'failure_code' => null, 'lock_token' => null, 'locked_until' => null,
            ]);
        });
    }
}
