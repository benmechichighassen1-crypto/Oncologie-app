@extends('layouts.app')
@section('titre', 'Statistiques')
@php
    $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $var = fn ($v) => $v === null ? '—' : (($v > 0 ? '+' : '').str_replace('.', ',', $v).' %');
    $couleurVar = fn ($v) => $v === null ? 'var(--doux)' : ($v >= 0 ? '#15803d' : '#b91c1c');
    $palette = ['#94a3b8', '#60a5fa', '#1e3a8a', '#0ea5e9', '#a855f7'];
    $nomMedecin = collect($listeMedecins)->firstWhere('CodMed', $f['medecin'])->NomMed ?? '';
@endphp
@push('tete')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
    main { max-width:1500px; }
    .filtres-stats { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
    .filtres-stats label { font-size:12px; color:var(--doux); display:block; } .filtres-stats input, .filtres-stats select { margin-top:3px; }
    .types label { display:inline-flex; align-items:center; gap:5px; padding:6px 10px; border:1px solid var(--bord); border-radius:999px; cursor:pointer; color:var(--texte); font-size:13px; }
    .raccourcis button { font-size:12px; }
    .kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:16px; } @media (max-width:900px) { .kpis { grid-template-columns:repeat(2, 1fr); } }
    .kpi { background:#fff; border:1px solid var(--bord); border-radius:10px; padding:14px 16px; border-top:4px solid var(--c); }
    .kpi .val { font-size:28px; font-weight:700; } .kpi .lib { color:var(--doux); font-size:13px; } .kpi .var { font-size:13px; font-weight:600; }
    .graphes { display:grid; grid-template-columns: 1fr 1fr; gap:16px; } @media (max-width:1100px) { .graphes { grid-template-columns:1fr; } }
    .graphe { position:relative; height:340px; }
    td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
</style>
@endpush
@section('contenu')
<div class="carte">
    <form method="get" class="filtres-stats" id="filtres">
        <div class="types" style="display:flex;gap:6px" title="Séances : chaque passage compte. Patients : chaque patient ne compte qu'une fois sur la période.">
            <label><input type="radio" name="mode" value="seances" @checked($f['mode'] === 'seances')> Par séance</label>
            <label><input type="radio" name="mode" value="patients" @checked($f['mode'] === 'patients')> Par patient</label>
        </div>
        <label>Du<input type="date" name="du" value="{{ $f['du'] }}" required></label>
        <label>Au<input type="date" name="au" value="{{ $f['au'] }}" required></label>
        <div class="raccourcis" style="display:flex;gap:4px">
            <button type="button" class="btn discret" data-mois="1">1 mois</button>
            <button type="button" class="btn discret" data-mois="3">3 mois</button>
            <button type="button" class="btn discret" data-mois="12">12 mois</button>
            <button type="button" class="btn discret" data-annee="1">Cette année</button>
        </div>
        <label>Regrouper par<select name="pas">
            @foreach(['auto' => 'Automatique', 'jour' => 'Jour', 'semaine' => 'Semaine', 'mois' => 'Mois'] as $c => $l)<option value="{{ $c }}" @selected($f['pas'] === $c)>{{ $l }}</option>@endforeach
        </select></label>
        <label style="min-width:260px">Médecin
            <input list="liste-med" id="medecin-texte" placeholder="Tous les médecins (tapez un nom)" value="{{ $nomMedecin }}" autocomplete="off" style="width:100%">
            <input type="hidden" name="medecin" id="medecin-code" value="{{ $f['medecin'] }}">
            <datalist id="liste-med">@foreach($listeMedecins as $m)<option value="{{ $m->NomMed }}" data-code="{{ $m->CodMed }}"></option>@endforeach</datalist>
        </label>
        <div class="types" style="display:flex;gap:6px">
            @foreach($libelles as $t => $l)
                <label><input type="checkbox" name="types[]" value="{{ $t }}" @checked(in_array($t, $f['types'], true))>
                    <i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:{{ $couleurs[$t] }}"></i>{{ $l }}</label>
            @endforeach
        </div>
        <label>Centre de chimiothérapie<select name="centre_chimio">
            <option value="">Les deux centres</option>
            @foreach($centresChimio as $c => $l)<option value="{{ $c }}" @selected($f['centre_chimio'] === (string) $c)>{{ $l }}</option>@endforeach
        </select></label>
        <label>Courbe annuelle<select name="annees">@foreach([1, 2, 3, 4, 5] as $a)<option value="{{ $a }}" @selected($f['annees'] === $a)>{{ $a }} an{{ $a > 1 ? 's' : '' }}</option>@endforeach</select></label>
        <button class="btn" type="submit">Afficher</button>
    </form>
</div>

<div class="kpis">
    @foreach($libelles as $t => $l)
        <div class="kpi" style="--c:{{ $couleurs[$t] }}">
            <div class="lib">{{ $l }}</div>
            <div class="val">{{ $fmt($kpi[$t]['n']) }}</div>
            <div class="var" style="color:{{ $couleurVar($kpi[$t]['variation']) }}">{{ $var($kpi[$t]['variation']) }} <span class="doux" style="font-weight:400">vs période précédente ({{ $fmt($kpi[$t]['prec']) }})</span></div>
        </div>
    @endforeach
    <div class="kpi" style="--c:#0f172a">
        <div class="lib">Total {{ $nomMedecin ? '· '.$nomMedecin : '' }}</div>
        <div class="val">{{ $fmt($kpi['TOTAL']['n']) }}</div>
        <div class="var" style="color:{{ $couleurVar($kpi['TOTAL']['variation']) }}">{{ $var($kpi['TOTAL']['variation']) }} <span class="doux" style="font-weight:400">vs {{ $periodePrec[0]->format('d/m/Y') }} – {{ $periodePrec[1]->format('d/m/Y') }}</span></div>
    </div>
</div>

<div class="graphes">
    <div class="carte">
        <h2>{{ $unite }} du {{ \Carbon\Carbon::parse($f['du'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($f['au'])->format('d/m/Y') }} <span class="doux" style="font-weight:400">par {{ $pas }}</span></h2>
        <div class="graphe"><canvas id="courbe-periode"></canvas></div>
    </div>
    <div class="carte">
        <h2 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">Évolution mois par mois, par année
            <span class="types centres-annuel" style="display:flex;gap:6px;margin-left:auto">
                @foreach($f['types'] as $t)<label><input type="checkbox" value="{{ $t }}" checked><i style="display:inline-block;width:10px;height:10px;border-radius:3px;background:{{ $couleurs[$t] }}"></i>{{ $libelles[$t] }}</label>@endforeach
            </span></h2>
        <div class="graphe"><canvas id="courbe-annuelle"></canvas></div>
    </div>
</div>

<div class="carte">
    <h2>Activité par médecin <span class="doux" style="font-weight:400">({{ mb_strtolower($unite) }})</span></h2>
    <table>
        <thead><tr><th>Médecin</th>@foreach($libelles as $t => $l)<th class="num">{{ $l }}</th>@endforeach<th class="num">Total</th><th class="num">Variation</th><th></th></tr></thead>
        @forelse($tableau as $l)
            <tr>
                <td>{{ $l['nom'] }}</td>
                @foreach($libelles as $t => $x)<td class="num">{{ $l[$t] ? $fmt($l[$t]) : '' }}</td>@endforeach
                <td class="num"><b>{{ $fmt($l['total']) }}</b></td>
                <td class="num" style="color:{{ $couleurVar($l['variation']) }}">{{ $var($l['variation']) }}</td>
                <td><a href="?{{ http_build_query(array_merge(request()->except('medecin'), ['medecin' => $l['code']])) }}" class="btn discret">Voir</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="doux">Aucune activité sur la période.</td></tr>
        @endforelse
    </table>
    <p class="doux" style="font-size:12px;margin-top:8px">En mode « par patient », chaque patient ne compte qu'une fois par période, par centre et par médecin, et chaque centre est compté à part (le total additionne les centres). Séances retenues : celles où le patient est venu : consultation avec dossier d'admission, chimiothérapie avec arrivée enregistrée, radiothérapie en salle, en traitement ou terminée (absents exclus). Médecin : celui de la consultation, ou le médecin traitant du dossier pour la chimiothérapie et la radiothérapie.</p>
</div>
@endsection
@push('scripts')
<script>
(function () {
    const libelles = @json($libelles), couleurs = @json($couleurs);
    const courbe = @json($courbe), annuel = @json($annuel), palette = @json($palette);
    const nf = new Intl.NumberFormat('fr-FR');
    const options = (titreY) => ({
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ' : ' + nf.format(c.parsed.y) } } },
        scales: { y: { beginAtZero: true, title: { display: true, text: titreY }, ticks: { precision: 0 } } },
        elements: { line: { tension: .25 }, point: { radius: 2 } },
    });

    // Courbe de la période : une ligne par centre.
    const jeux = Object.entries(courbe.series).map(([t, v]) => ({ label: libelles[t], data: v, borderColor: couleurs[t], backgroundColor: couleurs[t], borderWidth: 2 }));
    new Chart(document.getElementById('courbe-periode'), { type: 'line', data: { labels: courbe.libelles, datasets: jeux }, options: options(@json($unite)) });

    // Courbe annuelle : 12 mois, une ligne par année ; somme des centres cochés au-dessus de la courbe.
    const annees = [...new Set(Object.values(annuel).flatMap(a => Object.keys(a)))].sort();
    const centresCoches = () => [...document.querySelectorAll('.centres-annuel input:checked')].map(c => c.value);
    const somme = (annee) => Array.from({ length: 12 }, (_, m) => {
        const v = centresCoches().map(t => (annuel[t] && annuel[t][annee]) ? annuel[t][annee][m] : 0);
        return v.some(x => x === null) ? null : v.reduce((s, x) => s + (x || 0), 0);
    });
    const graphAnnuel = new Chart(document.getElementById('courbe-annuelle'), {
        type: 'line',
        data: { labels: ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'],
            datasets: annees.map((a, i) => ({ label: a, data: somme(a), spanGaps: false,
                borderColor: palette[palette.length - annees.length + i] || '#1e3a8a', backgroundColor: palette[palette.length - annees.length + i] || '#1e3a8a',
                borderWidth: i === annees.length - 1 ? 3 : 2 })) },
        options: options(@json($unite.' par mois')),
    });
    document.querySelectorAll('.centres-annuel input').forEach(c => c.addEventListener('change', () => {
        graphAnnuel.data.datasets.forEach(d => d.data = somme(d.label));
        graphAnnuel.update();
    }));
    // Médecin : nom choisi dans la liste -> code.
    const texte = document.getElementById('medecin-texte'), code = document.getElementById('medecin-code');
    const codes = new Map([...document.querySelectorAll('#liste-med option')].map(o => [o.value.toUpperCase(), o.dataset.code]));
    texte.addEventListener('input', () => { const v = texte.value.trim().toUpperCase(); code.value = v === '' ? '' : (codes.get(v) ?? code.value); });
    texte.addEventListener('change', () => { if (texte.value.trim() === '') code.value = ''; });

    // Raccourcis de période.
    const du = document.querySelector('[name=du]'), au = document.querySelector('[name=au]');
    const iso = d => d.toISOString().slice(0, 10);
    document.querySelectorAll('.raccourcis button').forEach(b => b.addEventListener('click', () => {
        const fin = new Date(); let debut = new Date();
        if (b.dataset.annee) debut = new Date(fin.getFullYear(), 0, 1, 12);
        else debut.setMonth(debut.getMonth() - Number(b.dataset.mois));
        du.value = iso(debut); au.value = iso(fin); document.getElementById('filtres').submit();
    }));
})();
</script>
@endpush
