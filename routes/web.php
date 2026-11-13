<?php

use App\Http\Controllers\BarberController;
use App\Http\Controllers\BarbershopController;
use App\Http\Controllers\BookingCancellationController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PrivacyRequestController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ServiceController;
use App\Models\Barbershop;
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
Route::get('politica-de-privacidade', fn () => Inertia::render('PrivacyPolicy', [
    'barbershops' => Barbershop::query()->where('is_open', true)->orderBy('name')
        ->get(['name', 'phones'])->map(fn (Barbershop $shop) => [
            'name' => $shop->name,
            'phones' => $shop->phones ?? [],
        ]),
]))->name('privacy');
Route::post('cancelar-agendamento', [BookingCancellationController::class, 'store'])
    ->middleware('throttle:booking-cancellation-request')->name('booking.cancellation.request');
Route::get('cancelar-agendamento/{token}/{target}', [BookingCancellationController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')->where('target', 'all|[1-9][0-9]*')
    ->middleware('throttle:60,1,booking-cancellation-link')->name('booking.cancellation.show');
Route::post('cancelar-agendamento/{token}/{target}', [BookingCancellationController::class, 'confirm'])
    ->where('token', '[a-f0-9]{64}')->where('target', 'all|[1-9][0-9]*')
    ->middleware('throttle:20,1,booking-cancellation-confirm')->name('booking.cancellation.confirm');
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
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('clients/{id}', [ClientController::class, 'show'])->whereNumber('id')->name('clients.show');
    Route::get('privacy-requests', [PrivacyRequestController::class, 'index'])->name('privacy-requests.index');
    Route::get('privacy-requests/create', [PrivacyRequestController::class, 'create'])->name('privacy-requests.create');
    Route::post('privacy-requests', [PrivacyRequestController::class, 'store'])->name('privacy-requests.store');
    Route::get('privacy-requests/{id}', [PrivacyRequestController::class, 'show'])->whereNumber('id')->name('privacy-requests.show');
    Route::patch('privacy-requests/{id}', [PrivacyRequestController::class, 'update'])->whereNumber('id')->name('privacy-requests.update');
    Route::post('privacy-requests/{id}/clients', [PrivacyRequestController::class, 'linkClient'])->whereNumber('id')->name('privacy-requests.clients');
    Route::delete('privacy-requests/{id}/clients/{clientId}', [PrivacyRequestController::class, 'unlinkClient'])->whereNumber(['id', 'clientId'])->name('privacy-requests.clients.unlink');
    Route::post('privacy-requests/{id}/complete', [PrivacyRequestController::class, 'complete'])->whereNumber('id')->name('privacy-requests.complete');
    Route::post('privacy-requests/{id}/reopen', [PrivacyRequestController::class, 'reopen'])->whereNumber('id')->name('privacy-requests.reopen');
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
    Route::post('schedule/appointments/{id}/no-show', [ScheduleController::class, 'noShow'])->name('schedule.appointments.no-show');
    Route::get('cash-register', [CashRegisterController::class, 'index'])->name('cash-register.index');
    Route::post('cash-register', [CashRegisterController::class, 'open'])->name('cash-register.open');
    Route::post('cash-register/{id}/withdrawals', [CashRegisterController::class, 'withdraw'])
        ->whereNumber('id')->name('cash-register.withdrawals.store');
    Route::post('cash-register/{id}/close', [CashRegisterController::class, 'close'])
        ->whereNumber('id')->name('cash-register.close');
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('inventory/supplies', [InventoryController::class, 'storeSupply'])->name('inventory.supplies.store');
    Route::put('inventory/supplies/{id}', [InventoryController::class, 'updateSupply'])
        ->whereNumber('id')->name('inventory.supplies.update');
    Route::post('inventory/movements', [InventoryController::class, 'storeMovement'])->name('inventory.movements.store');
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
