<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows=[];foreach(\Illuminate\Support\Facades\Route::getRoutes() as $r){if(str_starts_with($r->uri(),'api/v1/'))$rows[]=['uri'=>$r->uri(),'methods'=>$r->methods(),'middleware'=>$r->gatherMiddleware(),'wheres'=>$r->wheres];}echo json_encode($rows,JSON_PRETTY_PRINT);
