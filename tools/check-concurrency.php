<?php

use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestCategory;
use App\Models\Room;
use App\Models\RoomCalendarEntry;
use App\Models\TariffVersion;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

putenv('DB_DATABASE=cox_laravel_application_testing');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'cox_laravel_application_testing';
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'cox_laravel_application_testing') {
    throw new RuntimeException('Unsafe database.');
}
// This tool is intentionally restricted to a disposable, explicitly named test database.
Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);
$admin = User::create(['name' => 'Concurrency Admin', 'email' => 'race@example.test', 'password' => 'temporary-test-only-password', 'role' => 'admin']);
$other = User::create(['name' => 'Second Admin', 'email' => 'race2@example.test', 'password' => 'temporary-test-only-password', 'role' => 'admin']);
$guest = Guest::create(['name' => 'Concurrency Guest', 'mobile' => '+8801712345678']);
Room::query()->update(['bookable' => true, 'capacity' => 2, 'housekeeping' => 'ready']);
TariffVersion::query()->update(['active' => true, 'approved_at' => now(), 'approved_by' => $admin->id, 'effective_from' => '2026-01-01', 'calculation_mode' => 'progressive']);
auth()->login($admin);
$service = app(BookingService::class);
$data = ['guest_id' => $guest->id, 'guest_category_id' => GuestCategory::where('code', 'A')->value('id'), 'room_ids' => [Room::where('code', 'R201')->value('id')], 'check_in_at' => '2027-01-01 14:00:00', 'check_out_at' => '2027-01-05 12:00:00'];
$one = $service->create($data);
auth()->login($other);
$two = $service->create($data);
$one->update(['status' => 'pending_approval']);
$two->update(['status' => 'pending_approval']);
$barrier = storage_path('framework/testing/concurrency-'.bin2hex(random_bytes(5)));
if (! is_dir(dirname($barrier))) {
    mkdir(dirname($barrier), 0777, true);
}
$children = [];
foreach ([[$one, $admin], [$two, $other]] as [$booking,$actor]) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __DIR__.'/concurrency-worker.php', (string) $booking->id, (string) $actor->id, $barrier], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start worker.');
    }fclose($pipes[0]);
    $children[] = [$process, $pipes, $booking->id];
}
$deadline = microtime(true) + 15;
while ((! file_exists($barrier.'.'.$one->id.'.ready') || ! file_exists($barrier.'.'.$two->id.'.ready')) && microtime(true) < $deadline) {
    usleep(10000);
}touch($barrier.'.go');
$results = [];
foreach ($children as [$process,$pipes,$id]) {
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException($err.$out);
    }$results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}
$confirmed = Booking::where('status', 'confirmed')->count();
$entries = RoomCalendarEntry::where('active', true)->count();
foreach (glob($barrier.'.*') as $file) {
    unlink($file);
}
echo json_encode(['workers' => $results, 'confirmed_bookings' => $confirmed, 'active_allocations' => $entries], JSON_PRETTY_PRINT).PHP_EOL;
exit($confirmed === 1 && $entries === 1 && collect($results)->where('status', 409)->count() === 1 ? 0 : 1);
