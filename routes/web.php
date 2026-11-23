<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\StoreGenerationController;
use Illuminate\Support\Facades\Route;

// The studio is the site: the root opens History, which sends guests to the login page.
Route::redirect('/', '/generations')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Fortify's home after login and sign-up; History is the studio's landing page.
    Route::redirect('dashboard', '/generations')->name('dashboard');

    // Owner session in brackets; see docs/CONTRACTS.md.
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index'); // S6
    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create'); // S6
    Route::get('generations/{generation}', [GenerationController::class, 'show'])->name('generations.show'); // S6
    Route::post('generations', StoreGenerationController::class)->name('generations.store'); // S4
    Route::post('estimate', EstimateController::class)->name('estimate'); // S4

    Route::get('credits', [CreditsController::class, 'index'])->name('credits.index'); // S3
    Route::post('credits/purchase', [CreditsController::class, 'purchase'])->name('credits.purchase'); // S3

    Route::get('admin/report', AdminReportController::class)->name('admin.report'); // S7
});

require __DIR__.'/settings.php';
