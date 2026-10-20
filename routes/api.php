<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ReferenceController;
use App\Services\FinanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    Route::get('version', \App\Http\Controllers\ApiVersionController::class);
    Route::post('messaging/twilio/status', \App\Http\Controllers\MessageWebhookController::class);
    Route::post('auth/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1,forgot');
    Route::post('auth/reset-password', [AuthController::class, 'reset'])->middleware('throttle:6,1,auth');
    Route::post('auth/mobile/login', [AuthController::class, 'mobile'])->middleware('throttle:6,1,auth');
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/preferences', [\App\Http\Controllers\PreferenceController::class,'show']);
        Route::patch('auth/preferences', [\App\Http\Controllers\PreferenceController::class,'update']);
        Route::get('auth/me', fn (Request $r) => ['data' => $r->user()]);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/password', [AuthController::class, 'password']);
        Route::get('auth/devices', [AuthController::class, 'devices']);
        Route::delete('auth/devices/{id}', [AuthController::class, 'revoke']);
        Route::get('catalog', [AdminController::class, 'catalog']);
        Route::get('invoices', [ReferenceController::class, 'invoices']);
        Route::get('invoices/{id}', [ReferenceController::class, 'invoice']);
        Route::get('payments', [ReferenceController::class, 'payments'])->middleware('admin');
        Route::get('credit-notes', [ReferenceController::class, 'credits'])->middleware('admin');
        Route::get('settings', [ReferenceController::class, 'settings'])->middleware('admin');
        Route::get('{resource}', [ReferenceController::class, 'index'])->whereIn('resource', ['properties', 'buildings', 'floors', 'rooms', 'guest-categories']);
        Route::get('{resource}/{id}', [ReferenceController::class, 'show'])->whereIn('resource', ['properties', 'buildings', 'floors', 'rooms', 'guest-categories'])->whereNumber('id');
        Route::post('{resource}', [ReferenceController::class, 'save'])->whereIn('resource', ['properties', 'buildings', 'floors', 'rooms', 'guest-categories'])->middleware(['admin', 'idempotent']);
        Route::put('{resource}/{id}', [ReferenceController::class, 'save'])->whereIn('resource', ['properties', 'buildings', 'floors', 'rooms', 'guest-categories'])->whereNumber('id')->middleware(['admin', 'idempotent']);

        Route::get('dashboard', [AdminController::class, 'dashboard']);
        Route::get('availability', [BookingController::class, 'availability']);
        Route::get('calendar', [BookingController::class, 'calendar']);
        Route::get('guests/{id}', [\App\Http\Controllers\ApiReadController::class,'guest'])->whereNumber('id');
        Route::get('tariffs', [\App\Http\Controllers\ApiReadController::class,'tariffs']);
        Route::get('tariffs/{id}', [\App\Http\Controllers\ApiReadController::class,'tariff'])->whereNumber('id');
        Route::get('guests', [AdminController::class, 'guests']);
        Route::post('guests', [AdminController::class, 'guest']);
        Route::put('guests/{id}', [AdminController::class, 'guest']);
        Route::post('booking-quotes', [BookingController::class, 'quote']);
        Route::get('bookings', [BookingController::class, 'index']);
        Route::post('bookings', [BookingController::class, 'store'])->middleware('idempotent');
        Route::get('bookings/{id}', [BookingController::class, 'show'])->whereNumber('id');
        Route::get('bookings/{id}/guest-confirmation', \App\Http\Controllers\BookingConfirmationController::class)->whereNumber('id');
        Route::get('bookings/{id}/exports/{format}', [\App\Http\Controllers\BookingConfirmationController::class,'export'])->whereIn('format',['pdf','jpg'])->whereNumber('id')->middleware('throttle:12,1,exports');
        Route::get('bookings/{id}/share-options', [\App\Http\Controllers\BookingConfirmationController::class,'options'])->whereNumber('id');
        Route::get('bookings/{id}/shares', [\App\Http\Controllers\BookingConfirmationController::class,'history'])->whereNumber('id');
        Route::post('bookings/{id}/share', [\App\Http\Controllers\BookingConfirmationController::class,'share'])->whereNumber('id')->middleware(['idempotent','throttle:6,1,guest-share']);
        Route::patch('bookings/{id}', [BookingController::class, 'update'])->middleware('idempotent');
        Route::post('bookings/{id}/amendments', [BookingController::class, 'amendment'])->middleware('idempotent');
        Route::post('bookings/{id}/attachments', [BookingController::class, 'upload']);
        Route::get('attachments/{id}/download', [BookingController::class, 'download']);
        Route::post('bookings/{id}/{action}', [BookingController::class, 'transition'])->whereIn('action', ['submit', 'approve', 'reject', 'return-for-correction', 'cancel', 'mark-no-show', 'check-in', 'check-out'])->middleware('idempotent');
        Route::middleware('admin')->group(function () {
            Route::patch('bookings/{id}/approved-details', [BookingController::class, 'adminUpdate'])->middleware('idempotent');
            Route::patch('rooms/{id}', [AdminController::class, 'room']);
            Route::post('tariffs/{id}/activate', [AdminController::class, 'activate'])->middleware('idempotent');
            Route::post('tariffs/{id}/clone', [AdminController::class, 'cloneTariff'])->middleware('idempotent');
            Route::patch('tariffs/{id}/rates/{rateId}', [AdminController::class, 'rate']);
            Route::get('room-blocks', [AdminController::class, 'blocks']);
            Route::post('room-blocks', [AdminController::class, 'block'])->middleware('idempotent');
            Route::post('room-blocks/{id}/release', [AdminController::class, 'release'])->middleware('idempotent');
            Route::post('booking-amendments/{id}/{action}', [BookingController::class, 'decide'])->whereIn('action', ['approve', 'reject'])->middleware('idempotent');
            Route::post('bookings/{id}/credit-notes', function (Request $r, int $id, FinanceService $f) {
                return response()->json(['data' => $f->credit($id, $r->validate(['amount_minor' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']))], 201);
            })->middleware('idempotent');
            Route::post('bookings/{id}/payments', [BookingController::class, 'pay'])->middleware('idempotent');
            Route::post('payments/{id}/reverse', [BookingController::class, 'reverse'])->middleware('idempotent');
            Route::post('payments/{id}/refunds', [BookingController::class, 'refund'])->middleware('idempotent');
            Route::get('users', [AdminController::class, 'users']);
            Route::post('users', [AdminController::class, 'user'])->middleware('idempotent');
            Route::put('users/{id}', [AdminController::class, 'user']);
            Route::get('{entity}/{id}', [\App\Http\Controllers\ApiReadController::class,'detail'])->whereIn('entity',['users','payments','credit-notes','room-blocks','booking-amendments'])->whereNumber('id');
            Route::get('reports/export', [\App\Http\Controllers\ApiReadController::class,'csv']);
            Route::get('reports', [AdminController::class, 'report']);
            Route::get('audit-logs', [AdminController::class, 'audit']);
            Route::get('notifications',[AdminController::class, 'notifications']);
            Route::post('notifications/{id}/retry',[BookingController::class, 'retry'])->middleware('idempotent');
        });
    });
});
