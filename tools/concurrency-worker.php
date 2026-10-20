<?php

use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('DB_DATABASE=cox_laravel_application_testing');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'cox_laravel_application_testing';
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'cox_laravel_application_testing') {
    throw new RuntimeException('Unsafe database.');
}
$id = (int) $argv[1];
$uid = (int) $argv[2];
$barrier = $argv[3];
auth()->loginUsingId($uid);
touch($barrier.'.'.$id.'.ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier.'.go') && microtime(true) < $deadline) {
    usleep(10000);
}
try {
    $b = app(BookingService::class)->transition($id, 'approve', ['version' => 1]);
    echo json_encode(['id' => $id, 'status' => 'confirmed']);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['id' => $id, 'status' => $e->getStatusCode()]);
}
