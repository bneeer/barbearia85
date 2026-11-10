<?php

namespace App\Console\Commands;

use App\Models\Barbershop;
use App\Models\Client;
use App\Models\Notification;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use InvalidArgumentException;

class NormalizePhones extends Command
{
    protected $signature = 'phones:normalize {--write : Gravar os números normalizados}';

    protected $description = 'Verifica telefones legados e, com --write, converte os válidos para E.164';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $changed = 0;
        $invalid = 0;

        foreach ([User::class => 'phone', Client::class => 'phone', Barbershop::class => 'phones'] as $class => $field) {
            $class::withoutGlobalScopes()->chunkById(100, function ($records) use ($field, $write, &$changed, &$invalid) {
                foreach ($records as $record) {
                    $value = $record->getAttribute($field);
                    if ($value === null || $value === '' || $value === []) {
                        continue;
                    }
                    try {
                        $normalized = $this->normalize($value);
                    } catch (InvalidArgumentException) {
                        $this->warn("Revisão manual: {$record->getTable()} #{$record->getKey()} ($field).");
                        $invalid++;

                        continue;
                    }
                    if ($value !== $normalized) {
                        $changed++;
                        if ($write) {
                            $record->setAttribute($field, $normalized);
                            $record->save();
                        }
                    }
                }
            });
        }

        Notification::where('status', 'pending')->chunkById(100, function ($records) use ($write, &$changed, &$invalid) {
            foreach ($records as $record) {
                $payload = $record->payload;
                try {
                    if ($record->type === 'whatsapp' && isset($payload['recipient'])) {
                        $payload['recipient'] = PhoneNumber::normalize($payload['recipient']);
                    }
                    if (! empty($payload['phones'])) {
                        $payload['phones'] = $this->normalize($payload['phones']);
                    }
                } catch (InvalidArgumentException) {
                    $this->warn("Revisão manual: notifications #{$record->id} (payload).");
                    $invalid++;

                    continue;
                }
                if ($payload !== $record->payload) {
                    $changed++;
                    if ($write) {
                        Notification::whereKey($record->id)->where('status', 'pending')->update(['payload' => $payload]);
                    }
                }
            }
        });

        $this->info(($write ? 'Registros normalizados' : 'Registros a normalizar').": $changed. Pendências manuais: $invalid.");
        if (! $write) {
            $this->comment('Simulação: nenhum dado foi alterado. Use --write para gravar os números válidos.');
        }

        return $invalid ? self::FAILURE : self::SUCCESS;
    }

    private function normalize(string|array $value): string|array
    {
        return is_array($value)
            ? array_values(array_unique(array_map(PhoneNumber::normalize(...), $value)))
            : PhoneNumber::normalize($value);
    }
}
