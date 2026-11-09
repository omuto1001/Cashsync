<?php

use App\Http\Controllers\BankApiController;
use Illuminate\Support\Facades\Route;

/*
| The bank server's addresses. Laravel puts "/api" in front of each one automatically,
| so '/withdraw' below becomes  http://127.0.0.1:8001/api/withdraw
|
| Only BANK servers have an API. The ATM app has screens instead (routes/web.php, Step 8).
*/
if (config('cashsync.mode') === 'bank') {

    // ---- Called by the ATMs ----
    Route::post('/withdraw', [BankApiController::class, 'withdraw']);
    Route::post('/deposit', [BankApiController::class, 'deposit']);
    Route::post('/transfer', [BankApiController::class, 'transfer']);
    Route::post('/balance', [BankApiController::class, 'balance']);  // POST so the PIN is not in the URL

    // ---- Called by the test tools (needs the admin key) ----
    Route::post('/adjust', [BankApiController::class, 'adjust']);

    // ---- Called by the OTHER bank server ----
    Route::post('/replicate', [BankApiController::class, 'replicate']); // "here is a copy"
    Route::get('/health', [BankApiController::class, 'health']);        // heartbeat
    Route::get('/snapshot', [BankApiController::class, 'snapshot']);    // "send me everything"
}