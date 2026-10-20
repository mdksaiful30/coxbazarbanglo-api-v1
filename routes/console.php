<?php

use App\Jobs\SendGuestNotification;
use App\Models\NotificationDelivery;
use App\Models\RoomBlock;
use App\Models\RoomCalendarEntry;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('resthouse:create-admin {email} {--name=Administrator}', function () {
    $email = $this->argument('email');
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists; nothing changed.');

        return 1;
    }
    $password = $this->secret('Choose an admin password (minimum 12 characters)');
    if (strlen($password ?? '') < 12) {
        $this->error('Password must have at least 12 characters.');

        return 1;
    }
    User::create(['email' => $email, 'name' => $this->option('name'), 'password' => $password, 'role' => 'admin', 'active' => true]);
    $this->info('Administrator created.');
});
Artisan::command('resthouse:dispatch-notifications', function () {
    NotificationDelivery::where('status', 'queued')->pluck('id')->each(function ($id) {
        DB::transaction(function () use ($id) {
            $n = NotificationDelivery::lockForUpdate()->find($id);
            if ($n->status !== 'queued') {
                return;
            } $n->update(['status' => 'processing']);
            SendGuestNotification::dispatch($id)->afterCommit();
        });
    });
    $this->info('Queued deliveries dispatched.');
});
Artisan::command('resthouse:expire-blocks', function () {
    // Lock rooms before blocks, consistently with the interactive reservation workflow.
    RoomBlock::where('status', 'active')->pluck('id')->each(function ($id) {
        DB::transaction(function () use ($id) {
            $entries = RoomCalendarEntry::where('room_block_id', $id)->get();
            app(AvailabilityService::class)->lockRooms($entries->pluck('room_id')->all());
            $b = RoomBlock::lockForUpdate()->find($id);
            if ($b->status === 'active' && ! $entries->contains(fn ($e) => $e->ends_at > now()->format('Y-m-d H:i:s'))) {
                $b->update(['status' => 'expired']);
                $b->entries()->update(['active' => false]);
            }
        });
    });
    $this->info('Expired room blocks released.');
});
Schedule::command('resthouse:dispatch-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('resthouse:expire-blocks')->everyMinute()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
