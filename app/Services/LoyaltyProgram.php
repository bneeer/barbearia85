<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentCheckout;
use App\Models\Barbershop;
use App\Models\Client;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Scopes\BarbershopScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class LoyaltyProgram
{
    private function identityHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'));
    }

    private function goal(Barbershop $barbershop): int
    {
        if ($barbershop->loyalty_stamp_goal < 1) {
            throw new LogicException('A meta do programa de fidelidade deve ser maior que zero.');
        }

        return $barbershop->loyalty_stamp_goal;
    }

    private function account(Barbershop $barbershop, string $email, bool $create = false): ?LoyaltyAccount
    {
        $identityHash = $this->identityHash($email);

        if ($create) {
            $now = now();
            DB::table('loyalty_accounts')->insertOrIgnore([
                'barbershop_id' => $barbershop->id,
                'identity_hash' => $identityHash,
                'stamps_balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return LoyaltyAccount::query()
            ->where('barbershop_id', $barbershop->id)
            ->where('identity_hash', $identityHash)
            ->lockForUpdate()
            ->first();
    }

    public function award(Appointment $appointment, AppointmentCheckout $checkout): void
    {
        if ($appointment->status !== 'completed'
            || $checkout->appointment_id !== $appointment->id
            || $checkout->total_cents < 1) {
            return;
        }

        $barbershop = Barbershop::find($appointment->barbershop_id);
        $client = Client::withoutGlobalScope(BarbershopScope::class)->find($appointment->client_id);
        if (! $barbershop?->loyalty_enabled
            || ! $barbershop->loyalty_enabled_at
            || $checkout->created_at->lt($barbershop->loyalty_enabled_at)
            || ! $client?->email
            || LoyaltyTransaction::where('appointment_checkout_id', $checkout->id)->exists()) {
            return;
        }

        $this->goal($barbershop);
        DB::transaction(function () use ($barbershop, $client, $checkout) {
            $account = $this->account($barbershop, $client->email, create: true);
            if (! $account || LoyaltyTransaction::where('appointment_checkout_id', $checkout->id)->exists()) {
                return;
            }

            $account->increment('stamps_balance');
            $account->transactions()->create([
                'client_id' => $client->id,
                'appointment_checkout_id' => $checkout->id,
                'type' => 'earned',
                'stamps' => 1,
            ]);
        }, 3);
    }

    private function progress(Barbershop $barbershop, ?LoyaltyAccount $account): array
    {
        $balance = $account?->stamps_balance ?? 0;
        $goal = $this->goal($barbershop);

        return [
            'barbershopId' => $barbershop->id,
            'barbershop' => $barbershop->name,
            'enabled' => $barbershop->loyalty_enabled,
            'goal' => $goal,
            'reward' => $barbershop->loyalty_reward_description,
            'stampsBalance' => $balance,
            'stampsProgress' => $balance % $goal,
            'rewardsAvailable' => intdiv($balance, $goal),
        ];
    }

    public function forClient(Client $client): ?array
    {
        $barbershop = Barbershop::find($client->barbershop_id);
        $account = $client->email && $barbershop
            ? $this->account($barbershop, $client->email)
            : null;

        return $barbershop ? $this->progress($barbershop, $account) : null;
    }

    public function forEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $barbershopIds = DB::table('clients')->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->distinct()->pluck('barbershop_id');
        $barbershops = Barbershop::query()->whereIn('id', $barbershopIds)->orderBy('name')->get();

        return $barbershops->map(function (Barbershop $barbershop) use ($email) {
            $account = $this->account($barbershop, $email);
            if (! $barbershop->loyalty_enabled && ! $account?->stamps_balance) {
                return null;
            }

            return $this->progress($barbershop, $account);
        })->filter()->values()->all();
    }

    public function redeem(Client $client, int $userId): void
    {
        if (! $client->email) {
            throw ValidationException::withMessages(['loyalty' => 'O cliente precisa ter um e-mail cadastrado para participar do programa.']);
        }

        DB::transaction(function () use ($client, $userId) {
            $barbershop = Barbershop::query()->lockForUpdate()->findOrFail($client->barbershop_id);
            if (! $barbershop->loyalty_enabled) {
                throw ValidationException::withMessages(['loyalty' => 'O programa de fidelidade desta unidade está desabilitado.']);
            }

            $account = $this->account($barbershop, $client->email);
            if ($client->trashed()) {
                throw ValidationException::withMessages(['loyalty' => 'Não é possível resgatar recompensas de um cadastro arquivado.']);
            }

            $goal = $this->goal($barbershop);
            if (! $account || $account->stamps_balance < $goal) {
                throw ValidationException::withMessages(['loyalty' => 'O cliente ainda não tem carimbos suficientes para resgatar uma recompensa.']);
            }

            $account->decrement('stamps_balance', $goal);
            $account->transactions()->create([
                'client_id' => $client->id,
                'created_by' => $userId,
                'type' => 'redeemed',
                'stamps' => -$goal,
                'reward_description' => $barbershop->loyalty_reward_description,
            ]);
        }, 3);
    }
}
