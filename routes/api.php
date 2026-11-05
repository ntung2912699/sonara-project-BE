<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AudioProcessingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\SongReportController;
use App\Http\Controllers\Api\TikTokImportController;
use App\Http\Controllers\Api\TrackController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/register/verify', [AuthController::class, 'verifyRegistration']);
Route::post('/register/resend-otp', [AuthController::class, 'resendRegistrationOtp']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/{provider}', [SocialAuthController::class, 'authenticate'])
    ->whereIn('provider', ['facebook', 'tiktok', 'apple']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::get('/tracks', [TrackController::class, 'index']);
Route::get('/tracks/{track}', [TrackController::class, 'show']);
Route::get('/tracks/{track}/play', [TrackController::class, 'play'])
    ->middleware('signed:relative')
    ->name('tracks.play');

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/me/downloads', [TrackController::class, 'downloads']);
    Route::get('/me/tracks', [TrackController::class, 'myUploads']);
    Route::post('/tracks', [TrackController::class, 'store']);
    Route::delete('/tracks/{track}', [TrackController::class, 'destroyUploaded']);
    Route::post('/tracks/{track}/reports', [SongReportController::class, 'store']);
    Route::get('/tracks/{track}/download', [TrackController::class, 'download']);
    Route::get('/tracks/{track}/play-url', [TrackController::class, 'playUrl']);
    Route::post('/audio-processing', [AudioProcessingController::class, 'store']);
    Route::get('/audio-processing/{audioProcessing}', [AudioProcessingController::class, 'show']);
    Route::get('/audio-processing/{audioProcessing}/files/{format}', [AudioProcessingController::class, 'download']);

    Route::get('/me/favorites', [FavoriteController::class, 'index']);
    Route::post('/tracks/{track}/favorite', [FavoriteController::class, 'store']);
    Route::delete('/tracks/{track}/favorite', [FavoriteController::class, 'destroy']);

    Route::apiResource('/playlists', PlaylistController::class);
    Route::post('/playlists/{playlist}/tracks/{track}', [PlaylistController::class, 'addTrack']);
    Route::delete('/playlists/{playlist}/tracks/{track}', [PlaylistController::class, 'removeTrack']);

    Route::get('/me/tiktok-imports', [TikTokImportController::class, 'index']);
    Route::post('/tiktok-imports', [TikTokImportController::class, 'store']);

    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/users', [AdminController::class, 'users']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
        Route::get('/tracks', [AdminController::class, 'tracks']);
        Route::patch('/tracks/{track}', [AdminController::class, 'updateTrack']);
        Route::delete('/tracks/{track}', [AdminController::class, 'destroyTrack']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::patch('/reports/{songReport}', [AdminController::class, 'updateReport']);
    });
});
