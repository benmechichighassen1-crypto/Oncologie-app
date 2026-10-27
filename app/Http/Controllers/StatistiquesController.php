<?php

namespace App\Http\Controllers;

use App\Repositories\PlanningRepository;
use App\Repositories\StatistiquesRepository;
use App\Services\Droits;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Statistiques d'activité par centre (consultation, chimio, radio) et par médecin. */
class StatistiquesController extends Controller
{
    public function index(Request $request, StatistiquesRepository $stats, PlanningRepository $planning, Droits $droits): View
    {
        $f = $request->validate([
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date', 'after_or_equal:du'],
            'pas' => ['nullable', 'in:auto,jour,semaine,mois'],
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(array_keys(StatistiquesRepository::TYPES))],
            'medecin' => ['nullable', 'string', 'max:10'],
            'annees' => ['nullable', 'integer', 'min:1', 'max:5'],
            'mode' => ['nullable', 'in:seances,patients'],
            'centre_chimio' => ['nullable', Rule::in(array_keys(PlanningRepository::CENTRES_CHIMIO))],
        ]);
        $au = CarbonImmutable::parse($f['au'] ?? 'today');
        $du = CarbonImmutable::parse($f['du'] ?? $au->subMonths(3)->startOfMonth());
        abort_if($du->diffInDays($au) > 1100, 422, 'Période limitée à 3 ans.');
        $types = $f['types'] ?? array_keys(StatistiquesRepository::TYPES);
        $nbAnnees = (int) ($f['annees'] ?? 3);
        $mode = $f['mode'] ?? 'seances';
        $stats->centreChimio($f['centre_chimio'] ?? null);

        // Droits : accès aux statistiques paramétré à part (écran Accès), par défaut comme ses patients.
        $autorises = $droits->medecinsStatistiques($request->user());
        abort_if($autorises === [], 403, 'Vous n\'avez pas accès aux statistiques. Demandez un accès à l\'administrateur.');
        $medecin = $f['medecin'] ?? null;
        abort_if($medecin && $autorises !== null && ! in_array($medecin, $autorises, true), 403);
        $filtre = $medecin ? [$medecin] : $autorises;

        $jours = $du->diffInDays($au) + 1;
        $pas = ($f['pas'] ?? 'auto') !== 'auto' ? $f['pas'] : ($jours <= 45 ? 'jour' : ($jours <= 200 ? 'semaine' : 'mois'));

        // Période précédente de même durée, pour la variation.
        $precAu = $du->subDay();
        $precDu = $precAu->subDays($jours - 1);

        $courant = $stats->parPeriode($du->toDateString(), $au->toDateString(), $pas, $types, $filtre, $mode);
        $totaux = $stats->totaux($du->toDateString(), $au->toDateString(), $types, $filtre, $mode);
        $totauxPrec = $stats->totaux($precDu->toDateString(), $precAu->toDateString(), $types, $filtre, $mode);
        $mensuel = $stats->parMois($au->year - $nbAnnees + 1, $au->year, $types, $filtre, $mode);
        $medecins = $stats->parMedecin($du->toDateString(), $au->toDateString(), $types, $filtre, $mode);
        $medecinsPrec = $stats->parMedecin($precDu->toDateString(), $precAu->toDateString(), $types, $filtre, $mode);

        return view('statistiques.index', [
            'f' => ['du' => $du->toDateString(), 'au' => $au->toDateString(), 'pas' => $f['pas'] ?? 'auto', 'types' => $types,
                'medecin' => $medecin, 'annees' => $nbAnnees, 'mode' => $mode, 'centre_chimio' => (string) ($f['centre_chimio'] ?? '')],
            'centresChimio' => PlanningRepository::CENTRES_CHIMIO,
            'unite' => $mode === 'patients' ? 'Patients' : 'Séances',
            'pas' => $pas,
            'periodePrec' => [$precDu, $precAu],
            'kpi' => $this->kpi($totaux, $totauxPrec),
            'courbe' => $this->courbe($courant, $du, $au, $pas, $types),
            'annuel' => $this->annuel($mensuel, $au->year - $nbAnnees + 1, $au->year),
            'tableau' => $this->tableau($medecins, $medecinsPrec),
            'listeMedecins' => $planning->medecins($autorises),
            'libelles' => StatistiquesRepository::TYPES,
            'couleurs' => config('oncologie.couleurs'),
        ]);
    }

    private function kpi(array $courant, array $precedent): array
    {
        $kpi = [];
        foreach (StatistiquesRepository::TYPES as $type => $libelle) {
            $n = (int) collect($courant)->where('type', $type)->sum('n');
            $p = (int) collect($precedent)->where('type', $type)->sum('n');
            $kpi[$type] = ['n' => $n, 'prec' => $p, 'variation' => $p > 0 ? round(($n - $p) * 100 / $p, 1) : null];
        }
        // Total = somme des centres : chaque centre est compté à part.
        $n = array_sum(array_column($kpi, 'n'));
        $p = array_sum(array_column($kpi, 'prec'));
        $kpi['TOTAL'] = ['n' => $n, 'prec' => $p, 'variation' => $p > 0 ? round(($n - $p) * 100 / $p, 1) : null];

        return $kpi;
    }

    /** Toutes les périodes de l'intervalle (à zéro si aucune activité), une série par type. */
    private function courbe(array $lignes, CarbonImmutable $du, CarbonImmutable $au, string $pas, array $types): array
    {
        $cles = [];
        $libelles = [];
        if ($pas === 'mois') {
            foreach (CarbonPeriod::create($du->startOfMonth(), '1 month', $au) as $d) {
                $cles[] = $d->format('Ym');
                $libelles[] = ucfirst($d->locale('fr')->isoFormat('MMM YYYY'));
            }
        } else {
            $debut = $pas === 'semaine' ? $du->startOfWeek() : $du;
            foreach (CarbonPeriod::create($debut, $pas === 'semaine' ? '1 week' : '1 day', $au) as $d) {
                $cles[] = $d->format('Ymd');
                $libelles[] = $pas === 'semaine' ? 'Sem. '.$d->format('d/m') : $d->locale('fr')->isoFormat('dd D/MM');
            }
        }
        $index = collect($lignes)->groupBy('type')->map(fn ($l) => $l->mapWithKeys(fn ($x) => [trim($x->periode) => (int) $x->n]));
        $series = [];
        foreach ($types as $type) {
            $series[$type] = array_map(fn ($c) => $index[$type][$c] ?? 0, $cles);
        }

        return ['libelles' => $libelles, 'series' => $series];
    }

    /** Une série de 12 mois par centre et par année : [type][annee] => 12 valeurs. */
    private function annuel(array $lignes, int $de, int $a): array
    {
        $series = [];
        // Par centre puis par année, pour pouvoir filtrer les centres sur la courbe.
        foreach (collect($lignes)->pluck('type')->unique() as $type) {
        for ($annee = $de; $annee <= $a; $annee++) {
            $mois = array_fill(1, 12, 0);
            foreach ($lignes as $l) {
                if ((int) $l->annee === $annee && $l->type === $type) {
                    $mois[(int) $l->mois] += (int) $l->n;
                }
            }
            // Mois futurs de l'année en cours : pas de point plutôt qu'un zéro trompeur.
            if ($annee === (int) date('Y')) {
                foreach ($mois as $m => $v) {
                    if ($m > (int) date('n')) {
                        $mois[$m] = null;
                    }
                }
            }
            $series[$type][$annee] = array_values($mois);
        }
        }

        return $series;
    }

    private function tableau(array $lignes, array $precedent): array
    {
        $prec = collect($precedent)->groupBy('cod_med')->map(fn ($l) => (int) $l->sum('n'));
        $tableau = collect($lignes)->groupBy('cod_med')->map(function ($l, $code) use ($prec) {
            $ligne = ['code' => $code, 'nom' => trim((string) ($l->first()->nom_med ?: $code)), 'total' => (int) $l->sum('n')];
            foreach (array_keys(StatistiquesRepository::TYPES) as $t) {
                $ligne[$t] = (int) $l->where('type', $t)->sum('n');
            }
            $p = $prec[$code] ?? 0;
            $ligne['variation'] = $p > 0 ? round(($ligne['total'] - $p) * 100 / $p, 1) : null;

            return $ligne;
        })->filter(fn ($l) => $l['code'] !== '')->sortByDesc('total')->values();

        return $tableau->all();
    }
}
