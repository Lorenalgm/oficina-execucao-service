<?php

use Illuminate\Support\Facades\Route;

Route::middleware('jwt')->prefix('execucoes')->group(function () {
    Route::get('/', [\App\Http\Controllers\ExecucaoController::class, 'index']);
    Route::get('/{osId}', [\App\Http\Controllers\ExecucaoController::class, 'show']);
    Route::patch('/{osId}/status', [\App\Http\Controllers\ExecucaoController::class, 'avancar']);
});
