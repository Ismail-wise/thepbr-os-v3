<?php

namespace App\Presentation\Http\Middleware;

use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded for the first Inertia page visit.
     *
     * @var string
     */
    protected $rootView = 'app';
}
