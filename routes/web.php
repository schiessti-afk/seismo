<?php

declare(strict_types=1);

use App\Livewire\LiveMonitor;
use Illuminate\Support\Facades\Route;

Route::get('/', LiveMonitor::class)->name('home');
