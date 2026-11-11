<?php

use App\Http\Controllers\BarberController;
use App\Http\Controllers\BarbershopController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ServiceController;
use App\Models\BarbershopPhoto;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'photos' => BarbershopPhoto::query()->orderBy('id')->get(['id'])
        ->map(fn (BarbershopPhoto $photo) => ['url' => $photo->url]),
]))->name('home');

Route::get('agendar', [BookingController::class, 'index'])->name('booking');
Route::get('agendar/disponibilidade', [BookingController::class, 'availability'])
    ->middleware('throttle:120,1,booking-availability')->name('booking.availability');
Route::post('agendar', [BookingController::class, 'store'])
    ->middleware('throttle:10,1,booking-store')->name('booking.store');

Route::get('barber-photos/{user}', function (User $user) {
    abort_unless($user->photo_path && Storage::disk('public')->exists($user->photo_path), 404);

    return Storage::disk('public')->response($user->photo_path);
})->name('barber.photo');

Route::get('barbershop-photos/{photo}', [BarbershopController::class, 'showPhoto'])
    ->name('barbershop-photos.show');
Route::get('api/barbershops/{barbershop:slug}/gallery', [BarbershopController::class, 'publicGallery'])
    ->name('barbershops.gallery');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('notifications/{notification}/retry', [NotificationController::class, 'retry'])
        ->middleware('throttle:10,1')->name('notifications.retry');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/scheduled', [DashboardController::class, 'scheduled'])->name('dashboard.scheduled');
    Route::get('dashboard/export', [DashboardController::class, 'export'])->name('dashboard.export');
    Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('schedule/events', [ScheduleController::class, 'events'])->name('schedule.events');
    Route::post('schedule/appointments', [ScheduleController::class, 'storeAppointment'])->name('schedule.appointments.store');
    Route::post('schedule/appointments/{id}/checkout', [ScheduleController::class, 'checkout'])->name('schedule.appointments.checkout');
    Route::patch('schedule/appointments/{id}', [ScheduleController::class, 'updateAppointment'])->name('schedule.appointments.update');
    Route::post('schedule/blocks', [ScheduleController::class, 'storeBlock'])->name('schedule.blocks.store');
    Route::patch('schedule/blocks/{id}', [ScheduleController::class, 'updateBlock'])->name('schedule.blocks.update');
    Route::delete('schedule/blocks/{id}', [ScheduleController::class, 'destroyBlock'])->name('schedule.blocks.destroy');
    Route::post('barbershops/{barbershop}/photos', [BarbershopController::class, 'storePhotos'])
        ->name('barbershops.photos.store');
    Route::delete('barbershops/{barbershop}/photos/{photo}', [BarbershopController::class, 'destroyPhoto'])
        ->name('barbershops.photos.destroy');
    Route::resource('barbershops', BarbershopController::class);
    Route::resource('barbers', BarberController::class);
    Route::resource('services', ServiceController::class);
    Route::resource('products', ProductController::class);
});

require __DIR__.'/settings.php';
