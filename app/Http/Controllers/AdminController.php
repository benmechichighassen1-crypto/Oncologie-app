<?php

namespace App\Http\Controllers;

use App\Services\Droits;
use App\Services\Journal;
use App\Support\Clinisys as C;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Paramétrage des accès par utilisateur et consultation du journal. */
class AdminController extends Controller
{
    public function acces(Request $request): View
    {
        $q = trim((string) $request->input('q', ''));
        $sql = 'SELECT a.UserName, a.Description, a.Code_Medecin_Infirmier, m.NomMed
                FROM '.C::t('erp', config('oncologie.auth.table')).' a
                LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m ON m.CodMed = a.Code_Medecin_Infirmier
                WHERE a.Actif = 1';
        $bindings = [];
        if ($q !== '') {
            $sql .= ' AND (a.UserName LIKE ? OR a.Description LIKE ? OR m.NomMed LIKE ?)';
            $bindings = ["%$q%", "%$q%", "%$q%"];
        } else {
            $sql .= " AND ISNULL(a.Code_Medecin_Infirmier, '') <> ''";
        }
        $comptes = C::db()->select($sql.' ORDER BY a.UserName', $bindings);

        $acces = DB::table('onco_acces')->get()->keyBy('user_name');
        $listes = DB::table('onco_acces_medecins')->get()->groupBy('user_name');

        return view('admin.acces', compact('comptes', 'acces', 'listes', 'q') + ['portees' => Droits::PORTEES,
            'synchro' => app(\App\Services\Referentiels::class)->derniereSynchro()]);
    }

    /** Recopie protocoles et médicaments de Clinisys dans oncologie_app. */
    public function referentiels(Request $request): RedirectResponse
    {
        $n = app(\App\Services\Referentiels::class)->synchroniser($request->user()->userName);
        Journal::ecrire('synchro_referentiels', ['details' => $n]);

        return redirect()->route('admin.acces')->with('statut', 'Mis à jour : '.collect($n)->map(fn ($v, $k) => "$v $k")->implode(', ').'.');
    }

    public function edit(string $userName): View
    {
        $compte = C::db()->selectOne(
            'SELECT a.UserName, a.Description, a.Code_Medecin_Infirmier, m.NomMed
             FROM '.C::t('erp', config('oncologie.auth.table')).' a
             LEFT JOIN '.C::t('erp', 'dbo.Medecin').' m ON m.CodMed = a.Code_Medecin_Infirmier
             WHERE a.UserName = ?',
            [$userName]
        );
        abort_unless($compte, 404);

        return view('admin.edit', [
            'compte' => $compte,
            'acces' => DB::table('onco_acces')->where('user_name', $userName)->first(),
            'choisis' => DB::table('onco_acces_medecins')->where('user_name', $userName)->pluck('cod_med')->all(),
            'medecins' => C::db()->select('SELECT CodMed, NomMed FROM '.C::t('erp', 'dbo.Medecin').' WHERE Actif = 1 ORDER BY NomMed'),
            'portees' => Droits::PORTEES,
            'statsChoisis' => DB::table('onco_acces_stats_medecins')->where('user_name', $userName)->pluck('cod_med')->all(),
            'porteesStats' => Droits::PORTEES_STATS,
        ]);
    }

    public function update(Request $request, string $userName): RedirectResponse
    {
        $data = $request->validate([
            'portee' => ['required', Rule::in(array_keys(Droits::PORTEES))],
            'admin' => ['nullable', 'boolean'],
            'medecins' => ['nullable', 'array'],
            'medecins.*' => ['string', 'max:10'],
            'stats_portee' => ['required', Rule::in(array_keys(Droits::PORTEES_STATS))],
            'stats_medecins' => ['nullable', 'array'],
            'stats_medecins.*' => ['string', 'max:10'],
        ]);
        $avant = [
            'acces' => DB::table('onco_acces')->where('user_name', $userName)->first(),
            'medecins' => DB::table('onco_acces_medecins')->where('user_name', $userName)->pluck('cod_med')->all(),
            'stats_medecins' => DB::table('onco_acces_stats_medecins')->where('user_name', $userName)->pluck('cod_med')->all(),
        ];
        $medecins = $data['portee'] === 'liste' ? array_values(array_unique($data['medecins'] ?? [])) : [];
        $statsMedecins = $data['stats_portee'] === 'liste' ? array_values(array_unique($data['stats_medecins'] ?? [])) : [];

        DB::transaction(function () use ($userName, $data, $medecins, $statsMedecins, $request) {
            DB::table('onco_acces')->updateOrInsert(['user_name' => $userName], [
                'portee' => $data['portee'],
                'stats_portee' => $data['stats_portee'] === 'identique' ? null : $data['stats_portee'],
                'admin' => (bool) ($data['admin'] ?? false),
                'modifie_par' => $request->user()->userName,
                'modifie_le' => now(),
            ]);
            DB::table('onco_acces_medecins')->where('user_name', $userName)->delete();
            foreach ($medecins as $codMed) {
                DB::table('onco_acces_medecins')->insert(['user_name' => $userName, 'cod_med' => $codMed]);
            }
            DB::table('onco_acces_stats_medecins')->where('user_name', $userName)->delete();
            foreach ($statsMedecins as $codMed) {
                DB::table('onco_acces_stats_medecins')->insert(['user_name' => $userName, 'cod_med' => $codMed]);
            }
        });

        Journal::ecrire('modification_droits', ['details' => [
            'compte' => $userName,
            'avant' => $avant,
            'apres' => ['portee' => $data['portee'], 'admin' => (bool) ($data['admin'] ?? false), 'medecins' => $medecins,
                'stats_portee' => $data['stats_portee'], 'stats_medecins' => $statsMedecins],
        ]]);

        return redirect()->route('admin.acces')->with('statut', "Droits de $userName enregistrés.");
    }

    public function journal(Request $request): View
    {
        $filtres = $request->validate([
            'user' => ['nullable', 'string', 'max:50'],
            'identifiant' => ['nullable', 'string', 'max:20'],
            'action' => ['nullable', 'string', 'max:40'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
        ]);

        $lignes = DB::table('onco_journal')
            ->when($filtres['user'] ?? null, fn ($q, $v) => $q->where('user_name', $v))
            ->when($filtres['identifiant'] ?? null, fn ($q, $v) => $q->where('identifiant_patient', $v))
            ->when($filtres['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filtres['du'] ?? null, fn ($q, $v) => $q->where('cree_le', '>=', $v))
            ->when($filtres['au'] ?? null, fn ($q, $v) => $q->where('cree_le', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->orderByDesc('cree_le')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        // Détails lisibles ; pour les anciennes lignes de retrait (id seul), on relit le contenu retiré.
        $ids = ['note' => [], 'antecedent' => []];
        foreach ($lignes as $l) {
            $l->infos = json_decode((string) $l->details, true) ?: [];
            if (in_array($l->action, ['masquage_note', 'masquage_antecedent'], true) && isset($l->infos['id']) && ! isset($l->infos['texte']) && ! isset($l->infos['antecedent'])) {
                $ids[substr($l->action, 10)][] = (int) $l->infos['id'];
            }
        }
        $notes = DB::table('onco_notes')->whereIn('id', $ids['note'] ?: [0])->get()->keyBy('id');
        $antecedents = DB::table('onco_antecedents')->whereIn('id', $ids['antecedent'] ?: [0])->get()->keyBy('id');
        foreach ($lignes as $l) {
            $id = (int) ($l->infos['id'] ?? 0);
            if ($l->action === 'masquage_note' && isset($notes[$id]) && ! isset($l->infos['texte'])) {
                $l->infos['texte'] = $notes[$id]->texte;
            } elseif ($l->action === 'masquage_antecedent' && isset($antecedents[$id]) && ! isset($l->infos['antecedent'])) {
                $l->infos['antecedent'] = $antecedents[$id]->libelle;
            }
        }

        return view('admin.journal', compact('lignes', 'filtres'));
    }
}
