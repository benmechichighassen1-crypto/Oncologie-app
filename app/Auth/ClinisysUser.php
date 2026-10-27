<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Utilisateur connecté, construit à partir d'une ligne de AccessControl.
 * Aucun mot de passe, hash ou sel n'est conservé dans l'objet ni en session.
 */
class ClinisysUser implements Authenticatable
{
    public function __construct(
        public readonly string $userName,
        public readonly string $description,
        public readonly ?string $codMed,
        public readonly ?string $nomMed,
        public readonly bool $admin,
    ) {
    }

    public function getAuthIdentifierName(): string
    {
        return 'userName';
    }

    public function getAuthIdentifier(): string
    {
        return $this->userName;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function nomAffiche(): string
    {
        return $this->nomMed ?: ($this->description ?: $this->userName);
    }
}
