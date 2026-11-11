<?php

namespace App\Mail;

use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NotificationEmail extends Mailable
{
    public function __construct(public Notification $record) {}

    public function render(): string
    {
        return view('mail.notification', $this->data())->render();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title());
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notification',
            text: 'mail.notification-text',
            with: $this->data(),
        );
    }

    public function title(): string
    {
        return match ($this->record->template) {
            'verify_email' => 'Verifique seu e-mail',
            'reset_password' => 'Redefina sua senha',
            'password_changed' => 'Sua senha foi alterada',
            'booking_confirmation' => 'Agendamento confirmado',
            'booking_rescheduled' => 'Agendamento reagendado',
            'booking_cancellation' => 'Agendamento cancelado',
            default => throw new \LogicException('Unknown email template.'),
        };
    }

    public function data(): array
    {
        $payload = $this->record->payload ?? [];
        $account = in_array($this->record->template, ['verify_email', 'reset_password', 'password_changed']);
        $description = match ($this->record->template) {
            'verify_email' => 'Confirme seu endereço de e-mail para acessar sua conta com segurança.',
            'reset_password' => 'Recebemos uma solicitação para redefinir sua senha. Se não foi você, ignore esta mensagem.',
            'password_changed' => 'A senha da sua conta foi alterada. Se você não reconhece esta ação, redefina sua senha e entre em contato com a barbearia.',
            'booking_confirmation' => $this->record->audience === 'client'
                ? 'Seu horário está reservado. Esperamos você para cuidar do seu estilo!'
                : 'Um novo atendimento foi confirmado na agenda da unidade.',
            'booking_rescheduled' => 'O horário do atendimento foi atualizado. Confira os novos detalhes abaixo.',
            'booking_cancellation' => 'O atendimento foi cancelado. Este horário não está mais reservado.',
        };
        $date = fn ($value) => $value
            ? CarbonImmutable::parse($value)->setTimezone($payload['timezone'])->format('d/m/Y \à\s H:i')
            : null;

        return [
            'title' => $this->title(), 'description' => $description, 'details' => $payload,
            'account' => $account, 'audience' => $this->record->audience,
            'startsAt' => $account ? null : $date($payload['starts_at'] ?? null),
            'previousStartsAt' => $account ? null : $date($payload['previous_starts_at'] ?? null),
            'actionUrl' => $this->record->secret['url'] ?? ($this->record->audience === 'client' ? null : ($account ? null : route('schedule.index'))),
            'actionLabel' => match ($this->record->template) {
                'verify_email' => 'Verificar e-mail',
                'reset_password' => 'Redefinir senha',
                default => 'Abrir agenda',
            },
            'expiresAt' => $this->record->expires_at?->setTimezone(config('app.display_timezone', 'America/Sao_Paulo'))->format('d/m/Y H:i T'),
        ];
    }
}
