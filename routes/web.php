<?php

use App\Http\Controllers\MigrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MigrationController::class, 'index'])->name('migration.index');
Route::get('/search', [MigrationController::class, 'index'])->name('tables.index');

Route::post('/migrate-structure/{table}', [MigrationController::class, 'migrateStructureToPostgres'])->name('migration.migrateStructure');
Route::post('/migrate-structures-customized/{table}', [MigrationController::class, 'index'])->name('migration.migrateStructureCustomized');
Route::post('/migrate-data/{table}', [MigrationController::class, 'migrateData'])->name('migration.migrateData');



