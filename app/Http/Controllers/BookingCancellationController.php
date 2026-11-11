<?php

namespace App\Http\Controllers;

use App\Services\BookingCancellations;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingCancellationController extends Controller
{
    public function store(Request $request, BookingCancellations $cancellations)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $cancellations->request($data['email']);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Caso haja algum agendamento para este endereço de e-mail, você receberá uma mensagem com as informações.',
        ]);

        return redirect()->route('home');
    }

    public function show(string $token, string $target, BookingCancellations $cancellations)
    {
        Inertia::encryptHistory();
        $grant = $cancellations->grant($token);
        $items = $cancellations->selection($grant, $target);
        $data = $items === null
            ? ['canCancel' => false, 'message' => 'Link inválido ou expirado. Solicite um novo e-mail.', 'appointments' => []]
            : $cancellations->preview($grant, $items);

        return Inertia::render('BookingCancellation', [
            ...$data, 'confirmUrl' => $items === null ? null : route('booking.cancellation.confirm', compact('token', 'target')),
        ])->toResponse(request())->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function confirm(string $token, string $target, BookingCancellations $cancellations)
    {
        $cancellations->cancel($token, $target);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cancelamento confirmado. Os horários não estão mais reservados.']);

        return redirect()->route('booking.cancellation.show', compact('token', 'target'));
    }
}
