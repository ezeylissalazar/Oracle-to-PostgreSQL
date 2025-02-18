<?php

use App\Http\Controllers\MigrationController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });
Route::get('/', [MigrationController::class, 'index'])->name('migration.index');
Route::post('/migration/migrate/{table}', [MigrationController::class, 'migrate'])->name('migration.migrate');
Route::get('/search', [MigrationController::class, 'index'])->name('tables.index');

Route::post('/migrate-structure/{table}', [MigrationController::class, 'migrateStructureToPostgres'])->name('migration.migrateStructure');
Route::post('/migrate-data/{table}', [MigrationController::class, 'migrateData'])->name('migration.migrateData');



