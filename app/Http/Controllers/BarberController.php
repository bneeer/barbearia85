<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BarberController extends Controller
{
    public function index()
    {
        if (! auth()->user()->isAdmin()) {
            return redirect('/');
        }

        return Inertia::render('Barbers/Index', [
            'barbers' => User::where('role', '=', 'barber')
                ->where('barbershop_id', auth()->user()->barbershop_id)->select('id', 'name', 'email')->get()->values(),
        ]);
    }

    public function show(User $barber)
    {
        return Inertia::render('Barbers/Show', [
            'barber' => $barber,
        ]);
    }

    public function create()
    {
        if (! auth()->user()->isAdmin()) {
            return redirect('/');
        }

        return Inertia::render('Barbers/Create');
    }

    public function store(Request $request)
    {
        if (! auth()->user()->isAdmin()) {
            return redirect('/');
        }
        User::create([
            ...$request->validate([
                'name' => 'required',
                'email' => 'required|email|unique:users',
                'password' => 'required|min:6',
            ]),
            'password' => bcrypt($request->password),
            'role' => 'barber',
            'barbershop_id' => auth()->user()->barbershop_id,
        ]);

        return redirect()->route('barbers.index');
    }

    public function edit(User $barber)
    {
        if (! auth()->user()->isAdmin()) {
            return redirect('/');
        }

        return Inertia::render('Barbers/Edit', [
            'barber' => $barber,
        ]);
    }

    public function update(Request $request, User $barber)
    {
        if (! auth()->user()->isAdmin()) {
            return redirect('/');
        }
        $barber->update(
            $request->validate([
                'name' => 'required',
                'email' => 'required|email',
            ])
        );

        return redirect()->route('barbers.index');
    }

    public function destroy(User $barber)
    {
        if (! auth()->user()->isAdmin()) {
            return redirect('/');
        }
        $barber->delete();

        return redirect()->route('barbers.index');
    }
}
