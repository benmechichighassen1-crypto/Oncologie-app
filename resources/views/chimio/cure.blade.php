@extends('layouts.app')
@section('titre', 'Cure '.$cure->numero.' · '.$traitement->nom_protocole)
@php
    use App\Repositories\TraitementsChimio as T;
    $d = fn ($v, $f = 'd/m/Y') => $v ? \Carbon\Carbon::parse($v)->format($f) : '';
    $modifiable = $peutSaisir && ! $jour->ok_chimio;
    $nb = fn ($v) => T::texte((float) $v);
@endphp
@push('tete')
<style>
    .entete-cure { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:6px 18px; font-size:14px; }
    .entete-cure span { display:block; font-size:12px; color:var(--doux); }
    table.tab-chimio { font-size:13.5px; } table.tab-chimio th { background:#f1f5f9; font-size:11.5px; } table.tab-chimio td { vertical-align:middle; padding:4px 6px; }
    table.tab-chimio input, table.tab-chimio select { padding:4px 6px; font-size:13.5px; width:100%; }
    tr.choisi td { background:#eff6ff; } tr.j-ok td { background:#dcfce7; }
    .a-administrer { background:#fefce8; font-weight:700; }
    .ecart { display:block; font-size:12px; font-weight:600; color:#b45309; } .ecart.fort { color:#b91c1c; }
    .calcul { display:block; font-size:12px; color:var(--doux); font-weight:400; }
    .puce-chimio { display:inline-block; width:9px; height:9px; border-radius:50%; background:#dc2626; margin-right:4px; }
    .mesures input { width:90px; }
</style>
@endpush
@section('contenu')
<div class="carte">
    <div class="filtres" style="justify-content:space-between;margin-bottom:10px">
        <h1 style="margin:0">{{ $traitement->nom_protocole }} · cure {{ $cure->numero }}/{{ $traitement->nb_cures }} <span class="doux" style="font-size:14px;font-weight:400">({{ $statut }})</span></h1>
        <div class="filtres">
            @foreach($autresCures as $a)
                <a class="btn {{ $a->id === $cure->id ? '' : 'sec' }}" style="padding:4px 10px" href="{{ route('chimio.cure', [$id, $a->id]) }}">{{ $a->numero }}</a>
            @endforeach
            <a class="btn discret" href="{{ route('chimio.index', [$id, 't' => $traitement->id]) }}">Tous les traitements</a>
            <a class="btn {{ $manquantes ? 'sec' : 'vert' }}" href="{{ route('chimio.fiche', [$id, $cure->id]) }}" target="_blank" rel="noopener" @if($manquantes) aria-disabled="true" title="Dose manquante : {{ implode(', ', $manquantes) }}" @endif>@include('partials.icone', ['n' => 'imprimer']) Imprimer la fiche</a>
        </div>
    </div>
    <div class="entete-cure">
        <div><span>Patient</span><b>{{ $patient->NomCli }} {{ $patient->Prenom }}</b></div>
        <div><span>Âge · mutuelle</span>{{ $patient->DatNai ? \Carbon\Carbon::parse($patient->DatNai)->age.' ans' : '' }} · {{ $derniere->mutuelle ?? ($derniere->organisme ?? '') }}</div>
        <div><span>Prescrit par</span>{{ $traitement->prescrit_nom }}</div>
        <div><span>Début · fin prévue du traitement</span>{{ $d($traitement->date_debut) }} · {{ $d($traitement->date_fin_prevue) }}</div>
        <div><span>Localisation · stade</span>{{ $traitement->localisation }} {{ $traitement->stade ? '· '.$traitement->stade : '' }}</div>
        <div><span>Allergies</span><b style="color:{{ $traitement->allergies ? '#b91c1c' : 'inherit' }}">{{ $traitement->allergies ?: 'Non renseignées' }}</b></div>
    </div>
    @if($peutSaisir)
    <form method="post" action="{{ route('chimio.mesures', [$id, $cure->id]) }}" class="filtres mesures" style="margin-top:10px" onsubmit="return confirm('Recalculer les doses de cette cure ? Les jours validés ne changent pas.')">
        @csrf
        <label>Poids (kg) <input name="poids" type="number" step="0.1" min="2" max="300" value="{{ $nb($cure->poids) }}" required></label>
        <label>Taille (cm) <input name="taille" type="number" step="0.1" min="40" max="250" value="{{ $nb($cure->taille) }}" required></label>
        <span>SC <b>{{ number_format((float) $cure->surface_corporelle, 3, ',', '') }} m²</b> <span class="doux">(formule de Clinisys)</span></span>
        <label title="Pour les produits en AUC (carboplatine) : dose = AUC × (clairance + 25), clairance plafonnée à 125 ml/min">Clairance créatinine (ml/min) <input name="clairance" type="number" step="0.1" min="5" max="250" value="{{ $cure->clairance !== null ? $nb($cure->clairance) : '' }}"></label>
        <button class="btn sec" style="padding:5px 10px">Recalculer les doses</button>
    </form>
    @else
        <p>Poids {{ $nb($cure->poids) }} kg · taille {{ $nb($cure->taille) }} cm · SC <b>{{ number_format((float) $cure->surface_corporelle, 3, ',', '') }} m²</b>@if($cure->clairance !== null) · clairance {{ $nb($cure->clairance) }} ml/min @endif</p>
    @endif
</div>
@if($errors->any())<p class="erreur"><b>{{ $errors->first() }}</b></p>@endif
@if($manquantes)<p class="alerte">Dose à renseigner : {{ implode(', ', $manquantes) }}. La fiche ne s'imprime pas tant qu'une dose manque.</p>@endif

<div class="carte">
    <h2>Jours de la cure</h2>
    <table class="tab-chimio">
        <thead><tr><th>J</th><th>Date</th><th>Observation</th><th>Tension</th><th>T°C</th><th>Douleur</th><th>OK chimio</th><th>Date OK</th><th>Praticien OK</th></tr></thead>
        @foreach($jours as $j)
            <tr class="{{ $j->id === $jour->id ? 'choisi' : ($j->ok_chimio ? 'j-ok' : '') }}">
                <td><a href="{{ route('chimio.cure', [$id, $cure->id, 'jour' => $j->id]) }}"><b>J{{ $j->jour }}</b></a></td>
                <td><a href="{{ route('chimio.cure', [$id, $cure->id, 'jour' => $j->id]) }}">{{ $d($j->date_prevue) }}</a></td>
                <td>{{ $j->observation }}</td><td>{{ $j->tension }}</td><td>{{ $j->temperature }}</td><td>{{ $j->douleur }}</td>
                <td>{{ $j->ok_chimio ? '✔' : '' }}</td><td>{{ $d($j->ok_le, 'd/m/Y H:i') }}</td><td>{{ $j->ok_nom }}</td>
            </tr>
        @endforeach
    </table>
</div>

<div class="carte">
    <div class="filtres" style="justify-content:space-between">
        <h2 style="margin:0">J{{ $jour->jour }} du {{ $d($jour->date_prevue) }} @if($jour->ok_chimio)<span class="badge" style="background:#15803d">OK chimio</span>@endif</h2>
        @php $aSigner = $produits->where('administre', false)->count(); @endphp
        @if($peutAdministrer && $jour->ok_chimio && $aSigner)
            <form method="post" action="{{ route('chimio.jour.administre', [$id, $jour->id]) }}" style="margin-left:auto" onsubmit="return confirm('Signer l\'administration des {{ $aSigner }} produit(s) restant(s) de J{{ $jour->jour }} ?')">
                @csrf
                <button class="btn vert">@include('partials.icone', ['n' => 'enregistrer']) Tout administrer ({{ $aSigner }})</button>
            </form>
        @endif
        @if($peutValider)
            <form method="post" action="{{ route('chimio.ok', [$id, $jour->id]) }}" @if($jour->ok_chimio) onsubmit="return confirm('Retirer le « OK chimio » de ce jour ?')" @else onsubmit="return confirm('Donner le « OK chimio » pour J{{ $jour->jour }} ? Enregistrez d\'abord vos modifications.')" @endif>
                @csrf
                <button class="btn {{ $jour->ok_chimio ? 'sec' : 'vert' }}">{{ $jour->ok_chimio ? 'Retirer le OK chimio' : 'Donner le OK chimio' }}</button>
            </form>
        @endif
    </div>

    <form method="post" action="{{ route('chimio.jour', [$id, $jour->id]) }}" id="form-jour" style="margin-top:10px">
        @csrf @method('PUT')
        <fieldset style="border:0;padding:0;margin:0">
        <div class="filtres" style="font-size:13px">
            <label>Date<br><input type="date" name="date_prevue" value="{{ substr($jour->date_prevue, 0, 10) }}" required></label>
            <label>Tension<br><input name="tension" maxlength="20" style="width:90px" value="{{ $jour->tension }}"></label>
            <label>T°C<br><input name="temperature" maxlength="10" style="width:70px" value="{{ $jour->temperature }}"></label>
            <label>Douleur<br><input name="douleur" maxlength="10" style="width:70px" value="{{ $jour->douleur }}"></label>
            <label style="flex:1;min-width:240px">Observation<br><input name="observation" maxlength="500" style="width:100%" value="{{ $jour->observation }}"></label>
        </div>
        <table class="tab-chimio" style="margin-top:10px">
            <thead><tr><th style="width:50px">Ordre</th><th>Produit</th><th style="width:80px">Dose</th><th style="width:88px">Type dose</th><th style="width:130px">Dose à administrer</th><th style="width:100px">Voie</th><th style="width:100px">Solvant</th><th style="width:90px">Volume</th><th style="width:90px">Durée</th><th style="width:120px">Administré</th><th style="width:34px"></th></tr></thead>
            @forelse($produits as $p)
                @php $e = $p->type_dose === 'DT' ? null : T::ecart($p); $plafond = T::depassePlafond($p); @endphp
                <tr class="ligne-produit">
                    <td><input type="number" name="produits[{{ $p->id }}][ordre]" min="0" max="999" value="{{ $p->ordre }}"></td>
                    <td>@if($p->chimio)<i class="puce-chimio" title="Produit de chimiothérapie"></i>@endif{{ $p->medicament }}</td>
                    <td><input class="p-ref" name="produits[{{ $p->id }}][dose_ref]" maxlength="40" value="{{ $p->dose_ref }}"></td>
                    <td><select class="p-type" name="produits[{{ $p->id }}][type_dose]">@foreach(T::TYPES_DOSE as $t)<option @selected($p->type_dose === $t)>{{ $t }}</option>@endforeach</select></td>
                    <td class="a-administrer">
                        <input class="p-dose" name="produits[{{ $p->id }}][dose]" maxlength="80" value="{{ $p->dose }}" style="font-weight:700">
                        <span class="calcul p-calcul">{{ $p->dose_calculee && $p->type_dose !== 'DT' ? 'Calculé : '.T::texte((float) $p->dose_calculee) : '' }}</span>
                        <span class="ecart p-ecart {{ $e !== null && abs($e) > 10 ? 'fort' : '' }}">{{ $e !== null ? 'Écart '.($e > 0 ? '+' : '').str_replace('.', ',', $e).' %' : '' }}</span>
                        @if($plafond)<span class="ecart fort">Au-dessus du plafond usuel ({{ $plafond }} mg)</span>@endif
                        @if($p->type_dose === 'AUC' && $cure->clairance === null)<span class="ecart fort">AUC : indiquez la clairance en haut pour calculer la dose (Calvert)</span>@endif
                    </td>
                    <td><input list="voies" name="produits[{{ $p->id }}][voie]" maxlength="30" value="{{ $p->voie }}"></td>
                    <td><input list="solvants" name="produits[{{ $p->id }}][solvant]" maxlength="40" value="{{ $p->solvant }}"></td>
                    <td><input list="volumes" name="produits[{{ $p->id }}][volume]" maxlength="20" value="{{ $p->volume }}"></td>
                    <td><input name="produits[{{ $p->id }}][duree]" maxlength="40" value="{{ $p->duree }}"></td>
                    <td style="font-size:12px">
                        @if($p->administre){{ $p->administre_nom }}<br>{{ $d($p->administre_le, 'd/m H:i') }}@endif
                        @if($peutAdministrer && $jour->ok_chimio)
                            <button type="submit" form="adm-{{ $p->id }}" class="btn {{ $p->administre ? 'discret' : 'sec' }}" style="padding:3px 8px">{{ $p->administre ? 'Annuler' : 'Signer' }}</button>
                        @endif
                    </td>
                    <td>@if($modifiable)<button type="submit" form="ret-{{ $p->id }}" class="btn discret" style="padding:2px 6px" title="Retirer de ce jour">@include('partials.icone', ['n' => 'fermer'])</button>@endif</td>
                </tr>
            @empty
                <tr><td colspan="11" class="doux">Aucun produit pour ce jour.</td></tr>
            @endforelse
        </table>
        </fieldset>
        <datalist id="voies">@foreach(T::VOIES as $v)<option value="{{ $v }}"></option>@endforeach</datalist>
        <datalist id="solvants">@foreach(T::SOLVANTS as $v)<option value="{{ $v }}"></option>@endforeach</datalist>
        <datalist id="volumes">@foreach(T::VOLUMES as $v)<option value="{{ $v }}"></option>@endforeach</datalist>
        @if($modifiable)<button class="btn" style="margin-top:10px">@include('partials.icone', ['n' => 'enregistrer']) Enregistrer ce jour</button>@endif
        @if($jour->ok_chimio)<p class="doux" style="font-size:13px">Jour validé par {{ $jour->ok_nom }} : retirez le « OK chimio » pour le modifier.</p>@endif
    </form>
    {{-- Hors du formulaire principal : un formulaire ne peut pas en contenir un autre. --}}
    @foreach($produits as $p)
        <form id="adm-{{ $p->id }}" method="post" action="{{ route('chimio.produit.administre', [$id, $p->id]) }}" @if(! $p->administre) onsubmit="return confirm('Signer l\'administration de {{ addslashes($p->medicament) }} ?')" @endif>@csrf</form>
        <form id="ret-{{ $p->id }}" method="post" action="{{ route('chimio.produit.retrait', [$id, $p->id]) }}" onsubmit="return confirm('Retirer {{ addslashes($p->medicament) }} de ce jour ?')">@csrf @method('DELETE')</form>
    @endforeach
    @if($modifiable)
        <form method="post" action="{{ route('chimio.produit.ajout', [$id, $jour->id]) }}" class="filtres" style="margin-top:10px">
            @csrf
            <input name="medicament" id="ajout-medicament" list="medicaments" maxlength="150" required placeholder="Ajouter un produit : tapez son nom…" autocomplete="off" style="flex:1;min-width:260px">
            <datalist id="medicaments"></datalist>
            <label style="font-size:13px"><input type="hidden" name="chimio" value="0"><input type="checkbox" name="chimio" value="1"> Produit de chimio</label>
            <button class="btn sec">@include('partials.icone', ['n' => 'plus']) Ajouter</button>
        </form>
    @endif
    @if($traitement->observation)<p style="font-size:14px"><b>Observations :</b> {{ $traitement->observation }}</p>@endif
</div>
@endsection
@push('scripts')
<script>
(function () {
    @if(! $modifiable)
    // Lecture seule (le serveur refuse aussi) : les boutons de signature restent actifs, contrairement à un fieldset désactivé.
    document.querySelectorAll('#form-jour input, #form-jour select').forEach(e => { e.disabled = true; });
    @endif
    const sc = {{ (float) $cure->surface_corporelle }}, poids = {{ (float) $cure->poids }}, clairance = {{ $cure->clairance !== null ? (float) $cure->clairance : 'null' }};
    const nombre = t => { const m = String(t).replace(/[\s  ]/g, '').replace(',', '.').match(/^(\d+(?:\.\d+)?)(?:mg)?$/i); return m ? parseFloat(m[1]) : null; };
    // Calcul et écart en direct : l'arrondi reste à la main du médecin.
    document.querySelectorAll('.ligne-produit').forEach(tr => {
        const ref = tr.querySelector('.p-ref'), type = tr.querySelector('.p-type'), dose = tr.querySelector('.p-dose'),
            calcul = tr.querySelector('.p-calcul'), ecart = tr.querySelector('.p-ecart');
        const calculer = () => {
            const base = nombre(ref.value);
            if (base === null || base <= 0) return null;
            return ({ 'mg/m²': Math.round(base * sc * 100) / 100, 'mg/kg': Math.round(base * poids * 100) / 100, 'DT': base,
                'AUC': clairance ? Math.round(base * (Math.min(clairance, {{ \App\Repositories\TraitementsChimio::CLAIRANCE_MAX }}) + 25) * 100) / 100 : null })[type.value] ?? null;
        };
        function afficher() {
            const c = calculer(), d = nombre(dose.value);
            calcul.textContent = c !== null && type.value !== 'DT' ? 'Calculé : ' + c : '';
            const e = c && d !== null && type.value !== 'DT' ? Math.round((d - c) / c * 1000) / 10 : 0;
            ecart.textContent = e ? 'Écart ' + (e > 0 ? '+' : '') + String(e).replace('.', ',') + ' %' : '';
            ecart.classList.toggle('fort', Math.abs(e) > 10);
        }
        // Changer la base ou le type recalcule ; une dose saisie à la main n'est pas touchée ensuite.
        [ref, type].forEach(el => el.addEventListener('change', () => { const c = calculer(); if (c !== null) dose.value = c; afficher(); }));
        dose.addEventListener('input', afficher);
    });
    const champ = document.getElementById('ajout-medicament');
    if (champ) {
        let m = null;
        champ.addEventListener('input', () => {
            clearTimeout(m); const q = champ.value.trim(); if (q.length < 2) return;
            m = setTimeout(() => fetch('{{ route('medicaments.recherche') }}?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : []).then(liste => {
                    const dl = document.getElementById('medicaments'); dl.innerHTML = '';
                    liste.forEach(x => { const o = document.createElement('option'); o.value = x.libelle; o.label = x.detail || ''; dl.append(o); });
                }), 300);
        });
    }
})();
</script>
@endpush
