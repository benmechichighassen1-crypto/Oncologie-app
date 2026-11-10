<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fiche protocole · {{ $patient->NomCli }} {{ $patient->Prenom }}</title>
    @php
        $d = fn ($v, $f = 'd/m/Y') => $v ? \Carbon\Carbon::parse($v)->format($f) : '';
        $nb = fn ($v) => \App\Repositories\TraitementsChimio::texte((float) $v);
        $dose = function ($p) { $n = \App\Repositories\TraitementsChimio::nombre($p->dose); return $n !== null ? number_format($n, $n == floor($n) ? 0 : 2, ',', "\u{00A0}").' mg' : $p->dose; };
        $base = fn ($p) => $p->type_dose === 'DT' || ! $p->dose_ref ? '' : ($p->type_dose === 'AUC' ? 'AUC '.$p->dose_ref : $p->dose_ref.' '.$p->type_dose);
        $bmi = (float) $cure->taille > 0 ? (float) $cure->poids / (((float) $cure->taille / 100) ** 2) : null;
    @endphp
    <style>
        @page { size: A4 landscape; margin: 9mm 10mm; }
        * { box-sizing:border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color:#111; font-size:11px; margin:12px auto; max-width:1120px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .actions { text-align:center; margin-bottom:10px; } .actions button { font:inherit; font-size:14px; padding:8px 18px; background:#1f1d5a; color:#fff; border:0; border-radius:6px; cursor:pointer; }
        .tete { display:flex; align-items:center; gap:14px; margin-bottom:8px; }
        .tete img { height:52px; } .tete .clinique { font-size:11px; color:#1f1d5a; font-weight:bold; line-height:1.3; }
        .tete h1 { flex:1; text-align:center; margin:0; font-size:19px; color:#1f1d5a; letter-spacing:.3px; }
        .tete .date { font-size:11px; text-align:right; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; }
        .infos { margin-bottom:7px; } .infos th, .infos td { border:1px solid #9ca3af; padding:3px 6px; text-align:center; font-size:11.5px; overflow-wrap:anywhere; }
        .infos th { background:#eef0f8; color:#1f1d5a; font-size:10.5px; }
        .infos td.nom { font-weight:bold; font-size:13px; }
        .allergie { color:#b91c1c; font-weight:bold; }
        .soins th { background:#e5e7eb; font-size:9.5px; padding:4px 3px; border:1px solid #9ca3af; }
        .soins td { border:1px solid #d1d5db; padding:3px 4px; height:21px; vertical-align:middle; overflow-wrap:anywhere; }
        .soins thead { display:table-header-group; }
        .soins tbody { break-inside:avoid; }
        .soins tbody tr:first-child td { border-top:1.6px solid #1f1d5a; }
        .jour { font-weight:bold; font-size:12px; text-align:center; }
        .dose { background:#fefce8; font-weight:bold; font-size:12.5px; text-align:right; white-space:nowrap; }
        .ctr { text-align:center; } .chimio { font-weight:bold; }
        .case { display:inline-block; width:12px; height:12px; border:1.3px solid #111; text-align:center; line-height:11px; font-size:11px; font-weight:bold; }
        .constantes { font-size:9.5px; color:#374151; }
        .bas { display:flex; gap:12px; margin-top:8px; break-inside:avoid; }
        .bas div { flex:1; border:1px solid #9ca3af; padding:4px 6px; min-height:52px; font-size:10.5px; } .bas b { color:#1f1d5a; }
        .pied { margin-top:5px; font-size:9.5px; color:#4b5563; display:flex; justify-content:space-between; }
        @media print { .actions { display:none; } body { margin:0; max-width:none; } }
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Imprimer</button></div>
    <div class="tete">
        <img src="{{ asset('images/logo-ibnsina.png') }}" alt="">
        <div class="clinique">Clinique Internationale Ibn Sina<br>Tanger · Oncologie</div>
        <h1>FICHE PROTOCOLE CHIMIOTHÉRAPIE</h1>
        <div class="date">Édité le {{ now()->format('d/m/Y') }}</div>
    </div>
    <table class="infos">
        <colgroup><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:8%"><col style="width:8%"><col style="width:8%"><col style="width:8%"><col style="width:19%"><col style="width:22%"></colgroup>
        <tr><th colspan="3">Identité</th><th>Âge</th><th colspan="3">Mutuelle</th><th>N° dossier</th><th>Médecin</th></tr>
        <tr><td colspan="3" class="nom">{{ $patient->NomCli }} {{ $patient->Prenom }}</td><td>{{ $patient->DatNai ? \Carbon\Carbon::parse($patient->DatNai)->age.' ans' : '' }}</td>
            <td colspan="3">{{ $derniere->mutuelle ?? ($derniere->organisme ?? '') }}</td><td>{{ $traitement->num_doss }}</td><td>{{ $traitement->prescrit_nom }}</td></tr>
        <tr><th>Poids</th><th>Taille</th><th>SC (Boyd)</th><th>IMC</th><th>Cure N°</th><th>Intercure</th><th>Rechute</th><th>Protocole</th><th>Localisation · stade</th></tr>
        <tr><td>{{ $nb($cure->poids) }} kg</td><td>{{ $nb($cure->taille) }} cm</td><td><b>{{ number_format((float) $cure->surface_corporelle, 3, ',', '') }} m²</b></td><td>{{ $bmi ? number_format($bmi, 1, ',', '') : '' }}</td>
            <td><b>{{ $cure->numero }}/{{ $traitement->nb_cures }}</b></td><td>{{ $traitement->intercure }} J</td><td>{{ $traitement->rechute ? 'Oui' : 'Non' }}</td>
            <td><b>{{ $traitement->nom_protocole }}</b></td><td>{{ $traitement->localisation }}{{ $traitement->stade ? ' · '.$traitement->stade : '' }}</td></tr>
        <tr><th>Allergies</th><td colspan="5" style="text-align:left" class="{{ $traitement->allergies ? 'allergie' : '' }}">{{ $traitement->allergies ?: 'Non renseignées' }}</td>
            <th>Clairance</th><td colspan="2">{{ $cure->clairance !== null ? $nb($cure->clairance).' ml/min (Calvert)' : '' }}</td></tr>
    </table>
    <table class="soins">
        <colgroup><col style="width:7%"><col style="width:3.5%"><col style="width:21%"><col style="width:8%"><col style="width:9%"><col style="width:6%"><col style="width:9%"><col style="width:6%"><col style="width:4.5%"><col style="width:4.5%"><col style="width:9%"><col style="width:3.5%"><col style="width:9%"></colgroup>
        <thead><tr><th>Date</th><th>J</th><th>Produit</th><th>Base</th><th>Dose à administrer</th><th>Voie</th><th>Solvant · volume</th><th>Durée</th><th>Début</th><th>Fin</th><th>Observation</th><th>OK</th><th>Date et praticien OK chimio</th></tr></thead>
        @foreach($jours as $j)
            @php $liste = $produits[$j->id] ?? collect(); @endphp
            <tbody>
            @foreach($liste as $i => $p)
                <tr>
                    <td class="jour">@if($i === 0){{ $d($j->date_prevue) }}@endif</td>
                    <td class="jour">@if($i === 0)J{{ $j->jour }}@endif</td>
                    <td class="{{ $p->chimio ? 'chimio' : '' }}">{{ $p->medicament }}</td>
                    <td class="ctr">{{ $base($p) }}</td>
                    <td class="dose" @if(\App\Repositories\TraitementsChimio::nombre($p->dose) === null) style="white-space:normal;font-size:10.5px" @endif>{{ $dose($p) }}</td>
                    <td class="ctr">{{ $p->voie }}</td>
                    <td class="ctr">{{ trim($p->solvant.' '.$p->volume) }}</td>
                    <td class="ctr">{{ $p->duree }}</td>
                    <td></td><td></td>
                    <td class="constantes">@if($i === 0){{ $j->observation }}@endif</td>
                    <td class="ctr">@if($i === 0)<span class="case">{{ $j->ok_chimio ? '✓' : '' }}</span>@endif</td>
                    <td class="constantes ctr">@if($i === 0 && $j->ok_chimio){{ $d($j->ok_le) }}<br>{{ $j->ok_nom }}@endif</td>
                </tr>
            @endforeach
            <tr><td colspan="13" class="constantes" style="height:17px">J{{ $j->jour }} · Tension : {{ $j->tension ?: '________' }} &nbsp; T°C : {{ $j->temperature ?: '_____' }} &nbsp; Douleur : {{ $j->douleur ?: '_____' }} &nbsp; Infirmier(e) : ______________________</td></tr>
            </tbody>
        @endforeach
    </table>
    <div class="bas">
        <div><b>Observations et recommandations</b><br>{{ $traitement->observation }}</div>
        <div style="flex:.6"><b>Visa médecin</b></div>
        <div style="flex:.6"><b>Visa infirmier(e)</b></div>
    </div>
    <div class="pied"><span>Surface corporelle : formule de Boyd, comme Clinisys · dose de chaque produit validée par le médecin</span><span>Début du traitement {{ $d($traitement->date_debut) }} · fin prévue {{ $d($traitement->date_fin_prevue) }}</span></div>
</body>
</html>
