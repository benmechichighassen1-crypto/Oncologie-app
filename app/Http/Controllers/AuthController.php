<?php

namespace App\Http\Controllers;

use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Connexion avec le login et le mot de passe Clinisys (AccessControl). */
class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.connexion');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $cle = 'connexion:'.mb_strtolower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($cle, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($cle).' secondes.',
            ]);
        }

        if (! Auth::attempt(['username' => $data['username'], 'password' => $data['password']])) {
            RateLimiter::hit($cle, 300);
            Journal::ecrire('connexion_refusee', ['details' => ['login' => $data['username']]], $data['username']);
            throw ValidationException::withMessages(['username' => 'Identifiant ou mot de passe incorrect, ou compte inactif.']);
        }

        RateLimiter::clear($cle);
        $request->session()->regenerate();
        Journal::ecrire('connexion');

        return redirect()->intended(route('planning'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Journal::ecrire('deconnexion');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
