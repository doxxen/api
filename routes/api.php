<?php
use App\Http\Controllers\RutController;

Route::get('/rut/{rut}', [RutController::class, 'getChileRutData']);
