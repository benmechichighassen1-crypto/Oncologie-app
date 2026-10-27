@extends('layouts.app')
@section('titre', 'Calendrier')
@push('tete')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<style>
    .barre { position:sticky; top:0; z-index:5; }
    .barre .filtres { gap:14px; }
    .recherche-med { position:relative; }
    .recherche-med input { width:300px; padding-right:28px; }
    .recherche-med button { position:absolute; right:4px; top:50%; transform:translateY(-50%); border:0; background:none; cursor:pointer; color:#64748b; font-size:16px; }
    #patient-resultats { position:absolute; top:100%; left:0; width:430px; max-height:340px; overflow:auto; z-index:20; margin-top:4px;
        background:#fff; border:1px solid var(--bord); border-radius:8px; box-shadow:0 6px 20px rgba(0,0,0,.12); font-size:13px; }
    #patient-resultats .ligne { display:flex; gap:8px; padding:6px 10px; border-bottom:1px solid #f1f5f9; cursor:pointer; }
    #patient-resultats .ligne:hover { background:#eff6ff; }
    #patient-resultats .ligne .q { width:92px; white-space:nowrap; font-weight:600; }
    #patient-resultats .vide { padding:8px 10px; color:#64748b; }
    .types label { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border:1px solid var(--bord); border-radius:999px; cursor:pointer; user-select:none; }
    .types input { display:none; }
    .types input:not(:checked) + span { opacity:.35; text-decoration:line-through; }
    .pastille { display:inline-block; width:10px; height:10px; border-radius:3px; }
    .statuts label { display:inline-flex; align-items:center; cursor:pointer; padding:3px 8px; border:1px solid var(--bord); border-radius:999px; background:#fff; }
    .statuts input { display:none; } .statuts input:not(:checked) + span { opacity:.35; text-decoration:line-through; }
    .legende { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-top:8px; font-size:12px; }
    #compteurs { margin-left:auto; font-size:12px; }
    #calendrier { background:#fff; font-size:12px; }
    .aller-a { margin-left:8px; padding:5px 8px; font:inherit; font-size:13px; border:1px solid var(--bord); border-radius:6px; vertical-align:middle; cursor:pointer; }
    .fc-event { cursor:pointer; border-width:0 0 0 4px !important; border-radius:3px; }
    .fc .fc-timegrid-event .fc-event-main { padding:0 3px; line-height:1.2; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
    .fc .fc-timegrid-slot { height:1.6em; }
    .fc .fc-timegrid-more-link { font-size:11px; background:#e2e8f0; }
    .fc .fc-list-event-title small { color:#64748b; }
</style>
@endpush
@section('contenu')
<div class="carte barre">
    <div class="filtres">
        <div class="recherche-med">
            <input id="medecin-recherche" list="medecins" placeholder="Tapez le nom d'un médecin…" autocomplete="off"
                   value="{{ collect($medecins)->firstWhere('CodMed', $medecinDefaut)->NomMed ?? '' }}">
            <button type="button" id="medecin-effacer" title="Tous les médecins">×</button>
            <datalist id="medecins">
                @foreach($medecins as $m)<option value="{{ $m->NomMed }}" data-code="{{ $m->CodMed }}"></option>@endforeach
            </datalist>
        </div>
        <div class="recherche-med" id="recherche-patient">
            <input id="patient-recherche" placeholder="Patient : nom, prénom ou n° de dossier" autocomplete="off">
            <button type="button" id="patient-effacer" title="Effacer la recherche">×</button>
            <div id="patient-resultats" hidden></div>
        </div>
        <div class="types filtres">
            @foreach($couleurs as $type => $couleur)
                <label><input type="checkbox" class="type" value="{{ $type }}" checked>
                    <span><i class="pastille" style="background:{{ $couleur }}"></i>
                    {{ ['CONSULTATION' => 'Consultation', 'CHIMIO' => 'Chimiothérapie', 'RADIO' => 'Radiothérapie'][$type] }}</span></label>
            @endforeach
        </div>
        <select id="centre" title="Centre de chimiothérapie">
            <option value="">Chimiothérapie : les deux centres</option>
            @foreach($centresChimio as $code => $libelle)<option value="{{ $code }}">{{ $libelle }}</option>@endforeach
        </select>
        <span id="compteurs" class="doux"></span>
    </div>
    <div class="legende doux statuts">Statut (cliquez pour filtrer) :
        @foreach($etats as $code => $etat)<label><input type="checkbox" class="etat" value="{{ $code }}" checked><span><i class="pastille" style="background:{{ $etat['couleur'] }}"></i> {{ $etat['libelle'] }}</span></label>@endforeach
        <span>· Chimiothérapie affichée sur 15 min (heure de fin réelle dans l'info-bulle)</span>
    </div>
    @if(count($medecins) === 0)
        <p class="alerte">Aucun médecin n'est associé à votre compte. Demandez un accès à l'administrateur.</p>
    @endif
</div>
<div class="carte"><div id="calendrier"></div></div>
@endsection
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const champMed = document.getElementById('medecin-recherche');
    const codes = new Map([...document.querySelectorAll('#medecins option')].map(o => [o.value.toUpperCase(), o.dataset.code]));
    let medecin = @json($medecinDefaut ?? '');
    let patient = '';

    const echapper = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const parametres = () => {
        const p = new URLSearchParams();
        // Une recherche de patient porte sur tous les médecins autorisés, pas seulement celui choisi.
        if (patient) p.set('q', patient); else if (medecin) p.set('medecin', medecin);
        const centre = document.getElementById('centre').value;
        if (centre) p.set('centre', centre);
        document.querySelectorAll('.type:checked').forEach(c => p.append('types[]', c.value));
        return p;
    };

    const calendrier = new FullCalendar.Calendar(document.getElementById('calendrier'), {
        locale: 'fr', firstDay: 1, initialView: 'timeGridWeek', allDaySlot: false, nowIndicator: true,
        slotDuration: '00:15:00', slotLabelInterval: '01:00', slotMinTime: '07:00:00', slotMaxTime: '24:00:00', // la radiothérapie travaille parfois jusqu'à 23 h
        expandRows: true, height: 'auto', eventMaxStack: 4, slotEventOverlap: false, displayEventEnd: false,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridWeek,timeGridDay,listDay,listWeek' },
        buttonText: { today: "Aujourd'hui", week: 'Semaine', day: 'Jour', listDay: 'Liste du jour', listWeek: 'Liste semaine' },
        moreLinkContent: arg => '+' + arg.num,
        events: function (info, ok, ko) {
            const p = parametres();
            if (!p.getAll('types[]').length) { ok([]); afficherCompteurs([]); return; }
            p.set('start', info.startStr); p.set('end', info.endStr);
            fetch('{{ route('planning.evenements') }}?' + p.toString(), { headers: { 'Accept': 'application/json' } })
                .then(r => r.ok ? r.json() : Promise.reject(r)).then(ev => {
                    const etats = [...document.querySelectorAll('.etat:checked')].map(c => Number(c.value));
                    ev = ev.filter(e => etats.includes(e.extendedProps.etatCode));
                    afficherCompteurs(ev); ok(ev);
                }).catch(ko);
        },
        eventContent: function (arg) {
            const x = arg.event.extendedProps;
            const detail = [x.seance, x.ressource].filter(Boolean).map(echapper).join(' · ');
            if (arg.view.type.startsWith('list')) {
                return { html: echapper(arg.event.title) + ' <small>' + [x.type, x.etat, detail, x.medecin].filter(Boolean).map(echapper).join(' · ') + '</small>' };
            }
            return { html: '<b>' + echapper(arg.timeText) + '</b> ' + echapper(arg.event.title) + (detail ? ' <span style="opacity:.85">· ' + detail + '</span>' : '') };
        },
        eventDidMount: function (arg) {
            const x = arg.event.extendedProps;
            arg.el.title = [arg.event.title, x.type + (x.finReelle ? ' (fin ' + x.finReelle + ')' : ''), x.etat,
                x.heurePrevue && 'Heure réelle de passage (RDV prévu à ' + x.heurePrevue + ' dans Clinisys)', x.seance && 'Séance ' + x.seance, x.ressource, x.centre, x.medecin, x.organisme, x.numdoss && 'Dossier ' + x.numdoss]
                .filter(Boolean).join('\n');
        },
    });
    calendrier.render();

    // Petit calendrier à côté de « Aujourd'hui » : aller directement à une date éloignée.
    const allerA = document.createElement('input');
    allerA.type = 'date'; allerA.title = 'Aller à une date'; allerA.className = 'aller-a';
    document.querySelector('.fc-today-button').after(allerA);
    allerA.addEventListener('change', () => { if (allerA.value) calendrier.gotoDate(allerA.value); });
    // Date locale (toISOString passerait en UTC et pourrait donner la veille).
    const jourLocal = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    calendrier.on('datesSet', () => { allerA.value = jourLocal(calendrier.getDate()); });
    allerA.value = jourLocal(calendrier.getDate());

    function afficherCompteurs(ev) {
        const n = t => ev.filter(e => e.extendedProps.type === t).length;
        document.getElementById('compteurs').textContent = ev.length
            ? `${ev.length} RDV · ${n('CONSULTATION')} consult. · ${n('CHIMIO')} chimio · ${n('RADIO')} radio` : 'Aucun RDV';
    }

    function choisirMedecin() {
        const texte = champMed.value.trim().toUpperCase();
        const code = texte === '' ? '' : (codes.get(texte) ?? null);
        if (code === null) return; // saisie en cours : on attend un nom de la liste
        if (code !== medecin) { medecin = code; calendrier.refetchEvents(); }
    }
    champMed.addEventListener('change', choisirMedecin);
    champMed.addEventListener('input', choisirMedecin);
    document.getElementById('medecin-effacer').addEventListener('click', () => { champMed.value = ''; choisirMedecin(); champMed.focus(); });
    // Recherche d'un patient : filtre le calendrier et liste ses RDV (prochains d'abord) pour s'y rendre.
    const champPat = document.getElementById('patient-recherche'), resultats = document.getElementById('patient-resultats');
    const fmt = d => d.split('-').reverse().join('/');
    let minuterie = null;
    function chercherPatient() {
        const texte = champPat.value.trim();
        if (texte.length > 0 && texte.length < 2) return;
        if (texte !== patient) { patient = texte; calendrier.refetchEvents(); }
        if (!texte) { resultats.hidden = true; return; }
        resultats.hidden = false; resultats.innerHTML = '<div class="vide">Recherche…</div>';
        fetch('{{ route('planning.recherche') }}?q=' + encodeURIComponent(texte), { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject(r)).then(liste => {
                if (champPat.value.trim() !== texte) return;
                resultats.innerHTML = liste.length ? liste.map(s =>
                    '<div class="ligne" data-date="' + s.date + '"><span class="q">' + fmt(s.date) + ' ' + echapper(s.heure) + '</span><span><b>'
                    + echapper(s.patient) + '</b><br><span class="doux">' + [s.type, s.etat, s.numdoss && 'Dossier ' + s.numdoss].filter(Boolean).map(echapper).join(' · ')
                    + '</span></span>' + (s.identifiant ? '<a href="{{ url('/patients') }}/' + encodeURIComponent(s.identifiant) + '" style="margin-left:auto" onclick="event.stopPropagation()">Dossier</a>' : '') + '</div>').join('')
                    : '<div class="vide">Aucun RDV trouvé entre 3 mois avant et 6 mois après aujourd\'hui.</div>';
            }).catch(() => { resultats.innerHTML = '<div class="vide">Recherche impossible.</div>'; });
    }
    champPat.addEventListener('input', () => { clearTimeout(minuterie); minuterie = setTimeout(chercherPatient, 400); });
    champPat.addEventListener('focus', () => { if (patient) resultats.hidden = false; });
    resultats.addEventListener('click', e => {
        const l = e.target.closest('.ligne'); if (!l) return;
        calendrier.changeView('timeGridDay', l.dataset.date); resultats.hidden = true;
    });
    document.addEventListener('click', e => { if (!e.target.closest('#recherche-patient')) resultats.hidden = true; });
    document.getElementById('patient-effacer').addEventListener('click', () => { champPat.value = ''; chercherPatient(); champPat.focus(); });

    document.querySelectorAll('#centre, .type, .etat').forEach(el => el.addEventListener('change', () => calendrier.refetchEvents()));
});
</script>
@endpush
