<?php

namespace App\Services;

use App\Mail\NotificationEmail;
use App\Models\Appointment;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailDelivery
{
    public function send(int $id): void
    {
        $mailer = config('mail.default');
        $simulation = in_array($mailer, ['log', 'array']);
        if (! config('notifications.email_enabled') && ! $simulation) {
            return;
        }
        $record = DB::transaction(function () use ($id) {
            $record = Notification::whereKey($id)->lockForUpdate()->first();
            if (! $record || $record->type !== 'email' || ! $record->deduplication_key
                || ! in_array($record->status, ['pending', 'processing'])
                || $record->next_attempt_at?->isFuture()
                || ($record->status === 'processing' && $record->locked_until?->isFuture())) {
                return null;
            }
            if ($reason = $this->ineligible($record)) {
                $record->update(['status' => 'skipped', 'failure_code' => $reason]);

                return null;
            }
            if ($record->status === 'processing' || $record->attemptsHistory()->where('result', 'processing')->exists()
                || in_array($record->failure_code, ['provider_result_unknown', 'smtp_result_unknown', 'worker_interrupted'])) {
                $record->update([
                    'status' => 'failed', 'failure_code' => 'reconciliation_required',
                    'locked_until' => null, 'lock_token' => null, 'next_attempt_at' => null,
                ]);
                $record->attemptsHistory()->where('result', 'processing')
                    ->update(['result' => 'interrupted', 'code' => 'worker_interrupted', 'finished_at' => now()]);

                return null;
            }
            if ($record->attempts >= 5) {
                $record->update(['status' => 'failed', 'failure_code' => 'attempts_exhausted']);

                return null;
            }
            $record->update([
                'status' => 'processing', 'lock_token' => (string) Str::uuid(),
                'locked_until' => now()->addSeconds(config('notifications.lease_seconds')),
                'first_attempt_at' => $record->first_attempt_at ?? now(),
                'attempts' => $record->attempts + 1,
            ]);
            NotificationAttempt::create([
                'notification_id' => $record->id, 'number' => $record->attempts, 'created_at' => now(),
            ]);

            return $record;
        });
        if (! $record) {
            return;
        }
        try {
            if (! $record->message) {
                $mail = new NotificationEmail($record);
                $record->message = [
                    'from' => config('mail.from.name').' <'.config('mail.from.address').'>',
                    'to' => [$record->recipient], 'subject' => $mail->title(),
                    'html' => $mail->render(),
                    'text' => view($mail->textView(), $mail->data())->render(),
                ];
                $record->save();
            }
            if (! $simulation && $mailer !== 'smtp') {
                throw new EmailDeliveryFailure('unsupported_mailer', false);
            }
            $providerId = $simulation ? null : app(SmtpGateway::class)->send($record->message, $record->deduplication_key);
            $this->finish($record, $simulation ? 'simulated' : 'sent', $providerId);
        } catch (EmailDeliveryFailure $failure) {
            $this->fail($record, $failure);
        } catch (\Throwable $exception) {
            // Do not log exceptions that may contain account URLs or raw provider responses.
            Log::error('Email notification rendering or persistence failed.', [
                'notification_id' => $id, 'exception_class' => $exception::class,
            ]);
            $this->fail($record, new EmailDeliveryFailure('internal_error', false));
        }
    }

    public function ineligible(Notification $record): ?string
    {
        if ($record->expires_at?->lte(now())) {
            return 'expired';
        }
        if ($record->appointment_id) {
            $appointment = Appointment::withoutGlobalScopes()->find($record->appointment_id);
            if (! $appointment || ! $appointment->email_notifications_enabled) {
                return 'activation_cutoff';
            }
            if ($record->template !== 'booking_cancellation'
                && ($appointment->status !== 'scheduled'
                    || $appointment->start_time->getTimestamp() !== strtotime($record->payload['starts_at']))) {
                return 'superseded';
            }
        }
        if ($record->audience === 'account') {
            $user = User::find($record->user_id);
            if (! $user || $user->email !== $record->recipient
                || ($record->template === 'verify_email' && $user->hasVerifiedEmail())) {
                return 'account_changed';
            }
        }

        return null;
    }

    private function finish(Notification $record, string $status, ?string $providerId): void
    {
        DB::transaction(function () use ($record, $status, $providerId) {
            $updated = Notification::whereKey($record->id)->where('lock_token', $record->lock_token)
                ->update([
                    'status' => $status, 'provider_id' => $providerId,
                    'sent_at' => $status === 'sent' ? now() : null, 'failure_code' => null,
                    'locked_until' => null, 'lock_token' => null, 'next_attempt_at' => null,
                ]);
            if ($updated) {
                $record->attemptsHistory()->where('result', 'processing')
                    ->update(['result' => $status, 'finished_at' => now()]);
            }
        });
    }

    private function fail(Notification $record, EmailDeliveryFailure $failure): void
    {
        DB::transaction(function () use ($record, $failure) {
            $retry = $failure->transient && $record->attempts < 5;
            $delay = max($failure->retryAfter, config('notifications.backoff')[$record->attempts - 1] ?? 3600);
            $updated = Notification::whereKey($record->id)->where('lock_token', $record->lock_token)->update([
                'status' => $retry ? 'pending' : 'failed', 'failure_code' => $failure->reason,
                'next_attempt_at' => $retry ? now()->addSeconds($delay) : null,
                'locked_until' => null, 'lock_token' => null,
            ]);
            if ($updated) {
                $record->attemptsHistory()->where('result', 'processing')->update([
                    'result' => $retry ? 'retry_scheduled' : 'failed',
                    'code' => $failure->reason, 'finished_at' => now(),
                ]);
                Log::warning('Email notification delivery failed.', [
                    'notification_id' => $record->id, 'code' => $failure->reason, 'retry' => $retry,
                ]);
            }
        });
    }
}
