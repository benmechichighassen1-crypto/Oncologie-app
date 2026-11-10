<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Scanner de dosimétrie · {{ $patient->NomCli }} {{ $patient->Prenom }}</title>
    @php
        $d = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '';
        $choisies = explode(',', $s->localisations);
        $oui = fn ($v) => $v ? 'Oui' : 'Non';
    @endphp
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing:border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color:#111; font-size:13px; margin:14px auto; max-width:760px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .actions { text-align:center; margin-bottom:10px; } .actions button { font:inherit; padding:8px 18px; background:#1f1d5a; color:#fff; border:0; border-radius:6px; cursor:pointer; }
        .tete { display:flex; align-items:center; gap:14px; margin-bottom:14px; } .tete img { height:56px; }
        .clinique { color:#1f1d5a; font-weight:bold; font-size:12px; line-height:1.3; }
        h1 { flex:1; text-align:center; margin:0; font-size:19px; color:#1f1d5a; }
        table { width:100%; border-collapse:collapse; margin-bottom:12px; table-layout:fixed; }
        th, td { border:1px solid #9ca3af; padding:6px 8px; text-align:left; overflow-wrap:anywhere; vertical-align:top; }
        th { background:#eef0f8; color:#1f1d5a; font-size:12px; width:26%; }
        .case { display:inline-block; width:13px; height:13px; border:1.3px solid #111; text-align:center; line-height:12px; font-size:11px; font-weight:bold; margin-right:5px; vertical-align:-2px; }
        .locs span { display:inline-block; width:32%; padding:3px 0; }
        .visa { border:1px solid #9ca3af; min-height:90px; padding:6px 8px; margin-top:14px; } .visa b { color:#1f1d5a; }
        @media print { .actions { display:none; } body { margin:0; max-width:none; } }
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Imprimer</button></div>
    <div class="tete">
        <img src="{{ asset('images/logo-ibnsina.png') }}" alt="">
        <div class="clinique">Clinique Internationale Ibn Sina<br>Tanger · Radiothérapie</div>
        <h1>SCANNER DE DOSIMÉTRIE</h1>
        <div style="font-size:11px">Prescrit le {{ $d($s->saisi_le) }}</div>
    </div>
    <table>
        <tr><th>Patient</th><td><b>{{ $patient->NomCli }} {{ $patient->Prenom }}</b></td><th>Âge</th><td>{{ $patient->DatNai ? \Carbon\Carbon::parse($patient->DatNai)->age.' ans' : '' }}</td></tr>
        <tr><th>N° dossier</th><td>{{ $s->num_doss }}</td><th>Mutuelle</th><td>{{ $derniere->mutuelle ?? ($derniere->organisme ?? '') }}</td></tr>
        <tr><th>Médecin</th><td>{{ $s->prescrit_nom }}</td><th>Date du scanner</th><td><b>{{ $d($s->date_scanner) }}</b></td></tr>
    </table>
    <table>
        <tr><th>Localisation</th><td class="locs">@foreach(\App\Http\Controllers\ScannerDosimetrieController::LOCALISATIONS as $l)<span><i class="case" style="font-style:normal">{{ in_array($l, $choisies, true) ? '✓' : '' }}</i>{{ $l }}</span>@endforeach</td></tr>
        <tr><th>Contraste IV</th><td>{{ $s->contraste_iv }}</td></tr>
        <tr><th>Contraste oral</th><td>{{ $s->contraste_oral }}</td></tr>
        <tr><th>Contraste vésical</th><td>{{ $s->contraste_vesical }}</td></tr>
        <tr><th>Contraste rectal</th><td>{{ $s->contraste_rectal }}</td></tr>
        <tr><th>Avertir le médecin</th><td>{{ $oui($s->avertir_medecin) }}</td></tr>
        <tr><th>Allergie au produit de contraste</th><td style="{{ $s->allergie_pc ? 'color:#b91c1c;font-weight:bold' : '' }}">{{ $oui($s->allergie_pc) }}</td></tr>
        <tr><th>Immobilisation</th><td>{{ $oui($s->immobilisation) }}{{ $s->type_immobilisation ? ' · type : '.$s->type_immobilisation : '' }}</td></tr>
        <tr><th>Limites d'acquisition</th><td>Du {{ $s->limite_du }} jusqu'à {{ $s->limite_jusqua }}</td></tr>
        <tr><th>Épaisseur des coupes</th><td>{{ $s->epaisseur_coupes }}</td></tr>
        <tr><th>Observations</th><td style="white-space:pre-wrap;min-height:60px">{{ $s->observations }}</td></tr>
    </table>
    <div class="visa"><b>Visa du médecin</b></div>
</body>
</html>
