<?php

namespace App\Auth;

use App\Support\Clinisys;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\DB;

/**
 * Fournisseur d'utilisateurs basé sur la table Clinisys AccessControl
 * (lecture seule). Le médecin associé vient de Code_Medecin_Infirmier -> Medecin.
 */
class AccessControlUserProvider implements UserProvider
{
    public function __construct(private VerificateurMotDePasse $verificateur)
    {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        $ligne = $this->ligne((string) $identifier);

        return $ligne && $this->compteValide($ligne) ? $this->construire($ligne) : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        // Jamais d'écriture dans Clinisys.
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $userName = trim((string) ($credentials['username'] ?? ''));
        if ($userName === '') {
            return null;
        }
        $ligne = $this->ligne($userName);

        return $ligne && $this->compteValide($ligne) ? $this->construire($ligne) : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $secret = Clinisys::db()->selectOne(
            'SELECT hasched_password, salt, hash_iterations FROM '.Clinisys::t('erp', config('oncologie.auth.table')).' WHERE UserName = ?',
            [$user->getAuthIdentifier()]
        );

        return $secret !== null && $this->verificateur->verifier(
            (string) ($credentials['password'] ?? ''),
            $secret->hasched_password,
            $secret->salt,
            $secret->hash_iterations,
        ) !== null;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        // Le mot de passe appartient à Clinisys : rien à faire.
    }

    private function ligne(string $userName): ?object
    {
        return Clinisys::db()->selectOne(
            'SELECT a.UserName, a.Description, a.Code_Medecin_Infirmier, a.Actif, a.expire, a.CompteExpire,
                    a.dateExpirationCompte, m.CodMed, m.NomMed
             FROM '.Clinisys::t('erp', config('oncologie.auth.table')).' a
             LEFT JOIN '.Clinisys::t('erp', 'dbo.Medecin').' m ON m.CodMed = a.Code_Medecin_Infirmier
             WHERE a.UserName = ?',
            [$userName]
        );
    }

    private function compteValide(object $ligne): bool
    {
        if ((string) $ligne->Actif !== '1') {
            return false;
        }
        if ((int) ($ligne->expire ?? 0) === 1) {
            return false;
        }
        if ((int) ($ligne->CompteExpire ?? 0) === 1 && $ligne->dateExpirationCompte !== null
            && strtotime((string) $ligne->dateExpirationCompte) < time()) {
            return false;
        }

        return true;
    }

    private function construire(object $ligne): ClinisysUser
    {
        $admin = in_array(strtolower($ligne->UserName), array_map('strtolower', config('oncologie.auth.admins')), true)
            || DB::table('onco_acces')->where('user_name', $ligne->UserName)->where('admin', true)->exists();

        return new ClinisysUser(
            userName: $ligne->UserName,
            description: (string) $ligne->Description,
            codMed: $ligne->CodMed ?: null,
            nomMed: $ligne->NomMed ?: null,
            admin: $admin,
        );
    }
}
