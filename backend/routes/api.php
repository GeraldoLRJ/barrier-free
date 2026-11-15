<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DomProcessController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/process-dom', DomProcessController::class);

Route::post('/check-url', function (\Illuminate\Http\Request $request) {
    $data = $request->validate(['url' => ['required', 'url', 'max:2048']]);

    return response()->json([
        'safe' => app(\App\Services\SafeBrowsingService::class)->isSafe($data['url']),
    ]);
});
