<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Accès en lecture seule aux bases Clinisys (ERP, RDV, GED, GED_DOC).
 *
 * Les noms de bases viennent de config/oncologie.php ; ils sont validés avant
 * d'être injectés dans le SQL, les valeurs utilisateur passant toujours par
 * des paramètres liés.
 */
class Clinisys
{
    public static function db(): ConnectionInterface
    {
        return DB::connection(config('oncologie.connexion'));
    }

    /** Nom complet d'un objet : t('erp', 'dbo.Client') => Gclinique_Maroc.dbo.Client */
    public static function t(string $base, string $objet): string
    {
        $nom = config("oncologie.bases.$base");
        if (! is_string($nom) || ! preg_match('/^[A-Za-z0-9_]+$/', $nom)) {
            throw new InvalidArgumentException("Base Clinisys inconnue : $base");
        }
        if (! preg_match('/^[A-Za-z0-9_]+\.[A-Za-z0-9_]+$/', $objet)) {
            throw new InvalidArgumentException("Objet invalide : $objet");
        }

        $prefixe = (string) config('oncologie.prefixe');
        if ($prefixe !== '' && ! preg_match('/^\[[A-Za-z0-9_\-\.]+\]$/', $prefixe)) {
            throw new InvalidArgumentException('Préfixe de serveur lié invalide.');
        }

        return ($prefixe !== '' ? $prefixe.'.' : '').$nom.'.'.$objet;
    }

    /** Nom à 3 parties sans serveur lié, pour le SQL exécuté à distance par OPENQUERY. */
    public static function local(string $base, string $objet): string
    {
        $complet = self::t($base, $objet);
        $prefixe = (string) config('oncologie.prefixe');

        return $prefixe !== '' ? substr($complet, strlen($prefixe) + 1) : $complet;
    }

    /**
     * Exécute une requête d'agrégation sur le serveur Clinisys lui-même (OPENQUERY)
     * quand on passe par un serveur lié : le regroupement se fait là-bas, seules
     * les lignes agrégées transitent. Le SQL ne doit contenir que des valeurs
     * déjà validées (OPENQUERY n'accepte pas de paramètres).
     */
    public static function distant(string $sql): array
    {
        $prefixe = (string) config('oncologie.prefixe');
        if ($prefixe === '') {
            return self::db()->select($sql);
        }

        return self::db()->select('SELECT * FROM OPENQUERY('.$prefixe.", '".str_replace("'", "''", $sql)."')");
    }

    public static function collate(): string
    {
        return 'COLLATE '.config('oncologie.collation');
    }

    /** Liste de paramètres « ?, ?, ? » pour une clause IN. */
    public static function in(array $valeurs): string
    {
        return implode(', ', array_fill(0, max(count($valeurs), 1), '?'));
    }
}
