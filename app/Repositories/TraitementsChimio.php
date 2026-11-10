<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Traitements de chimiothérapie (base de l'appli, jamais Clinisys) :
 * traitement > cures > jours (J1, J15…) > produits.
 */
class TraitementsChimio
{
    public const TYPES_DOSE = ['mg/m²', 'mg/kg', 'AUC', 'DT'];
    public const VOIES = ['IV', 'IV lente', 'Perfusion', 'SC', 'IM', 'Per os', 'Baxter'];
    public const SOLVANTS = ['G5%', 'NaCl 0,9%', 'Baxter'];
    public const VOLUMES = ['50 cc', '100 cc', '200 cc', '250 cc', '300 cc', '350 cc', '400 cc', '450 cc', '500 cc', '1000 cc'];
    public const CLAIRANCE_MAX = 125.0;
    /** Plafonds usuels par prise (mg) : simple avertissement à l'écran, le médecin décide. */
    public const PLAFONDS = ['VINCRISTINE' => 2.0];

    public function __construct(private ChimioRepository $chimio)
    {
    }

    /** Traitements du patient, du plus récent au plus ancien, avec leurs cures et leur statut. */
    public function duPatient(string $identifiant): array
    {
        $traitements = DB::table('onco_traitements')->where('identifiant', $identifiant)->whereNull('masque_le')->orderByDesc('date_debut')->orderByDesc('id')->get();
        $cures = DB::table('onco_traitement_cures')->whereIn('traitement_id', $traitements->pluck('id'))->orderBy('numero')->get();
        $jours = DB::table('onco_traitement_jours')->whereIn('cure_id', $cures->pluck('id'))->get()->groupBy('cure_id');
        foreach ($cures as $c) {
            $c->statut = self::statutCure($jours[$c->id] ?? collect());
        }
        $parTraitement = $cures->groupBy('traitement_id');
        $administres = DB::table('onco_traitement_produits as p')->join('onco_traitement_jours as j', 'j.id', '=', 'p.jour_id')
            ->join('onco_traitement_cures as c', 'c.id', '=', 'j.cure_id')->whereIn('c.traitement_id', $traitements->pluck('id'))
            ->where('p.administre', true)->distinct()->pluck('c.traitement_id')->map(fn ($id) => (int) $id)->all();
        foreach ($traitements as $t) {
            $t->cures = ($parTraitement[$t->id] ?? collect())->values()->all();
            $t->administre = in_array((int) $t->id, $administres, true);
        }

        return $traitements->all();
    }

    public static function statutCure($jours): string
    {
        $ok = collect($jours)->where('ok_chimio', true)->count();

        return $ok === 0 ? 'NON ENTAMÉE' : ($ok === collect($jours)->count() ? 'FINALISÉE' : 'EN COURS');
    }

    /** « 2-J08 » => [cycle 2, jour 8]. */
    public static function decoderJour(string $code): array
    {
        return preg_match('/^(\d+)-J0*(\d+)$/i', trim($code), $m) ? [(int) $m[1], (int) $m[2]] : [1, 1];
    }

    public static function dateFinPrevue(string $debut, int $nbCures, int $intercure, array $jours): string
    {
        return CarbonImmutable::parse($debut)->addDays(($nbCures - 1) * $intercure + max($jours) - 1)->toDateString();
    }

    /** Dose selon la formule de Clinisys pour mg/m² ; AUC par Calvert, seulement si la clairance est connue. */
    public static function doseCalculee(string $type, ?string $reference, float $sc, float $poids, ?float $clairance = null): ?float
    {
        $base = self::nombre($reference);
        if ($base === null || $base <= 0) {
            return null;
        }

        return match ($type) {
            'mg/m²' => round($base * $sc, 2),
            'mg/kg' => round($base * $poids, 2),
            'DT' => $base,
            // Calvert : AUC × (clairance + 25), clairance plafonnée à 125 ml/min (dose maximale = AUC × 150).
            'AUC' => $clairance > 0 ? round($base * (min($clairance, self::CLAIRANCE_MAX) + 25), 2) : null,
            default => null,
        };
    }

    /** Valeur numérique d'un texte de dose (« 4 592,74 », « 350 mg ») ; null si ce n'est pas une dose chiffrée. */
    public static function nombre(?string $texte): ?float
    {
        $t = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $texte));

        return preg_match('/^(\d+(?:\.\d+)?)(?:mg)?$/i', $t, $m) ? (float) $m[1] : null;
    }

    /** Écart de la dose retenue par rapport au calcul, en % (affiché à l'écran, jamais imprimé). */
    public static function ecart(object $p): ?float
    {
        $dose = self::nombre($p->dose);
        if ($dose === null || ! $p->dose_calculee || (float) $p->dose_calculee <= 0) {
            return null;
        }
        $e = round(($dose - (float) $p->dose_calculee) / (float) $p->dose_calculee * 100, 1);

        return $e == 0.0 ? null : $e;
    }

    public static function depassePlafond(object $p): ?float
    {
        $dose = self::nombre($p->dose);
        foreach (self::PLAFONDS as $dci => $max) {
            if ($dose !== null && $dose > $max && str_contains(mb_strtoupper($p->medicament), $dci)) {
                return $max;
            }
        }

        return null;
    }

    /** Une ligne du protocole Clinisys, convertie en produit pour ce patient. */
    public static function produitDepuisLigne(array $l, float $sc, float $poids, int $ordre, ?float $clairance = null): array
    {
        $parKg = preg_match('/^([\d.,]+)\s*mg\s*\/\s*kg/i', $l['dose_initiale'], $k) ? str_replace(',', '.', $k[1]) : null;
        if ($l['dose_m2'] !== null) {
            [$type, $ref] = ['mg/m²', (string) $l['dose_m2']];
        } elseif ($l['auc'] !== null) {
            [$type, $ref] = ['AUC', (string) $l['auc']];
        } elseif ($parKg !== null) {
            [$type, $ref] = ['mg/kg', $parKg];
        } else {
            [$type, $ref] = ['DT', self::nombre($l['dose']) !== null ? (string) self::nombre($l['dose']) : null];
        }
        $calcul = self::doseCalculee($type, $ref, $sc, $poids, $clairance);
        [$voie, $solvant, $volume] = self::lireDilution($l['dilution']);

        return [
            'ordre' => $ordre, 'medicament' => mb_substr($l['medicament'], 0, 150), 'chimio' => $l['chimio'],
            'dose_ref' => $ref, 'type_dose' => $type, 'dose_calculee' => $calcul,
            'dose' => $calcul !== null ? self::texte($calcul) : (mb_substr($l['dose'], 0, 80) ?: null),
            'voie' => $voie, 'solvant' => $solvant, 'volume' => $volume, 'duree' => mb_substr($l['duree'], 0, 40) ?: null,
        ];
    }

    /** Ligne saisie à l'écran de création : la dose retenue par le médecin est gardée, le calcul est refait ici. */
    public static function produitSaisi(array $l, float $sc, float $poids, int $ordre, ?float $clairance): array
    {
        $vide = fn ($k) => ($v = trim((string) ($l[$k] ?? ''))) === '' ? null : $v;

        return [
            'ordre' => $ordre, 'medicament' => mb_substr(trim($l['medicament']), 0, 150), 'chimio' => (bool) ($l['chimio'] ?? false),
            'dose_ref' => $vide('dose_ref'), 'type_dose' => $l['type_dose'],
            'dose_calculee' => self::doseCalculee($l['type_dose'], $vide('dose_ref'), $sc, $poids, $clairance),
            'dose' => $vide('dose'), 'voie' => $vide('voie'), 'solvant' => $vide('solvant'), 'volume' => $vide('volume'), 'duree' => $vide('duree'),
        ];
    }

    public static function texte(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** « mg dans 250cc G5% » => voie, solvant, volume. Le texte libre non reconnu reste à compléter à l'écran. */
    public static function lireDilution(string $d): array
    {
        $voie = preg_match('/^\s*SC\s*$/i', $d) ? 'SC' : (preg_match('/baxter/i', $d) ? 'Baxter' : null);
        $solvant = preg_match('/baxter/i', $d) ? 'Baxter' : (preg_match('/G\s*5/i', $d) ? 'G5%' : (preg_match('/\b(ss|nacl|s\.s)\b/i', $d) ? 'NaCl 0,9%' : null));
        $volume = preg_match('/(\d{2,4})\s*(cc|ml)/i', $d, $m) ? $m[1].' cc' : null;

        return [$voie, $solvant, $volume];
    }

    /** Crée le traitement et toutes ses cures, jours et produits. */
    public function creer(array $d, string $identifiant, ?string $numDoss, string $nomProtocole, array $jours, array $lignes, string $user, string $nomUser): int
    {
        $sc = ChimioRepository::surfaceCorporelle((float) $d['poids'], (float) $d['taille']);
        // Lignes par cycle et par jour : presque tous les protocoles Clinisys ne décrivent que « 1-J01 ».
        $parCycle = collect($lignes)->groupBy(fn ($l) => self::decoderJour((string) ($l['jour'] ?? ''))[0]);
        $nbCycles = max(1, (int) $parCycle->keys()->max());

        return DB::transaction(function () use ($d, $identifiant, $numDoss, $nomProtocole, $jours, $parCycle, $nbCycles, $sc, $user, $nomUser) {
            $id = DB::table('onco_traitements')->insertGetId([
                'identifiant' => $identifiant, 'num_doss' => $numDoss, 'cod_prot' => $d['cod_prot'], 'nom_protocole' => mb_substr($nomProtocole, 0, 150),
                'nb_cures' => $d['nb_cures'], 'intercure' => $d['intercure'], 'jours_cycle' => implode(',', $jours),
                'date_debut' => $d['date_debut'], 'date_fin_prevue' => self::dateFinPrevue($d['date_debut'], $d['nb_cures'], $d['intercure'], $jours),
                'rechute' => (bool) ($d['rechute'] ?? false), 'localisation' => $d['localisation'] ?? null, 'histologie' => $d['histologie'] ?? null,
                'stade' => $d['stade'] ?? null, 'tnm' => $d['tnm'] ?? null, 'allergies' => $d['allergies'] ?? null, 'observation' => $d['observation'] ?? null,
                'statut' => 'actif', 'prescrit_par' => $user, 'prescrit_nom' => $nomUser, 'saisi_le' => now(),
            ]);
            $debut = CarbonImmutable::parse($d['date_debut']);
            for ($n = 1; $n <= $d['nb_cures']; $n++) {
                $debutCure = $debut->addDays(($n - 1) * $d['intercure']);
                $cureId = DB::table('onco_traitement_cures')->insertGetId([
                    'traitement_id' => $id, 'numero' => $n, 'date_debut' => $debutCure->toDateString(),
                    'date_fin' => $debutCure->addDays(max($jours) - 1)->toDateString(),
                    'poids' => $d['poids'], 'taille' => $d['taille'], 'surface_corporelle' => $sc, 'clairance' => $d['clairance'] ?? null,
                ]);
                $cycle = collect($parCycle[(($n - 1) % $nbCycles) + 1] ?? $parCycle->first() ?? []);
                foreach ($jours as $j) {
                    $jourId = DB::table('onco_traitement_jours')->insertGetId([
                        'cure_id' => $cureId, 'jour' => $j, 'date_prevue' => $debutCure->addDays($j - 1)->toDateString(),
                    ]);
                    // Jour décrit par le protocole, sinon reprise du J1 ; le médecin retire ensuite ce qui ne s'applique pas.
                    $duJour = $cycle->filter(fn ($l) => self::decoderJour((string) ($l['jour'] ?? ''))[1] === $j)->values();
                    if ($duJour->isEmpty()) {
                        $duJour = $cycle->filter(fn ($l) => self::decoderJour((string) ($l['jour'] ?? ''))[1] === 1)->values();
                    }
                    foreach ($duJour as $i => $l) {
                        $clairance = isset($d['clairance']) ? (float) $d['clairance'] : null;
                        DB::table('onco_traitement_produits')->insert(['jour_id' => $jourId] + (isset($l['type_dose'])
                            ? self::produitSaisi($l, $sc, (float) $d['poids'], $i, $clairance)
                            : self::produitDepuisLigne($l, $sc, (float) $d['poids'], $i, $clairance)));
                    }
                }
            }

            return $id;
        });
    }

    /** Nouveau poids ou taille : recalcule la cure. Une dose arrondie à la main reste, son écart s'affiche. */
    public function recalculer(object $cure, float $poids, float $taille, ?float $clairance = null): void
    {
        $sc = ChimioRepository::surfaceCorporelle($poids, $taille);
        DB::transaction(function () use ($cure, $poids, $taille, $sc, $clairance) {
            DB::table('onco_traitement_cures')->where('id', $cure->id)->update(['poids' => $poids, 'taille' => $taille, 'surface_corporelle' => $sc, 'clairance' => $clairance]);
            $jours = DB::table('onco_traitement_jours')->where('cure_id', $cure->id)->where('ok_chimio', false)->pluck('id');
            foreach (DB::table('onco_traitement_produits')->whereIn('jour_id', $jours)->get() as $p) {
                $calcul = self::doseCalculee($p->type_dose, $p->dose_ref, $sc, $poids, $clairance);
                $suitCalcul = $p->dose_calculee !== null && self::nombre($p->dose) === round((float) $p->dose_calculee, 2);
                DB::table('onco_traitement_produits')->where('id', $p->id)->update([
                    'dose_calculee' => $calcul,
                    // Une dose vide (AUC sans clairance jusqu'ici) prend aussi le calcul.
                    'dose' => ($suitCalcul || trim((string) $p->dose) === '') && $calcul !== null ? self::texte($calcul) : $p->dose,
                ]);
            }
        });
    }
}
