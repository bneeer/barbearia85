<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\BarberPhoto;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'barbershop' => function () {
                return auth()->user()?->barbershop
                    ? [
                        'id' => auth()->user()->barbershop->id,
                        'slug' => auth()->user()->barbershop->slug,
                    ]
                    : null;
            },
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->except('photo'));
        $oldPhoto = $user->photo_path;

        if ($request->hasFile('photo')) {
            $user->photo_path = BarberPhoto::store($request->file('photo'));
        }

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($request->hasFile('photo') && $oldPhoto) {
            Storage::disk('public')->delete($oldPhoto);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perfil atualizado.']);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();
        if ($user->photo_path) {
            Storage::disk('public')->delete($user->photo_path);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
