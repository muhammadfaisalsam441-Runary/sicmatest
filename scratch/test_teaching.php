<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$request = Request::create('/admin/pembagian-mengajar', 'GET');
$app->instance('request', $request);

$admin = User::where('role', 'admin')->first();
auth()->guard('web')->setUser($admin);

$response = $kernel->handle($request);

echo 'Status: '.$response->getStatusCode()."\n";
if ($response->getStatusCode() !== 200) {
    echo substr($response->getContent(), 0, 1500)."\n";
} else {
    echo 'Content length: '.strlen($response->getContent())."\n";
    echo "Success! The page rendered properly.\n";
}
