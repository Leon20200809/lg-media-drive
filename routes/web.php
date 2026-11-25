<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DriveController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.store');
});

// プレビュー・原本取得も一覧と同じ認証境界に置く。
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DriveController::class, 'index'])->name('drive.index');
    Route::post('/items', [DriveController::class, 'upload'])->name('drive.upload');
    Route::post('/folders', [DriveController::class, 'folder'])->name('drive.folder');
    Route::get('/items/{item}/preview', [DriveController::class, 'preview'])->whereNumber('item')->name('drive.preview');
    Route::get('/items/{item}/download', [DriveController::class, 'download'])->whereNumber('item')->name('drive.download');
    Route::patch('/items/{item}/rename', [DriveController::class, 'rename'])->whereNumber('item')->name('drive.rename');
    Route::patch('/items/{item}/move', [DriveController::class, 'move'])->whereNumber('item')->name('drive.move');
    Route::delete('/items/{item}', [DriveController::class, 'trash'])->whereNumber('item')->name('drive.trash');
    Route::post('/items/{item}/restore', [DriveController::class, 'restore'])->whereNumber('item')->name('drive.restore');
});
