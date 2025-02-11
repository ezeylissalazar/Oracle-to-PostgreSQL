<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/', [TestController::class, 'index'])->name('migration.index');
Route::post('/migration/migrate/{table}', [TestController::class, 'migrate'])->name('migration.migrate');
Route::get('/search', [TestController::class, 'index'])->name('tables.index');


