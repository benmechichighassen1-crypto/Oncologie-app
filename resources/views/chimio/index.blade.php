@extends('layouts.app')
@section('titre', 'Traitement chimio')
@php
    use App\Repositories\ChimioRepository;
    $d = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '';
    $sc = old('poids', $dernieresMesures->poids ?? null);
@endphp
@push('tete')
<style>
    .tete-patient { display:flex; flex-wrap:wrap; gap:6px 22px; align-items:baseline; }
    .tete-patient b.nom { font-size:18px; }
    table.tab-chimio { font-size:14px; } table.tab-chimio th { background:#f1f5f9; } table.tab-chimio td { vertical-align:middle; }
    tr.choisi td { background:#eff6ff; }
    .statut { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; color:#fff; background:#64748b; }
    .statut.FINALISÉE { background:#15803d; } .statut.EN.COURS { background:#d97706; }
    #form-traitement label { display:block; font-size:13px; color:var(--doux); }
    #form-traitement input, #form-traitement select { width:100%; color:var(--texte); }
    .champs { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px 14px; margin-top:10px; }
    .champs .large { grid-column:span 2; }
    #liste-protocoles { max-height:240px; overflow:auto; border:1px solid var(--bord); border-radius:8px; margin-top:6px; }
    #liste-protocoles div { padding:6px 10px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:14px; }
    #liste-protocoles div:hover, #liste-protocoles div.actif { background:#eff6ff; font-weight:600; }
</style>
@endpush
@section('contenu')
<div class="carte tete-patient">
    <b class="nom">{{ $patient->NomCli }} {{ $patient->Prenom }}</b>
    <span class="doux">Identifiant {{ $id }}</span>
    @if($patient->DatNai)<span>{{ \Carbon\Carbon::parse($patient->DatNai)->age }} ans</span>@endif
    @if($derniere)<span>{{ $derniere->mutuelle ?: $derniere->organisme }}</span>@endif
    <a href="{{ route('dossier', $id) }}" style="margin-left:auto">Retour au dossier</a>
</div>
@if($errors->any())<p class="erreur">{{ $errors->first() }}</p>@endif

<div class="carte">
    <div class="filtres" style="justify-content:space-between">
        <h2 style="margin:0">Traitements de chimiothérapie</h2>
        @if($peutSaisir)<button type="button" class="btn" onclick="document.getElementById('nouveau').hidden = !document.getElementById('nouveau').hidden">@include('partials.icone', ['n' => 'plus']) Nouveau traitement</button>@endif
    </div>

    @if($peutSaisir)
    <div id="nouveau" @if(! $errors->any() && count($traitements)) hidden @endif style="border:1px solid var(--bord);border-radius:10px;padding:14px;margin:12px 0">
        <form method="post" action="{{ route('chimio.creer', $id) }}" id="form-traitement">
            @csrf
            <input type="hidden" name="cod_prot" id="cod-prot" value="{{ old('cod_prot') }}">
            <label>Protocole<input id="filtre-protocole" placeholder="Tapez une partie du nom : folfiri, ac60, carbo…" autocomplete="off"></label>
            <div id="liste-protocoles" hidden>
                @foreach($protocoles as $code => $nom)<div data-code="{{ $code }}" data-nom="{{ $nom }}">{{ $nom }}</div>@endforeach
            </div>
            <p style="margin:8px 0 0">Protocole choisi : <b id="protocole-nom">aucun</b></p>
            <div class="champs">
                <label>Poids (kg)<input name="poids" id="f-poids" type="number" step="0.1" min="2" max="300" required value="{{ old('poids', $dernieresMesures->poids ?? '') }}"></label>
                <label>Taille (cm)<input name="taille" id="f-taille" type="number" step="0.1" min="40" max="250" required value="{{ old('taille', $dernieresMesures->taille ?? '') }}"></label>
                <label>Surface corporelle<b id="f-sc" style="display:block;padding:7px 0;color:var(--texte)">—</b></label>
                <label>Début du traitement<input name="date_debut" id="f-debut" type="date" required value="{{ old('date_debut', date('Y-m-d')) }}"></label>
                <label>Nombre de cures<input name="nb_cures" id="f-cures" type="number" min="1" max="60" required value="{{ old('nb_cures', 6) }}"></label>
                <label>Intercure (jours)<input name="intercure" id="f-intercure" type="number" min="1" max="365" required value="{{ old('intercure', 21) }}"></label>
                <label>Jours du cycle<input name="jours" id="f-jours" required placeholder="1, 8, 15" value="{{ old('jours', '1') }}"></label>
                <label>Fin prévue<b id="f-fin" style="display:block;padding:7px 0;color:var(--texte)">—</b></label>
                <label title="Pour les produits en AUC (carboplatine) : dose = AUC × (clairance + 25), clairance plafonnée à 125 ml/min">Clairance créat. (ml/min)<input name="clairance" id="f-clairance" type="number" step="0.1" min="5" max="250" value="{{ old('clairance') }}"></label>
                <label class="large">Localisation<input name="localisation" maxlength="150" value="{{ old('localisation', $fiche->pathologie ?? '') }}"></label>
                <label>Histologie<input name="histologie" maxlength="150" value="{{ old('histologie') }}"></label>
                <label>TNM<input name="tnm" maxlength="40" placeholder="T2 N1 M0" value="{{ old('tnm') }}"></label>
                <label>Stade<input name="stade" maxlength="40" value="{{ old('stade', $fiche->stade ?? '') }}"></label>
                <label class="large">Allergies<input name="allergies" maxlength="255" value="{{ old('allergies') }}"></label>
                <label style="align-self:end"><input type="hidden" name="rechute" value="0"><input type="checkbox" name="rechute" value="1" style="width:auto" @checked(old('rechute'))> Rechute</label>
            </div>
            {{-- Saisie des doses dès le choix du protocole : ces lignes servent à créer toutes les cures. --}}
            <div id="apercu" hidden style="margin-top:12px;border:1px solid var(--bord);border-radius:8px;padding:10px">
                <b>Doses du protocole</b> <span class="doux" style="font-size:13px">(calculées avec le poids, la taille et la clairance ci-dessus ; arrondissez, retirez ou ajoutez avant de créer)</span>
                <table class="tab-chimio" style="margin-top:6px">
                    <thead><tr><th>Produit</th><th style="width:80px">Base</th><th style="width:90px">Type dose</th><th style="width:150px">Dose à administrer</th><th style="width:105px">Voie</th><th style="width:105px">Solvant</th><th style="width:90px">Volume</th><th style="width:90px">Durée</th><th style="width:34px"></th></tr></thead>
                    <tbody id="apercu-lignes"></tbody>
                </table>
                <div class="filtres" style="margin-top:6px">
                    <input id="ajout-ligne" list="medicaments-ajout" maxlength="150" placeholder="Ajouter un produit : tapez son nom…" autocomplete="off" style="flex:1;min-width:240px">
                    <datalist id="medicaments-ajout"></datalist>
                    <button type="button" class="btn sec" id="ajout-ligne-btn">@include('partials.icone', ['n' => 'plus']) Ajouter</button>
                </div>
                <p id="apercu-calendrier" style="font-size:13px;margin:8px 0 0"></p>
            </div>
            <datalist id="voies">@foreach(\App\Repositories\TraitementsChimio::VOIES as $v)<option value="{{ $v }}"></option>@endforeach</datalist>
            <datalist id="solvants">@foreach(\App\Repositories\TraitementsChimio::SOLVANTS as $v)<option value="{{ $v }}"></option>@endforeach</datalist>
            <datalist id="volumes">@foreach(\App\Repositories\TraitementsChimio::VOLUMES as $v)<option value="{{ $v }}"></option>@endforeach</datalist>
            <label style="margin-top:10px">Observations et recommandations<textarea name="observation" rows="2" maxlength="2000" style="width:100%">{{ old('observation') }}</textarea></label>
            <p class="doux" style="font-size:13px">Les doses en mg/m² utilisent la surface corporelle de Clinisys (formule de Boyd). Un jour sans ligne propre dans le protocole reprend les produits du J1 : retirez ensuite ce qui ne s'applique pas.</p>
            <p id="f-nom-jours" class="alerte" hidden>Le nom de ce protocole contient des jours : vérifiez l'intercure et les jours du cycle. « J1J21 » veut dire une cure tous les 21 jours : jours « 1 », intercure 21.</p>
            <p id="f-alerte" class="erreur" hidden><b>Le dernier jour du cycle doit tomber avant la cure suivante : diminuez les jours ou augmentez l'intercure.</b></p>
            <button class="btn" type="submit" id="f-valider" disabled>@include('partials.icone', ['n' => 'enregistrer']) Créer le traitement et ses cures</button>
        </form>
    </div>
    @endif

    <table class="tab-chimio" style="margin-top:12px">
        <thead><tr><th>Protocole</th><th>Cures</th><th>Début</th><th>Fin prévue</th><th>Intercure</th><th>Rechute</th><th>Prescrit par</th><th>Statut</th><th></th></tr></thead>
        @forelse($traitements as $t)
            <tr class="{{ $choisi && $choisi->id === $t->id ? 'choisi' : '' }}">
                <td><a href="{{ route('chimio.index', [$id, 't' => $t->id]) }}"><b>{{ $t->nom_protocole }}</b></a></td>
                <td>{{ $t->nb_cures }}</td><td>{{ $d($t->date_debut) }}</td><td>{{ $d($t->date_fin_prevue) }}</td><td>{{ $t->intercure }} j</td>
                <td>{{ $t->rechute ? 'Oui' : '' }}</td><td>{{ $t->prescrit_nom }}</td>
                <td>{{ $t->statut === 'arrete' ? 'Arrêté le '.$d($t->arrete_le) : 'En cours' }}</td>
                <td style="text-align:right">
                    @if($peutSaisir && $t->statut === 'actif')
                        <form method="post" action="{{ route('chimio.arret', [$id, $t->id]) }}" onsubmit="return confirm('Arrêter ce traitement ? Il restera visible dans l\'historique.')">@csrf @method('PATCH')<button class="btn discret">Arrêter</button></form>
                    @endif
                    @if(! $t->administre && ($t->prescrit_par === auth()->user()->userName || auth()->user()->admin))
                        <form method="post" action="{{ route('chimio.suppression', [$id, $t->id]) }}" onsubmit="return confirm('Supprimer ce traitement et toutes ses cures ? Aucun produit n\'a été administré. Il disparaîtra des écrans ; la trace reste au journal.')">@csrf @method('DELETE')<button class="btn discret" style="color:#b91c1c">Supprimer</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="doux">Aucun traitement saisi dans l'appli pour ce patient.</td></tr>
        @endforelse
    </table>
</div>

@if($choisi)
<div class="carte">
    <h2>Cures de {{ $choisi->nom_protocole }}</h2>
    <table class="tab-chimio">
        <thead><tr><th>N° cure</th><th>Statut</th><th>Début</th><th>Fin</th><th>Poids</th><th>SC</th><th></th></tr></thead>
        @foreach($choisi->cures as $c)
            <tr>
                <td><a href="{{ route('chimio.cure', [$id, $c->id]) }}"><b>{{ $c->numero }} / {{ $choisi->nb_cures }}</b></a></td>
                <td><span class="statut {{ $c->statut }}">{{ $c->statut }}</span></td>
                <td>{{ $d($c->date_debut) }}</td><td>{{ $d($c->date_fin) }}</td>
                <td>{{ \App\Repositories\TraitementsChimio::texte((float) $c->poids) }} kg</td><td>{{ number_format((float) $c->surface_corporelle, 3, ',', '') }} m²</td>
                <td style="text-align:right"><a class="btn sec" href="{{ route('chimio.cure', [$id, $c->id]) }}">Ouvrir</a></td>
            </tr>
        @endforeach
    </table>
</div>
@endif
@endsection
@push('scripts')
<script>
(function () {
    const liste = document.getElementById('liste-protocoles');
    if (!liste) return;
    const sansAccents = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const q = id => document.getElementById(id);
    const lignes = [...liste.children];
    // Liste fermée par défaut : elle s'ouvre au clic ou à la frappe dans le champ, et se referme après le choix.
    function filtrer() {
        const mots = sansAccents(q('filtre-protocole').value).split(/\s+/).filter(Boolean);
        lignes.forEach(l => { const t = sansAccents(l.dataset.nom); l.hidden = !mots.every(m => t.includes(m)); });
        liste.hidden = false;
    }
    q('filtre-protocole').addEventListener('input', filtrer);
    q('filtre-protocole').addEventListener('click', filtrer);
    q('filtre-protocole').addEventListener('keydown', e => { if (e.key === 'Escape') liste.hidden = true; });
    document.addEventListener('click', e => { if (!e.target.closest('#liste-protocoles, #filtre-protocole')) liste.hidden = true; });
    function choisir(l) {
        lignes.forEach(x => x.classList.remove('actif')); l.classList.add('actif');
        liste.hidden = true; q('filtre-protocole').value = '';
        q('cod-prot').value = l.dataset.code; q('protocole-nom').textContent = l.dataset.nom; q('f-valider').disabled = false;
        // Jamais de jours déduits du nom : « J1J21 » veut dire une cure tous les 21 jours, pas un second jour de cure.
        q('f-jours').value = '1';
        q('f-nom-jours').hidden = !/J\s*\d/i.test(l.dataset.nom);
        chargerApercu(l.dataset.code);
        calculer();
    }
    lignes.forEach(l => l.addEventListener('click', () => choisir(l)));
    const echap = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let n = 0, mesures = { sc: null, poids: null, clairance: null };
    const nombre = t => { const m = String(t ?? '').replace(/[\s  ]/g, '').replace(',', '.').match(/^(\d+(?:\.\d+)?)(?:mg)?$/i); return m ? parseFloat(m[1]) : null; };
    const calcul = (type, ref) => {
        const b = nombre(ref); if (b === null || b <= 0) return null;
        const { sc, poids, clairance } = mesures;
        return ({ 'mg/m²': sc ? Math.round(b * sc * 100) / 100 : null, 'mg/kg': poids ? Math.round(b * poids * 100) / 100 : null, 'DT': b,
            'AUC': clairance ? Math.round(b * (Math.min(clairance, 125) + 25) * 100) / 100 : null })[type] ?? null;
    };
    // Dilution du protocole Clinisys en voie, solvant, volume (comme TraitementsChimio::lireDilution).
    const dilution = d => [/^\s*SC\s*$/i.test(d) ? 'SC' : (/baxter/i.test(d) ? 'Baxter' : ''),
        /baxter/i.test(d) ? 'Baxter' : (/G\s*5/i.test(d) ? 'G5%' : (/\b(ss|nacl)\b/i.test(d) ? 'NaCl 0,9%' : '')), (d.match(/(\d{2,4})\s*(cc|ml)/i) || [])[1] ? d.match(/(\d{2,4})\s*(cc|ml)/i)[1] + ' cc' : ''];
    function ajouterLigne(l) {
        const i = n++, kg = (l.dose_initiale || '').match(/^([\d.,]+)\s*mg\s*\/\s*kg/i);
        const [type, ref] = l.dose_m2 != null ? ['mg/m²', String(l.dose_m2)] : (l.auc != null ? ['AUC', String(l.auc)] : (kg ? ['mg/kg', kg[1].replace(',', '.')] : ['DT', nombre(l.dose) !== null ? String(nombre(l.dose)) : '']));
        const [voie, solvant, volume] = dilution(l.dilution || ''), c = 'lignes[' + i + ']';
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + (l.chimio ? '<i class="puce-chimio" title="Produit de chimiothérapie" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#dc2626;margin-right:4px"></i>' : '') + echap(l.medicament)
            + '<input type="hidden" name="' + c + '[medicament]" value="' + echap(l.medicament) + '"><input type="hidden" name="' + c + '[jour]" value="' + echap(l.jour || '1-J01') + '"><input type="hidden" name="' + c + '[chimio]" value="' + (l.chimio ? 1 : 0) + '"></td>'
            + '<td><input class="p-ref" name="' + c + '[dose_ref]" maxlength="40" value="' + echap(ref) + '"></td>'
            + '<td><select class="p-type" name="' + c + '[type_dose]">' + ['mg/m²', 'mg/kg', 'AUC', 'DT'].map(t => '<option' + (t === type ? ' selected' : '') + '>' + t + '</option>').join('') + '</select></td>'
            + '<td style="background:#fefce8"><input class="p-dose" name="' + c + '[dose]" maxlength="80" style="font-weight:700" value="' + echap(type === 'DT' && nombre(l.dose) === null ? (l.dose || '') : '') + '"><span class="p-info" style="display:block;font-size:12px"></span></td>'
            + '<td><input list="voies" name="' + c + '[voie]" maxlength="30" value="' + echap(voie) + '"></td>'
            + '<td><input list="solvants" name="' + c + '[solvant]" maxlength="40" value="' + echap(solvant) + '"></td>'
            + '<td><input list="volumes" name="' + c + '[volume]" maxlength="20" value="' + echap(volume) + '"></td>'
            + '<td><input name="' + c + '[duree]" maxlength="40" value="' + echap(l.duree || '') + '"></td>'
            + '<td><button type="button" class="btn discret" style="padding:2px 6px" title="Retirer">✕</button></td>';
        tr.querySelectorAll('input:not([type=hidden]), select').forEach(e => { e.style.width = '100%'; e.style.padding = '4px 6px'; });
        const dose = tr.querySelector('.p-dose'), refEl = tr.querySelector('.p-ref'), typeEl = tr.querySelector('.p-type');
        dose.dataset.auto = dose.value === '' ? '1' : '';
        // Une dose tapée à la main n'est plus remplacée par le calcul ; changer la base ou le type la recalcule.
        dose.addEventListener('input', () => { dose.dataset.auto = ''; majLigne(tr); });
        [refEl, typeEl].forEach(e => e.addEventListener('input', () => { dose.dataset.auto = '1'; majLigne(tr); }));
        tr.querySelector('button').addEventListener('click', () => { tr.remove(); q('apercu').hidden = !q('apercu-lignes').children.length; });
        q('apercu-lignes').append(tr); majLigne(tr);
    }
    function majLigne(tr) {
        const dose = tr.querySelector('.p-dose'), type = tr.querySelector('.p-type').value, c = calcul(type, tr.querySelector('.p-ref').value), info = tr.querySelector('.p-info');
        if (dose.dataset.auto && c !== null) dose.value = c;
        const d = nombre(dose.value), e = c && d !== null && type !== 'DT' ? Math.round((d - c) / c * 1000) / 10 : 0;
        info.style.color = Math.abs(e) > 10 || (type === 'AUC' && !mesures.clairance) ? '#b91c1c' : (e ? '#b45309' : 'var(--doux)');
        info.textContent = type === 'AUC' && !mesures.clairance ? 'indiquez la clairance' : (c !== null && type !== 'DT' ? 'Calculé : ' + c + (e ? ' · écart ' + (e > 0 ? '+' : '') + String(e).replace('.', ',') + ' %' : '') : '');
    }
    function chargerApercu(code) {
        q('apercu-lignes').innerHTML = ''; q('apercu').hidden = true;
        fetch('{{ url('/protocoles') }}/' + encodeURIComponent(code) + '/lignes', { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : []).then(l => { l.filter(x => x.medicament).forEach(ajouterLigne); calculer(); });
    }
    function apercu(sc, poids, clairance) {
        mesures = { sc, poids, clairance };
        q('apercu').hidden = !q('apercu-lignes').children.length;
        q('apercu-lignes').querySelectorAll('tr').forEach(majLigne);
    }
    q('ajout-ligne-btn').addEventListener('click', () => {
        const nom = q('ajout-ligne').value.trim(); if (!nom) return;
        ajouterLigne({ medicament: nom, chimio: false, dose: '', dilution: '', duree: '' }); q('ajout-ligne').value = '';
    });
    let minuterie = null;
    q('ajout-ligne').addEventListener('input', () => {
        clearTimeout(minuterie); const v = q('ajout-ligne').value.trim(); if (v.length < 2) return;
        minuterie = setTimeout(() => fetch('{{ route('medicaments.recherche') }}?q=' + encodeURIComponent(v), { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : []).then(liste => { q('medicaments-ajout').innerHTML = liste.map(x => '<option value="' + echap(x.libelle) + '"></option>').join(''); }), 300);
    });
    if (q('cod-prot').value) { const l = lignes.find(x => x.dataset.code === q('cod-prot').value); if (l) { l.classList.add('actif'); q('protocole-nom').textContent = l.dataset.nom; q('f-valider').disabled = false; chargerApercu(l.dataset.code); } }
    function calculer() {
        const p = parseFloat(q('f-poids').value), t = parseFloat(q('f-taille').value);
        let s = null;
        // Même formule que le serveur (Boyd, tronquée à 3 décimales, comme Clinisys).
        if (p > 0 && t > 0) {
            const g = p * 1000; s = Math.floor(0.0003207 * Math.pow(t, 0.3) * Math.pow(g, 0.7285 - 0.0188 * Math.log10(g)) * 1000) / 1000;
            q('f-sc').textContent = s.toFixed(3).replace('.', ',') + ' m²';
        } else q('f-sc').textContent = '—';
        apercu(s, p > 0 ? p : null, parseFloat(q('f-clairance').value) || null);
        const debut = q('f-debut').value, n = parseInt(q('f-cures').value), i = parseInt(q('f-intercure').value);
        const jours = (q('f-jours').value.match(/\d+/g) || []).map(Number);
        const chevauche = i > 0 && jours.length && Math.max(...jours) >= i;
        q('f-alerte').hidden = !chevauche;
        q('f-valider').disabled = chevauche || !q('cod-prot').value;
        if (debut && n > 0 && i > 0 && jours.length) {
            const d = new Date(debut + 'T12:00:00'); d.setDate(d.getDate() + (n - 1) * i + Math.max(...jours) - 1);
            q('f-fin').textContent = d.toLocaleDateString('fr-FR');
            // Calendrier : date de chaque jour de chaque cure, pour voir le rythme avant de créer.
            const date = k => { const x = new Date(debut + 'T12:00:00'); x.setDate(x.getDate() + k); return x.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }); };
            const cures = [];
            for (let c = 0; c < Math.min(n, 12); c++) cures.push('<b>Cure ' + (c + 1) + '</b> ' + jours.map(j => 'J' + j + ' ' + date(c * i + j - 1)).join(', '));
            q('apercu-calendrier').innerHTML = cures.join(' · ') + (n > 12 ? ' …' : '');
        } else { q('f-fin').textContent = '—'; q('apercu-calendrier').textContent = ''; }
    }
    ['f-poids', 'f-taille', 'f-debut', 'f-cures', 'f-intercure', 'f-jours', 'f-clairance'].forEach(i => q(i).addEventListener('input', calculer));
    calculer();
})();
</script>
@endpush
