<?php

namespace App\Providers;

use App\Auth\AccessControlUserProvider;
use App\Auth\VerificateurMotDePasse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class OncologieServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::provider('accesscontrol', fn ($app) => new AccessControlUserProvider($app->make(VerificateurMotDePasse::class)));
    }
}
