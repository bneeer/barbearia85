<?php

namespace App\Http\Controllers;

use App\Services\ClientPortal;
use App\Services\LoyaltyProgram;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ClientPortalController extends Controller
{
    public function store(Request $request, ClientPortal $portal)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        if (! config('notifications.email_enabled') || config('mail.default') !== 'smtp') {
            return back()->withErrors(['email' => 'O serviço está temporariamente indisponível. Tente novamente mais tarde.']);
        }
        try {
            $portal->request($data['email']);
        } catch (QueryException $exception) {
            Log::error('Client portal request could not be recorded.', ['exception_class' => $exception::class]);

            return back()->withErrors(['email' => 'O serviço está temporariamente indisponível. Tente novamente mais tarde.']);
        }
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Se houver um cadastro e não existir um link válido, enviaremos as instruções por e-mail. Verifique sua caixa de entrada e spam.',
        ]);

        return redirect()->route('home');
    }

    public function show(Request $request, string $token, ClientPortal $portal, LoyaltyProgram $loyalty)
    {
        Inertia::encryptHistory();
        $grant = $portal->grant($token);

        return Inertia::render('ClientPortal', [
            'valid' => $grant !== null,
            'dashboard' => $grant ? $portal->dashboard($grant, $loyalty) : null,
        ])->toResponse($request);
    }
}
