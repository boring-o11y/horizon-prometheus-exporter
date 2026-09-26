<?php

use BoringO11y\HorizonPrometheusExporter\Http\PrometheusController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PrometheusController::class, 'index'])->name('horizon.prometheus');
