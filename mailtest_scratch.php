<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Mail::raw('hello', function ($m) {
    $m->to('test@example.com')->subject('Sub');
});
try {
    Illuminate\Support\Facades\Mail::assertSent(Illuminate\Mail\Mailable::class);
    echo "ASSERT OK\n";
} catch (\Throwable $e) {
    echo 'ASSERT FAIL: '.$e->getMessage()."\n";
}
