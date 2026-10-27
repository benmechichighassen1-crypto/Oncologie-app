@extends('layouts.app')
@use('App\Repositories\OncologieRepository', 'Onco')
@section('titre', $patient->NomCli.' '.$patient->Prenom)
@php
    $d = fn ($v, $f = 'd/m/Y') => $v ? \Carbon\Carbon::parse($v)->format($f) : '';
    $jourLong = fn ($v) => $v ? \Carbon\Carbon::parse($v)->locale('fr')->isoFormat('dddd D MMMM YYYY') : '';
    $id = trim($patient->Identifiant);
    $couleurType = ['Saisie' => '#be185d', 'Consultation' => '#2563eb', 'Chimiothérapie' => '#ea580c', 'Radiothérapie' => '#7c3aed',
        'Admission' => '#0f766e', 'Programme' => '#b45309', 'Prescription' => '#15803d', 'Document' => '#64748b', 'RDV' => '#64748b'];
    $nbDocs = $groupes->sum(fn ($g) => $g->count());
    $actives = $prescriptions->where('statut', '!=', 'arretee')->count();
    $onglets = ['dossier' => 'Dossier', 'documents' => 'Documents ('.$nbDocs.')', 'prescriptions' => 'Prescriptions'.($actives ? ' ('.$actives.')' : ''), 'historique' => 'Historique'];
    $onglet = array_key_exists($onglet, $onglets) ? $onglet : 'dossier';
    $pathologie = $fiche?->pathologie ?: (isset($tumeursClinisys[0]) ? Onco::libellePrincipal($tumeursClinisys[0]->description) : null);
    $tousAntecedents = collect($antecedentsClinisys)->map(fn ($a) => trim($a->antecedent.($a->Observation ? ' ('.$a->Observation.')' : '')))
        ->merge($antecedents->map(fn ($a) => $a->libelle))->filter()->unique()->values();
    $parJour = collect($historique)->groupBy(fn ($e) => substr((string) $e['date'], 0, 10));
    $poso = fn ($p) => collect([$p->dose, $p->voie, $p->frequence, $p->duree ? 'pendant '.$p->duree : null, $p->nb_cures ? $p->nb_cures.' cures' : null])->filter()->implode(' · ');
@endphp
@push('tete')
<style>
    main { max-width:none; padding:14px 18px; }
    .entete { display:flex; flex-wrap:wrap; gap:6px 26px; align-items:baseline; padding:12px 16px; }
    .entete h1 { margin:0; font-size:19px; } .entete .info { font-size:13px; } .entete .info b { color:var(--doux); font-weight:500; margin-right:4px; }
    .patho { flex-basis:100%; font-size:13px; } .patho .tag { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; padding:2px 8px; border-radius:999px; margin-right:6px; }
    .onglets { display:flex; gap:4px; margin:0 0 12px; }
    .onglets a { padding:8px 16px; text-decoration:none; color:var(--doux); font-weight:600; border-radius:8px; }
    .onglets a.actif { color:#fff; background:var(--bleu); }
    .panneau { display:none; } .panneau.actif { display:block; }
    .meta { color:var(--doux); font-size:12px; }
    .puce { display:inline-block; padding:2px 9px; border-radius:999px; color:#fff; font-size:12px; font-weight:600; white-space:nowrap; }
    .deux { display:grid; grid-template-columns: 1fr 1fr; gap:14px; align-items:start; } @media (max-width:1100px) { .deux { grid-template-columns:1fr; } }

    /* Saisie */
    .saisie { position:sticky; top:12px; }
    .jour-dossier { display:flex; align-items:center; gap:10px; padding:8px 12px; margin:-4px 0 12px; background:#f8fafc; border:1px solid var(--bord); border-radius:8px; }
    .saisie label { display:block; font-size:12px; color:var(--doux); margin-bottom:10px; } .saisie input, .saisie select, .saisie textarea { width:100%; margin-top:3px; }
    .types-saisie { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
    .types-saisie input { display:none; } .types-saisie span { display:inline-block; padding:6px 12px; border-radius:999px; border:1px solid var(--bord); cursor:pointer; font-size:13px; }
    .types-saisie input:checked + span { background:var(--bleu); color:#fff; border-color:var(--bleu); }
    .si-diagnostic, .si-antecedent { display:none; }
    .saisie textarea[name=texte] { min-height:260px; font-size:15px; line-height:1.5; }
    .lignes { width:100%; border-collapse:collapse; margin:4px 0 8px; } .lignes td { padding:3px 3px; border:0; } .lignes input, .lignes select { margin:0; padding:6px 8px; }

    /* Protocoles de chimiothérapie */
    .protocole { border-bottom:1px solid var(--bord); } .protocole > summary { padding:8px 12px; cursor:pointer; }
    .protocole > summary:hover { background:#fff7ed; }
    .suggestions { position:absolute; left:0; right:0; top:100%; z-index:30; max-height:320px; overflow:auto; margin-top:2px; background:#fff;
        border:1px solid var(--bord); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); color:var(--texte); font-size:13px; }
    .suggestions .sugg { padding:6px 10px; cursor:pointer; border-bottom:1px solid #f1f5f9; }
    .suggestions .sugg:hover, .suggestions .sugg.actif { background:#eff6ff; }
    .table-protocole { margin:0 0 4px; font-size:13px; }
    .table-protocole tr.ligne-chimio td { background:#fecaca; }
    .table-protocole input { padding:4px 6px; } .table-protocole th, .table-protocole td { padding:4px 8px; }

    /* Historique des consultations */
    .consult { background:#fff; border:1px solid var(--bord); border-radius:10px; margin-bottom:12px; overflow:hidden; }
    .consult-tete { display:flex; align-items:center; gap:10px; padding:8px 12px; background:#f8fafc; border-bottom:1px solid var(--bord); }
    .consult-tete .date { font-weight:700; text-transform:capitalize; }
    .consult-corps { padding:10px 12px; }
    .note { margin-bottom:10px; } .note .texte { white-space:pre-wrap; margin-top:3px; font-size:14px; line-height:1.5; }
    .rx { margin:6px 0 0; padding:8px 10px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; }
    .rx ul { margin:4px 0 0; padding-left:18px; } .rx li { margin-bottom:2px; }

    /* Chronologie (onglet prescriptions) */
    .jour { border-left:2px solid var(--bord); margin-left:6px; padding:0 0 4px 16px; position:relative; }
    .jour::before { content:''; position:absolute; left:-6px; top:4px; width:10px; height:10px; border-radius:50%; background:var(--bleu); }
    .jour h3 { margin:0 0 6px; font-size:13px; color:var(--bleu); text-transform:capitalize; }
    .evt { display:flex; gap:10px; padding:5px 10px; margin-bottom:5px; background:#fff; border:1px solid var(--bord); border-radius:8px; font-size:13px; }

    /* Documents : catégories à gauche, grand aperçu à droite */
    .ged { display:grid; grid-template-columns: 300px 1fr; gap:10px; height:calc(100vh - 190px); min-height:540px; }
    .ged-liste { overflow:auto; background:#fff; border:1px solid var(--bord); border-radius:10px; }
    .rubrique > summary { padding:8px 12px; background:#fde68a; font-weight:700; cursor:pointer; position:sticky; top:0; z-index:1; border-bottom:1px solid #fcd34d; }
    .rubrique.vide > summary { background:#f1f5f9; color:var(--doux); border-bottom-color:var(--bord); }
    .doc { display:flex; gap:8px; padding:6px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer; font-size:13px; }
    .doc:hover { background:#eff6ff; } .doc.actif { background:#dbeafe; box-shadow:inset 3px 0 0 var(--bleu); }
    .doc .nom { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; } .doc .dt { color:var(--doux); white-space:nowrap; font-size:12px; }
    .ged-apercu { background:#fff; border:1px solid var(--bord); border-radius:10px; display:flex; flex-direction:column; overflow:hidden; }
    .ged-barre { display:flex; gap:6px; align-items:center; padding:6px 8px; border-bottom:1px solid var(--bord); }
    .ged-barre .titre { flex:1; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; padding-left:4px; }
    .ged-vue { flex:1; overflow:auto; background:#e2e8f0; display:flex; justify-content:center; align-items:flex-start; }
    .ged-vue iframe { width:100%; height:100%; border:0; background:#fff; }
    .ged-vue img { max-width:100%; margin:14px; box-shadow:0 2px 10px rgba(0,0,0,.25); background:#fff; }
    .ged.plein { position:fixed; inset:0; z-index:40; height:auto; padding:10px; background:var(--fond); }
    .reclasser select { font-size:12px; padding:5px 6px; max-width:210px; }
</style>
@endpush
@section('contenu')

<div class="carte entete">
    <h1>{{ $patient->NomCli }} {{ $patient->Prenom }}</h1>
    <span class="info doux">{{ $id }}@if($patient->DatNai) · {{ \Carbon\Carbon::parse($patient->DatNai)->age }} ans @endif</span>
    <span class="info"><b>Organisme</b>{{ $derniere->organisme ?? '' ?: 'Payant' }}@if($derniere?->mutuelle) · {{ $derniere->mutuelle }}@endif</span>
    <span class="info"><b>Médecin</b>{{ $derniere->medecin_traitant ?? '' }}</span>
    @if($derniere?->correspondant)<span class="info"><b>Correspondant</b>{{ $derniere->correspondant }}</span>@endif
    @if($pathologie || $tousAntecedents->isNotEmpty())
        <div class="patho">
            @if($pathologie)<span class="tag"><b>{{ $pathologie }}</b>@if($fiche?->stade) · stade {{ $fiche->stade }}@endif @if($fiche?->statut) · {{ Onco::STATUTS[$fiche->statut] ?? '' }}@endif</span>@endif
            @if($tousAntecedents->isNotEmpty())<span class="meta">Antécédents : {{ $tousAntecedents->take(8)->implode(' · ') }}{{ $tousAntecedents->count() > 8 ? ' …' : '' }}</span>@endif
        </div>
    @endif
</div>

<nav class="onglets">
    @foreach($onglets as $cle => $titre)<a href="?onglet={{ $cle }}" data-onglet="{{ $cle }}" class="{{ $onglet === $cle ? 'actif' : '' }}">{{ $titre }}</a>@endforeach
</nav>

{{-- ===== Dossier : saisie du jour à gauche, historique des consultations à droite ===== --}}
<section class="panneau {{ $onglet === 'dossier' ? 'actif' : '' }}" id="p-dossier">
<div class="deux">
    <div class="carte saisie">
        <div class="jour-dossier">
            <span class="puce" style="background:{{ $couleurType[$dossierDuJour['type']] ?? '#2563eb' }}">{{ $dossierDuJour['type'] }}</span>
            <b style="text-transform:capitalize">{{ $jourLong(date('Y-m-d')) }}</b>
            @if($dossierDuJour['numdoss'])<span class="meta">Dossier {{ $dossierDuJour['numdoss'] }}</span>@endif
        </div>
        <form method="post" action="{{ route('dossier.saisie', $id) }}" id="form-saisie">
            @csrf
            <div class="types-saisie">
                @foreach(Onco::SAISIES as $c => $l)
                    <label style="margin:0"><input type="radio" name="type" value="{{ $c }}" @checked(old('type', 'consultation') === $c)><span>{{ $l }}</span></label>
                @endforeach
            </div>
            <div class="si-diagnostic">
                <label>Pathologie <span class="meta">(tapez pour filtrer)</span>
                    <input list="liste-tumeurs" id="tumeur-texte" placeholder="sein, poumon, côlon, prostate…" autocomplete="off" value="{{ $fiche?->pathologie }}"></label>
                <input type="hidden" name="code_tumeur" id="tumeur-code" value="{{ $fiche?->code_tumeur }}">
                <datalist id="liste-tumeurs">@foreach($pathologies as $libelle => $code)<option value="{{ $libelle }}" data-code="{{ $code }}"></option>@endforeach</datalist>
                <div style="display:flex;gap:8px">
                    <label style="flex:1">Stade<input name="stade" maxlength="20" value="{{ $fiche?->stade }}" placeholder="ex. IIB, T2N1M0"></label>
                    <label style="flex:1">Statut<select name="statut"><option value=""></option>
                        @foreach(Onco::STATUTS as $c => $l)<option value="{{ $c }}" @selected($fiche?->statut === $c)>{{ $l }}</option>@endforeach</select></label>
                </div>
            </div>
            <div class="si-antecedent">
                <label>Type d'antécédent<select name="famille">@foreach(Onco::FAMILLES as $c => $l)<option value="{{ $c }}">{{ $l }}</option>@endforeach</select></label>
            </div>
            <label><span id="libelle-texte">Compte rendu de consultation</span><textarea name="texte" maxlength="8000" placeholder="Saisissez ici…">{{ old('texte') }}</textarea></label>

            <div style="display:flex;gap:10px;align-items:center;margin-top:10px">
                <label style="margin:0;width:170px">Date<input type="date" name="date" value="{{ old('date', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}" required></label>
                <span style="flex:1"></span>
                <button class="btn" type="submit">@include('partials.icone', ['n' => 'enregistrer']) Enregistrer</button>
            </div>
            @if($errors->any())<p class="erreur">{{ $errors->first() }}</p>@endif
        </form>
    </div>

    <div>
            {{-- Réduit par défaut ; protocoles du plus récent au plus ancien. --}}
            <details class="consult protocoles" @if(session('statut') && str_starts_with(session('statut'), 'Protocole')) open @endif>
                <summary class="consult-tete" style="cursor:pointer"><b>Protocoles de chimiothérapie</b> <span class="meta">({{ count($protocoles) }}, du plus récent au plus ancien · cliquer pour afficher)</span></summary>
                <div class="consult-corps" style="padding:0">
                    <details class="protocole" id="prescrire-protocole">
                        <summary style="color:var(--bleu);font-weight:600">@include('partials.icone', ['n' => 'plus']) Prescrire un protocole</summary>
                        @include('dossier.partials.protocole-form', ['onglet' => 'dossier'])
                    </details>
                    @foreach($protocoles as $pr)
                        @php $cu = $pr['cure']; $appli = ($pr['source'] ?? '') === 'appli'; @endphp
                        <details class="protocole">
                            <summary>
                                <span class="puce" style="background:{{ $appli ? '#15803d' : '#64748b' }};font-size:11px">{{ $appli ? 'Appli' : 'Clinisys' }}</span>
                                <b style="{{ $appli && $pr['statut'] === 'arretee' ? 'text-decoration:line-through' : '' }}">{{ $pr['nom'] }}</b>@if($appli && $pr['statut'] === 'arretee') <span class="meta">(arrêté)</span>@endif
                                <span class="meta">{{ $d($cu->Date_cre) }}@if($cu->nbreCure) · {{ $cu->nbreCure }} cure(s)@endif @if($cu->nbreSeance) · {{ $cu->nbreSeance }} séance(s)@endif @if($cu->Surface_corporelle) · SC {{ number_format((float) $cu->Surface_corporelle, 2, ',', '') }}@endif · {{ $cu->prescripteur ?? $cu->User_Cre }}</span>
                                <a class="btn sec" style="float:right;padding:4px 10px" href="{{ $appli ? route('dossier.protocole.appli', [$id, $pr['id']]) : route('dossier.protocole', [$id, $cu->numCure]) }}" target="_blank" rel="noopener" onclick="event.stopPropagation()">@include('partials.icone', ['n' => 'imprimer']) Imprimer</a>
                                @if($appli && $pr['statut'] !== 'arretee')
                                    <form method="post" action="{{ route('dossier.protocole.arret', [$id, $pr['id']]) }}" style="float:right;margin-right:6px" onsubmit="return confirm('Arrêter ce protocole ? Il restera visible dans l\'historique.')" onclick="event.stopPropagation()">@csrf @method('PATCH')<button class="btn discret" style="padding:4px 10px">Arrêter</button></form>
                                @endif
                            </summary>
                            <table class="table-protocole">
                                <thead><tr><th>Jour</th><th>Médicament</th><th>Dose</th><th>Dilution</th><th>Durée</th></tr></thead>
                                @forelse($pr['lignes'] as $l)
                                    <tr><td>{{ $l['jour'] }}</td>
                                        <td>{{ $l['medicament'] }}@if($l['observation'])<br><span class="meta">{{ $l['observation'] }}</span>@endif</td>
                                        <td>{{ $l['dose'] }}@if($l['ajustee']) <span class="meta" title="Dose ajustée pour ce patient">●</span>@endif</td>
                                        <td>{{ $l['dilution'] }}</td><td>{{ $l['duree'] }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="meta">Aucune ligne enregistrée pour ce protocole.</td></tr>
                                @endforelse
                            </table>
                            @if(count($pr['seances']))
                                <div class="meta" style="padding:6px 12px 10px">Séances : {{ collect($pr['seances'])->map(fn ($s) => trim($s->codeSeance).($s->datePrevus ? ' ('.$d($s->datePrevus, 'd/m').')' : ''))->implode(' · ') }}</div>
                            @endif
                        </details>
                    @endforeach
                </div>
            </details>
        <h2 style="margin:4px 0 10px">Historique des consultations <span class="meta" style="font-weight:normal">(du plus récent au plus ancien)</span></h2>
        @forelse($consultations as $c)
            <div class="consult">
                <div class="consult-tete">
                    <span class="date">{{ $jourLong($c['date']) }}</span>
                    <span class="puce" style="background:{{ $couleurType[$c['type']] ?? '#2563eb' }}">{{ $c['type'] }}</span>
                    @if($c['numdoss'])<span class="meta">Dossier {{ $c['numdoss'] }}</span>@endif
                    <span style="flex:1"></span>
                    @if(collect($c['prescriptions'])->where('statut', '!=', 'arretee')->isNotEmpty())
                        <a class="btn sec" href="{{ route('dossier.ordonnance', [$id, 'date' => $c['date']]) }}" target="_blank" rel="noopener">@include('partials.icone', ['n' => 'ordonnance']) Ordonnance</a>
                    @endif
                </div>
                <div class="consult-corps">
                    @foreach($c['notes'] as $n)
                        <div class="note">
                            <span class="meta"><b>{{ Onco::NOTES[$n->type] ?? $n->type }}</b> · {{ $n->saisi_par }} · {{ $d($n->saisi_le, 'H:i') }}
                                @if($n->modifie_le ?? null) · <i>modifiée par {{ $n->modifie_par }} le {{ $d($n->modifie_le, 'd/m/Y H:i') }}</i>@endif</span>
                            <span style="float:right">
                                @if(strcasecmp(trim((string) $n->saisi_par), trim((string) auth()->user()->userName)) === 0)
                                    <button type="button" class="btn discret modifier-note" title="Modifier">Modifier</button>
                                @else
                                    <button type="button" class="btn discret" title="Modifier" onclick="alert('Vous n\'êtes pas autorisé à modifier cette note. Seul son auteur peut la modifier.')">Modifier</button>
                                @endif
                                <form method="post" action="{{ route('dossier.masquer', [$id, 'note', $n->id]) }}" style="display:inline" onsubmit="return confirm('Retirer cette saisie ? Son contenu restera dans le journal.')">@csrf @method('DELETE')<button class="btn discret" title="Retirer">@include('partials.icone', ['n' => 'fermer'])</button></form>
                            </span>
                            <div class="texte">{{ $n->texte }}</div>
                            <form method="post" action="{{ route('dossier.note.modifier', [$id, $n->id]) }}" class="edition-note" hidden>
                                @csrf @method('PUT')
                                <textarea name="texte" rows="8" style="width:100%;margin-top:6px" required maxlength="8000">{{ $n->texte }}</textarea>
                                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
                                    <button type="button" class="btn sec annuler-note">Annuler</button>
                                    <button class="btn" type="submit">@include('partials.icone', ['n' => 'enregistrer']) Enregistrer la modification</button>
                                </div>
                            </form>
                        </div>
                    @endforeach
                    @foreach($c['diagnostics'] as $v)
                        <div class="note"><span class="meta"><b>Diagnostic</b> · {{ $v->saisi_par }}</span>
                            <div class="texte">{{ collect([$v->pathologie, $v->stade ? 'Stade '.$v->stade : null, Onco::STATUTS[$v->statut] ?? null])->filter()->implode(' · ') }}@if($v->diagnostic)<br>{{ $v->diagnostic }}@endif</div></div>
                    @endforeach
                    @foreach($c['antecedents'] as $a)
                        <div class="note"><span class="meta"><b>Antécédent {{ mb_strtolower(Onco::FAMILLES[$a->famille] ?? '') }}</b> · {{ $a->saisi_par }}</span>
                            <form method="post" action="{{ route('dossier.masquer', [$id, 'antecedent', $a->id]) }}" style="float:right" onsubmit="return confirm('Retirer cet antécédent ?')">@csrf @method('DELETE')<button class="btn discret" title="Retirer">@include('partials.icone', ['n' => 'fermer'])</button></form>
                            <div class="texte">{{ $a->libelle }}@if($a->details) — {{ $a->details }}@endif</div></div>
                    @endforeach
                    @if(count($c['prescriptions']))
                        <div class="rx"><b>Prescriptions</b>
                            <ul>@foreach($c['prescriptions'] as $p)
                                <li style="{{ $p->statut === 'arretee' ? 'text-decoration:line-through;opacity:.6' : '' }}"><b>{{ $p->libelle }}</b> <span class="meta">{{ $poso($p) }}</span></li>
                            @endforeach</ul>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <p class="doux">Aucune consultation saisie pour l'instant. Les consultations et prescriptions enregistrées à gauche apparaîtront ici, par date.</p>
        @endforelse
    </div>
</div>
</section>

{{-- ===== Documents : catégories à gauche, grand aperçu à droite ===== --}}
<section class="panneau {{ $onglet === 'documents' ? 'actif' : '' }}" id="p-documents">
    <div class="ged" id="ged">
        <div class="ged-liste">
            @foreach($groupes as $groupe => $docs)
                <details class="rubrique {{ $docs->isEmpty() ? 'vide' : '' }}" @if($docs->isNotEmpty()) open @endif>
                    <summary>{{ $groupe }} ({{ $docs->count() }})</summary>
                    @foreach($docs as $doc)
                        <div class="doc" tabindex="0" data-url="{{ route('pj.voir', [$id, $doc->source, $doc->doc_id]) }}"
                             data-ext="{{ strtolower(ltrim((string) $doc->Extension, '.')) }}" data-titre="{{ $doc->type }} · {{ $d($doc->Date_doc) }}"
                             data-classer="{{ route('pj.classer', [$id, $doc->source, $doc->doc_id]) }}" data-code="{{ $doc->code_type }}"
                             title="{{ $doc->Nom_doc }} · {{ $doc->Users }}">
                            <span class="nom">{{ $doc->type }}@if($doc->corrige) <span class="meta">(corrigé)</span>@endif</span>
                            <span class="dt">{{ $d($doc->Date_doc) }}</span>
                        </div>
                    @endforeach
                </details>
            @endforeach
        </div>
        <div class="ged-apercu">
            <div class="ged-barre">
                <button type="button" class="btn discret" id="vis-prec" title="Document précédent (↑)">@include('partials.icone', ['n' => 'precedent'])</button>
                <button type="button" class="btn discret" id="vis-suiv" title="Document suivant (↓)">@include('partials.icone', ['n' => 'suivant'])</button>
                <span class="titre" id="vis-titre">Choisissez un document dans la liste</span>
                <form method="post" id="vis-classer" class="reclasser" hidden>@csrf
                    <select name="code_type" onchange="this.form.submit()" title="Corriger le type si le document est mal classé">
                        @foreach($types as $code => $t)<option value="{{ $code }}">{{ $t['Des_Type'] }}</option>@endforeach
                    </select>
                </form>
                <button type="button" class="btn sec" id="vis-plein" title="Plein écran (Échap pour sortir)">@include('partials.icone', ['n' => 'agrandir'])</button>
                <button type="button" class="btn sec" id="vis-imprimer" disabled>@include('partials.icone', ['n' => 'imprimer']) Imprimer</button>
                <a class="btn" id="vis-telecharger" href="#" download aria-disabled="true">@include('partials.icone', ['n' => 'telecharger']) Télécharger</a>
            </div>
            <div class="ged-vue" id="vis-contenu"></div>
        </div>
    </div>
</section>

{{-- ===== Prescriptions ===== --}}
<section class="panneau {{ $onglet === 'prescriptions' ? 'actif' : '' }}" id="p-prescriptions">
    <div style="max-width:1200px">
        <div class="carte">
            <h2>Nouvelle prescription</h2>
            <div class="filtres" style="margin-bottom:8px">
                <label class="meta">Type<br><select id="rx-type">@foreach(Onco::TYPES_PRESCRIPTION as $c => $l)<option value="{{ $c }}" @selected($c === 'medicament')>{{ $l }}</option>@endforeach</select></label>
            </div>
            {{-- Médicament, examen, autre : saisie libre, avec suggestions de la liste des médicaments. --}}
            <form method="post" action="{{ route('dossier.prescription', $id) }}" class="filtres" id="rx-simple" style="align-items:flex-end">
                @csrf
                <input type="hidden" name="type" id="rx-type-champ" value="medicament">
                <label class="meta">Date<br><input type="date" name="date_prescription" value="{{ date('Y-m-d') }}" required></label>
                <label class="meta" style="flex:1;min-width:260px;position:relative">Libellé <span id="rx-aide"></span><br>
                    <input name="libelle" id="rx-libelle" required maxlength="255" style="width:100%" autocomplete="off">
                    <div id="rx-suggestions" class="suggestions" hidden></div></label>
                <label class="meta">Dose<br><input name="dose" maxlength="60" size="9"></label>
                <label class="meta">Voie<br><select name="voie"><option value=""></option>@foreach(Onco::VOIES as $v)<option>{{ $v }}</option>@endforeach</select></label>
                <label class="meta">Fréquence<br><input name="frequence" maxlength="80" size="10" placeholder="ex. 2 fois/j"></label>
                <label class="meta">Pendant<br><input name="duree" maxlength="60" size="10" placeholder="ex. 10 jours"></label>
                <button class="btn" type="submit">@include('partials.icone', ['n' => 'plus']) Prescrire</button>
            </form>
            {{-- Protocole : même écran que dans le dossier (poids, taille, SC, doses). --}}
            <div id="rx-protocole" style="display:none">
                @include('dossier.partials.protocole-form', ['onglet' => 'prescriptions'])
            </div>
        </div>
        @php $protocolesAppli = collect($protocoles)->where('source', 'appli'); @endphp
        @if($protocolesAppli->isNotEmpty())
            <div class="carte">
                <h2 style="margin-top:0">Protocoles prescrits dans l'appli</h2>
                <table>
                    <thead><tr><th>Date</th><th>Protocole</th><th>SC</th><th>Prescripteur</th><th>Statut</th><th></th></tr></thead>
                    @foreach($protocolesAppli as $pr)
                        <tr style="{{ $pr['statut'] === 'arretee' ? 'opacity:.5' : '' }}">
                            <td>{{ $d($pr['cure']->Date_cre) }}</td>
                            <td><b>{{ $pr['nom'] }}</b><br><span class="meta">{{ collect($pr['lignes'])->pluck('medicament')->filter()->unique()->implode(' · ') }}</span></td>
                            <td>{{ $pr['cure']->Surface_corporelle ? number_format((float) $pr['cure']->Surface_corporelle, 2, ',', '').' m²' : '' }}</td>
                            <td>{{ $pr['cure']->prescripteur }}</td>
                            <td>{{ $pr['statut'] === 'arretee' ? 'Arrêté' : 'Prescrit' }}</td>
                            <td><a class="btn sec" href="{{ route('dossier.protocole.appli', [$id, $pr['id']]) }}" target="_blank" rel="noopener">@include('partials.icone', ['n' => 'imprimer']) Imprimer</a></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
        <div class="carte">
            <table>
                <thead><tr><th>Date</th><th>Prescription</th><th>Statut</th><th></th><th></th></tr></thead>
                @forelse($prescriptions->groupBy('date_prescription') as $date => $lignes)
                    @foreach($lignes as $p)
                        @php $auteur = strcasecmp(trim((string) $p->prescrit_par), trim((string) auth()->user()->userName)) === 0; @endphp
                        <tr style="{{ $p->statut === 'arretee' ? 'opacity:.5' : '' }}">
                            <td>{{ $d($p->date_prescription) }}</td>
                            <td><b>{{ $p->libelle }}</b><br><span class="meta">{{ $poso($p) }} · {{ $p->prescrit_par }}
                                @if($p->modifie_le ?? null) · <i>modifiée le {{ $d($p->modifie_le, 'd/m/Y H:i') }}</i>@endif</span></td>
                            <td><form method="post" action="{{ route('dossier.prescription.statut', [$id, $p->id]) }}">@csrf @method('PATCH')
                                <select name="statut" onchange="this.form.submit()">@foreach(Onco::STATUTS_PRESCRIPTION as $c => $l)<option value="{{ $c }}" @selected($p->statut === $c)>{{ $l }}</option>@endforeach</select></form></td>
                            <td style="white-space:nowrap">
                                @if($auteur)
                                    <button type="button" class="btn discret" onclick="const l = document.getElementById('rx-edit-{{ $p->id }}'); l.hidden = !l.hidden;">Modifier</button>
                                    <form method="post" action="{{ route('dossier.prescription.supprimer', [$id, $p->id]) }}" style="display:inline" onsubmit="return confirm('Supprimer cette prescription ? Son contenu restera dans le journal.')">@csrf @method('DELETE')<button class="btn discret" style="color:#b91c1c">Supprimer</button></form>
                                @else
                                    <button type="button" class="btn discret" onclick="alert('Vous n\'êtes pas autorisé à modifier cette prescription. Seul son auteur peut la modifier ou la supprimer.')">Modifier</button>
                                @endif
                            </td>
                            <td>@if($loop->first && $lignes->where('statut', '!=', 'arretee')->isNotEmpty())
                                <a class="btn sec" href="{{ route('dossier.ordonnance', [$id, 'date' => $date]) }}" target="_blank" rel="noopener">@include('partials.icone', ['n' => 'ordonnance']) Ordonnance</a>@endif</td>
                        </tr>
                        @if($auteur)
                            <tr id="rx-edit-{{ $p->id }}" hidden><td colspan="5" style="background:#f8fafc">
                                <form method="post" action="{{ route('dossier.prescription.modifier', [$id, $p->id]) }}" class="filtres" style="align-items:flex-end">
                                    @csrf @method('PUT')
                                    <label class="meta">Date<br><input type="date" name="date_prescription" value="{{ substr((string) $p->date_prescription, 0, 10) }}" required></label>
                                    <label class="meta" style="flex:1;min-width:220px">Libellé<br><input name="libelle" value="{{ $p->libelle }}" required maxlength="255" style="width:100%"></label>
                                    <label class="meta">Dose<br><input name="dose" value="{{ $p->dose }}" maxlength="60" size="9"></label>
                                    <label class="meta">Voie<br><select name="voie"><option value=""></option>@foreach(Onco::VOIES as $v)<option @selected($p->voie === $v)>{{ $v }}</option>@endforeach</select></label>
                                    <label class="meta">Fréquence<br><input name="frequence" value="{{ $p->frequence }}" maxlength="80" size="10"></label>
                                    <label class="meta">Pendant<br><input name="duree" value="{{ $p->duree }}" maxlength="60" size="10"></label>
                                    <button class="btn" type="submit">@include('partials.icone', ['n' => 'enregistrer']) Enregistrer</button>
                                </form>
                            </td></tr>
                        @endif
                    @endforeach
                @empty
                    <tr><td colspan="5" class="doux">Aucune prescription.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</section>

{{-- ===== Historique : chronologie complète du patient ===== --}}
<section class="panneau {{ $onglet === 'historique' ? 'actif' : '' }}" id="p-historique">
    <div class="carte" style="max-width:1200px">
        <div class="filtres" style="margin-bottom:10px">
            <b>Historique du patient</b>
            @foreach(collect($historique)->pluck('type')->unique()->sort() as $t)
                <label class="meta"><input type="checkbox" class="filtre-histo" value="{{ $t }}" @checked(! in_array($t, ['Document', 'Saisie'], true))> <span class="puce" style="background:{{ $couleurType[$t] ?? '#64748b' }}">{{ $t }}</span></label>
            @endforeach
        </div>
        @foreach($parJour as $jour => $evenements)
            <div class="jour">
                <h3>{{ $jourLong($jour) }}</h3>
                @foreach($evenements as $e)
                    <div class="evt" data-type="{{ $e['type'] }}">
                        <span class="puce" style="background:{{ $couleurType[$e['type']] ?? '#64748b' }}">{{ $e['type'] }}</span>
                        <div><b>{{ $e['titre'] }}</b> @isset($e['heure'])<span class="meta">{{ $d($e['heure'], 'H:i') }}</span>@endisset <span class="meta">{{ $e['detail'] }}</span></div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</section>
@endsection

@push('scripts')
<script>
(function () {
    // Onglets sans rechargement.
    document.querySelectorAll('.onglets a').forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('.onglets a').forEach(x => x.classList.toggle('actif', x === a));
        document.querySelectorAll('.panneau').forEach(p => p.classList.toggle('actif', p.id === 'p-' + a.dataset.onglet));
        history.replaceState(null, '', '?onglet=' + a.dataset.onglet);
        if (a.dataset.onglet === 'documents' && !courant) ouvrirPremier();
    }));

    // Saisie : champs propres au type choisi.
    const libelles = { diagnostic: 'Diagnostic (texte)', antecedent: 'Antécédent', consultation: 'Compte rendu de consultation',
        histoire: 'Histoire de la maladie', evolution: 'Évolution', rcp: 'Décision de la RCP' };
    function majSaisie() {
        const type = document.querySelector('input[name=type]:checked').value;
        document.querySelector('.si-diagnostic').style.display = type === 'diagnostic' ? 'block' : 'none';
        document.querySelector('.si-antecedent').style.display = type === 'antecedent' ? 'block' : 'none';
        document.getElementById('libelle-texte').textContent = libelles[type];
        document.querySelector('textarea[name=texte]').style.minHeight = type === 'antecedent' ? '80px' : (type === 'diagnostic' ? '140px' : '260px');
    }
    document.querySelectorAll('input[name=type]').forEach(r => r.addEventListener('change', majSaisie));
    majSaisie();
    const tTexte = document.getElementById('tumeur-texte'), tCode = document.getElementById('tumeur-code');
    const codes = new Map([...document.querySelectorAll('#liste-tumeurs option')].map(o => [o.value, o.dataset.code]));
    tTexte.addEventListener('input', () => { tCode.value = tTexte.value.trim() === '' ? '' : (codes.get(tTexte.value) ?? tCode.value); });

    // Modification d'une note : le texte devient éditable sur place.
    // Prescription de protocole : lignes du protocole choisi et doses selon la surface corporelle.
    const echap = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
    document.querySelectorAll('.form-protocole').forEach(pp => {
        const poids = pp.querySelector('.pp-poids'), taille = pp.querySelector('.pp-taille'), corps = pp.querySelector('.pp-lignes tbody');
        // Surface corporelle : formule de Boyd, à 3 décimales comme Clinisys (100 kg / 180 cm = 2,265 m²).
        const sc = () => {
            if (!(poids.value > 0 && taille.value > 0)) return null;
            const g = poids.value * 1000;
            return Math.floor(0.0003207 * Math.pow(taille.value, 0.3) * Math.pow(g, 0.7285 - 0.0188 * Math.log10(g)) * 1000) / 1000; // tronquée comme Clinisys
        };
        const recalculer = () => {
            const s = sc();
            pp.querySelector('.pp-sc').textContent = s ? s.toFixed(3).replace('.', ',') + ' m²' : '—';
            corps.querySelectorAll('tr[data-m2]').forEach(tr => {
                // Dose = dose initiale × SC, à 2 décimales (ex. 85 × 2,265 = 192.53).
                const calc = s ? (Math.round(tr.dataset.m2 * s * 100) / 100).toFixed(2) : '';
                const dose = tr.querySelector('.pp-dose'), cache = tr.querySelector('.pp-calc');
                if (dose.value === cache.value) dose.value = calc; // ne pas écraser une dose ajustée à la main
                cache.value = calc;
            });
        };
        poids.addEventListener('input', recalculer); taille.addEventListener('input', recalculer);
        pp.querySelector('.pp-protocole').addEventListener('change', async e => {
            corps.innerHTML = '<tr><td colspan="6" class="meta">Chargement…</td></tr>';
            if (!e.target.value) { corps.innerHTML = ''; return; }
            const lignes = await (await fetch('{{ url('/protocoles') }}/' + e.target.value + '/lignes', {headers: {Accept: 'application/json'}})).json();
            corps.innerHTML = lignes.length ? '' : '<tr><td colspan="6" class="meta">Ce protocole n\'a aucune ligne.</td></tr>';
            lignes.forEach((l, i) => {
                const n = 'lignes[' + i + ']';
                const tr = document.createElement('tr');
                if (l.dose_m2) tr.dataset.m2 = l.dose_m2;
                if (l.chimio) tr.className = 'ligne-chimio'; // produit de chimio, coloré comme dans Clinisys
                tr.innerHTML = '<td>' + echap(l.jour) + '<input type="hidden" name="' + n + '[jour]" value="' + echap(l.jour) + '"></td>'
                    + '<td>' + (l.chimio ? '<b>' + echap(l.medicament) + '</b>' : echap(l.medicament))
                    + '<input type="hidden" name="' + n + '[medicament]" value="' + echap(l.medicament) + '">'
                    + '<input type="hidden" name="' + n + '[dose_m2]" value="' + echap(l.dose_m2) + '">'
                    + '<input type="hidden" name="' + n + '[horaire]" value="' + echap(l.horaire) + '"></td>'
                    + '<td>' + echap(l.dose_initiale) + (l.dose_m2 ? '<br><span class="meta">par m²</span>' : '')
                    + (l.auc ? '<br><span class="meta">AUC : dose à calculer (Calvert)</span>' : '')
                    + (l.sans_dose ? '<br><span class="meta">dose à 0 dans le paramétrage Clinisys</span>' : '') + '</td>'
                    + '<td><input class="pp-dose" name="' + n + '[dose]" value="' + echap(l.dose) + '" style="width:100px"'
                    + (l.auc || l.sans_dose ? ' placeholder="à saisir"' : '') + '>'
                    + '<input type="hidden" class="pp-calc" name="' + n + '[dose_calculee]" value="' + echap(l.dose) + '"></td>'
                    + '<td><input name="' + n + '[dilution]" value="' + echap(l.dilution) + '" maxlength="150" style="width:100%;min-width:140px"></td>'
                    + '<td><input name="' + n + '[duree]" value="' + echap(l.duree) + '" maxlength="40" style="width:70px"></td>';
                corps.appendChild(tr);
            });
            recalculer();
        });
    });

    // Onglet Prescriptions : le type choisit le formulaire (protocole = écran complet avec SC et doses).
    const rxType = document.getElementById('rx-type');
    if (rxType) {
        const majRx = () => {
            const t = rxType.value;
            document.getElementById('rx-type-champ').value = t;
            // style.display et non hidden : la classe .filtres impose display:flex.
            document.getElementById('rx-simple').style.display = t === 'protocole' ? 'none' : '';
            document.getElementById('rx-protocole').style.display = t === 'protocole' ? '' : 'none';
            document.getElementById('rx-aide').textContent = t === 'medicament'
                ? '(tapez 2 lettres : liste proposée, ou saisie libre)' : (t === 'examen' ? '(examen)' : '');
            fermerSuggestions();
        };

        // Médicaments : suggestions cherchées sur le serveur pendant la frappe ; tout texte libre reste accepté.
        const champRx = document.getElementById('rx-libelle'), boite = document.getElementById('rx-suggestions');
        let minuterieRx = null, actif = -1;
        function fermerSuggestions() { boite.hidden = true; boite.innerHTML = ''; actif = -1; }
        function choisir(el) { champRx.value = el.dataset.valeur; fermerSuggestions(); champRx.focus(); }
        function surligner(i) {
            const items = [...boite.querySelectorAll('.sugg')];
            if (!items.length) return;
            actif = (i + items.length) % items.length;
            items.forEach((el, k) => el.classList.toggle('actif', k === actif));
            items[actif].scrollIntoView({ block: 'nearest' });
        }
        champRx.addEventListener('input', () => {
            clearTimeout(minuterieRx);
            const q = champRx.value.trim();
            if (rxType.value !== 'medicament' || q.length < 2) { fermerSuggestions(); return; }
            minuterieRx = setTimeout(() => {
                fetch('{{ route('medicaments.recherche') }}?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
                    .then(r => r.ok ? r.json() : []).then(liste => {
                        if (champRx.value.trim() !== q) return;
                        boite.innerHTML = liste.length
                            ? liste.map(m => '<div class="sugg" data-valeur="' + echap(m.libelle) + '"><b>' + echap(m.libelle) + '</b>'
                                + (m.detail ? '<br><span class="meta">' + echap(m.detail) + '</span>' : '') + '</div>').join('')
                            : '<div class="meta" style="padding:8px 10px">Aucun médicament trouvé : votre saisie sera enregistrée telle quelle.</div>';
                        boite.hidden = false; actif = -1;
                    }).catch(fermerSuggestions);
            }, 250);
        });
        champRx.addEventListener('keydown', e => {
            if (boite.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); surligner(actif + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); surligner(actif - 1); }
            else if (e.key === 'Enter' && actif >= 0) { e.preventDefault(); choisir(boite.querySelectorAll('.sugg')[actif]); }
            else if (e.key === 'Escape') { fermerSuggestions(); }
        });
        boite.addEventListener('mousedown', e => { const el = e.target.closest('.sugg'); if (el) { e.preventDefault(); choisir(el); } });
        champRx.addEventListener('blur', () => setTimeout(fermerSuggestions, 150));
        rxType.addEventListener('change', majRx); majRx();
    }

    document.querySelectorAll('.modifier-note').forEach(b => b.addEventListener('click', () => {
        const note = b.closest('.note');
        note.querySelector('.texte').hidden = true; note.querySelector('.edition-note').hidden = false;
        note.querySelector('.edition-note textarea').focus();
    }));
    document.querySelectorAll('.annuler-note').forEach(b => b.addEventListener('click', () => {
        const note = b.closest('.note');
        note.querySelector('.texte').hidden = false; note.querySelector('.edition-note').hidden = true;
    }));

    // Chronologie : filtres par type.
    function filtrer() {
        const actifs = [...document.querySelectorAll('.filtre-histo:checked')].map(x => x.value);
        document.querySelectorAll('.evt').forEach(e => e.hidden = !actifs.includes(e.dataset.type));
        document.querySelectorAll('.jour').forEach(j => j.hidden = !j.querySelector('.evt:not([hidden])'));
    }
    document.querySelectorAll('.filtre-histo').forEach(c => c.addEventListener('change', filtrer));
    filtrer();

    // Documents : liste par catégorie, grand aperçu.
    const vue = document.getElementById('vis-contenu'), titre = document.getElementById('vis-titre');
    const btnImp = document.getElementById('vis-imprimer'), btnTel = document.getElementById('vis-telecharger'), classer = document.getElementById('vis-classer');
    const images = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];
    const tous = [...document.querySelectorAll('.doc')];
    var courant = null;
    function afficher(el) {
        document.querySelectorAll('.doc.actif').forEach(x => x.classList.remove('actif'));
        el.classList.add('actif'); el.closest('details').open = true; el.scrollIntoView({ block: 'nearest' });
        courant = { el, url: el.dataset.url, ext: el.dataset.ext };
        titre.textContent = el.dataset.titre;
        btnTel.href = courant.url; btnTel.setAttribute('aria-disabled', 'false');
        classer.action = el.dataset.classer; classer.hidden = false; classer.querySelector('select').value = el.dataset.code || '';
        vue.innerHTML = '';
        if (images.includes(courant.ext)) { const i = document.createElement('img'); i.src = courant.url; vue.appendChild(i); }
        else if (courant.ext === 'pdf') { const f = document.createElement('iframe'); f.src = courant.url; vue.appendChild(f); }
        else { vue.innerHTML = '<p style="padding:24px">Aperçu impossible pour ce format : utilisez « Télécharger ».</p>'; }
        btnImp.disabled = !(images.includes(courant.ext) || courant.ext === 'pdf');
    }
    function voisin(sens) { if (!courant) return; const i = tous.indexOf(courant.el) + sens; if (tous[i]) afficher(tous[i]); }
    function ouvrirPremier() { if (tous[0]) afficher(tous[0]); }
    tous.forEach(el => { el.addEventListener('click', () => afficher(el)); el.addEventListener('keydown', e => { if (e.key === 'Enter') afficher(el); }); });
    document.getElementById('vis-prec').addEventListener('click', () => voisin(-1));
    document.getElementById('vis-suiv').addEventListener('click', () => voisin(1));
    document.addEventListener('keydown', e => {
        if (!document.getElementById('p-documents').classList.contains('actif') || ['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); voisin(1); }
        if (e.key === 'ArrowUp') { e.preventDefault(); voisin(-1); }
        if (e.key === 'Escape') document.getElementById('ged').classList.remove('plein');
    });
    document.getElementById('vis-plein').addEventListener('click', () => document.getElementById('ged').classList.toggle('plein'));
    btnTel.addEventListener('click', e => { if (btnTel.getAttribute('aria-disabled') === 'true') e.preventDefault(); });
    btnImp.addEventListener('click', () => {
        if (!courant) return;
        if (courant.ext === 'pdf') { vue.querySelector('iframe').contentWindow.print(); return; }
        const f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0'; document.body.appendChild(f);
        const doc = f.contentDocument; doc.open();
        doc.write('<!doctype html><title>Impression</title><style>@page{margin:10mm}body{margin:0}img{max-width:100%;max-height:100vh;display:block;margin:auto}</style><img>');
        doc.close();
        const img = doc.querySelector('img');
        img.onload = () => { f.contentWindow.focus(); f.contentWindow.print(); setTimeout(() => f.remove(), 1000); };
        img.src = courant.url;
    });
    // Le premier document ne s'ouvre que si l'onglet Documents est affiché (chaque ouverture est tracée).
    if (document.getElementById('p-documents').classList.contains('actif')) ouvrirPremier();
})();
</script>
@endpush
