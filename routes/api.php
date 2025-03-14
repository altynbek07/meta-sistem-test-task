<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileUploadController;

Route::prefix('upload')->group(function () {
    Route::post('/init', [FileUploadController::class, 'initUpload'])->name('upload.init');
    Route::post('/chunk/{uploadId}', [FileUploadController::class, 'uploadChunk'])->name('upload.chunk');
    Route::post('/finalize/{uploadId}', [FileUploadController::class, 'finalizeUpload'])->name('upload.finalize');
    Route::get('/status/{uploadId}', [FileUploadController::class, 'getUploadStatus'])->name('upload.status');
});