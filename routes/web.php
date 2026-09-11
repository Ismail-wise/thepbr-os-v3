<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Foundation', [
    'foundation' => 'F0',
]))->name('foundation');
