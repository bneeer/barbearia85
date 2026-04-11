<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $barbershopId = null;

        // Estratégia inicial: usuário autenticado
        if (auth()->check()) {
            $barbershopId = auth()->user()->barbershop_id;
        }

        // Bind no container (escopo da request)
        app()->instance('currentBarbershopId', $barbershopId);

        return $next($request);
    }
}
