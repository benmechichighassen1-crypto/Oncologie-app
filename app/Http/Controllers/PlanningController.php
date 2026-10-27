<?php

namespace App\Http\Controllers;

use App\Repositories\PlanningRepository;
use App\Services\Droits;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Accueil : calendrier de la semaine par médecin. */
class PlanningController extends Controller
{
    public function __construct(private PlanningRepository $planning, private Droits $droits)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('planning.index', [
            'medecins' => $this->planning->medecins($this->droits->medecinsAutorises($user)),
            'medecinDefaut' => $user->codMed,
            'centresChimio' => PlanningRepository::CENTRES_CHIMIO,
            'couleurs' => config('oncologie.couleurs'),
            'etats' => config('oncologie.etats'),
        ]);
    }

    /** Recherche d'un patient : ses RDV de 3 mois avant à 6 mois après aujourd'hui, pour s'y rendre. */
    public function recherche(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q']);
        $seances = $this->planning->seances(now()->subMonths(3)->toDateString(), now()->addMonths(6)->toDateString(),
            $this->droits->medecinsAutorises($request->user()), ['CONSULTATION', 'CHIMIO', 'RADIO'], null, $q);
        $libelles = ['CONSULTATION' => 'Consultation', 'CHIMIO' => 'Chimiothérapie', 'RADIO' => 'Radiothérapie'];
        $aujourdhui = now()->toDateString();

        // Les prochains d'abord, puis les plus récents passés.
        $liste = collect($seances)->sortBy(fn ($s) => [substr($s->startDate, 0, 10) < $aujourdhui ? 1 : 0,
            substr($s->startDate, 0, 10) < $aujourdhui ? -strtotime($s->startDate) : strtotime($s->startDate)])
            ->take(12)->map(fn ($s) => [
                'date' => substr($s->startDate, 0, 10), 'heure' => substr($s->startDate, 11, 5),
                'patient' => trim((string) $s->patient), 'numdoss' => $s->numdoss, 'identifiant' => trim((string) $s->identifiant),
                'type' => $libelles[$s->type] ?? $s->type, 'etat' => config('oncologie.etats')[(int) $s->etat]['libelle'] ?? '',
            ])->values();

        return response()->json($liste);
    }

    /** Flux JSON pour FullCalendar. */
    public function evenements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date'],
            'medecin' => ['nullable', 'string', 'max:10'],
            'types' => ['nullable', 'array'],
            'types.*' => ['in:CONSULTATION,CHIMIO,RADIO'],
            'centre' => ['nullable', 'in:20,23'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $du = CarbonImmutable::parse($data['start'])->toDateString();
        $au = CarbonImmutable::parse($data['end'])->subDay()->toDateString();
        abort_if(CarbonImmutable::parse($du)->diffInDays($au) > 62, 422, 'Période trop longue.');

        $autorises = $this->droits->medecinsAutorises($request->user());
        $medecins = $autorises;
        if (! empty($data['medecin'])) {
            abort_unless($autorises === null || in_array($data['medecin'], $autorises, true), 403);
            $medecins = [$data['medecin']];
        }

        $ressources = $this->planning->ressources();
        $couleurs = config('oncologie.couleurs');
        $etats = config('oncologie.etats');

        $seances = $this->planning->seances($du, $au, $medecins, $data['types'] ?? array_keys($couleurs), $data['centre'] ?? null, $data['q'] ?? null);

        return response()->json(array_map(function ($s) use ($ressources, $couleurs, $etats) {
            $etat = $etats[(int) $s->etat] ?? null;
            $ressource = $s->ressource !== null ? ($ressources[(string) $s->ressource] ?? null) : null;

            // Une cure de chimio dure plusieurs heures : on l'affiche sur 15 min pour garder
            // le calendrier lisible ; les vraies heures restent dans l'info-bulle.
            $fin = $s->type === 'CHIMIO' && $s->startDate
                ? CarbonImmutable::parse($s->startDate)->addMinutes(15)->format('Y-m-d\TH:i:s')
                : $s->endDate;

            return [
                'id' => $s->type.'-'.$s->num_rdv,
                'title' => trim((string) $s->patient),
                'start' => $s->startDate,
                'end' => $fin,
                'backgroundColor' => $couleurs[$s->type],
                'borderColor' => $etat['couleur'] ?? $couleurs[$s->type],
                'url' => $s->identifiant ? route('dossier', ['identifiant' => trim($s->identifiant)]) : null,
                'extendedProps' => [
                    'type' => $s->type,
                    'etat' => $etat['libelle'] ?? '',
                    'etatCode' => (int) $s->etat,
                    'seance' => $s->seance,
                    'ressource' => $ressource ?? ($s->type === 'CONSULTATION' ? null : $s->ressource),
                    'centre' => PlanningRepository::CENTRES_CHIMIO[$s->centre ?? ''] ?? null,
                    'organisme' => $s->organisme,
                    'medecin' => $s->medecin,
                    'numdoss' => $s->numdoss,
                    'finReelle' => $s->endDate ? substr($s->endDate, 11, 5) : null,
                    'heurePrevue' => ($s->prevu ?? null) ? substr($s->prevu, 11, 5) : null,
                ],
            ];
        }, $seances));
    }
}
