<?php

namespace App\Console\Commands;

use App\Services\BookingNotifications;
use Illuminate\Console\Command;

class BackfillEmailNotifications extends Command
{
    protected $signature = 'notifications:backfill {--write : Enfileirar as confirmações retroativas}';

    protected $description = 'Simula ou enfileira confirmações de agendamentos futuros ainda não habilitados para e-mail';

    public function handle(BookingNotifications $notifications): int
    {
        $write = (bool) $this->option('write');
        if ($write && ! config('notifications.email_enabled')) {
            $this->error('Ative NOTIFICATIONS_EMAIL_ENABLED antes de enfileirar confirmações retroativas.');

            return self::FAILURE;
        }
        if ($write && config('mail.default') !== 'smtp') {
            $this->error('Configure MAIL_MAILER=smtp antes de enfileirar confirmações retroativas.');

            return self::FAILURE;
        }
        $count = 0;
        $notifications->retroactiveCandidates()->chunkById(100, function ($appointments) use ($notifications, $write, &$count) {
            foreach ($appointments as $appointment) {
                if (! $write || $notifications->retroactiveConfirmation($appointment->id)) {
                    $this->line("Agendamento #{$appointment->id}");
                    $count++;
                }
            }
        });
        $this->info(($write ? 'Agendamentos enfileirados' : 'Agendamentos elegíveis').": {$count}.");
        if (! $write) {
            $this->comment('Simulação: nenhum dado foi alterado. Use --write para enfileirar os e-mails.');
        }

        return self::SUCCESS;
    }
}
