<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$email = 'admin@cox-resthouse.test';
if (! User::where('email', $email)->exists()) {
    $password = bin2hex(random_bytes(12)).'!aA9';
    User::create(['name' => 'Rest House Administrator', 'email' => $email, 'password' => $password, 'role' => 'admin', 'active' => true]);
    file_put_contents(storage_path('app/local-admin-credentials.txt'), "Local administrator\nEmail: $email\nPassword: $password\n\nSign in and change this password in Account. Keep this file private.\n");
    echo "Local administrator created. Credentials stored privately under storage/app.\n";
} else {
    echo "Administrator already exists. Credentials unchanged.\n";
}
