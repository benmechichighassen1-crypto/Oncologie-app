<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Prescription chimiothérapie · {{ $p['nom'] }}</title>
    @php
        $c = $p['cure'];
        $n = fn ($v, $dec = 2) => $v === null || $v === '' ? '' : number_format((float) $v, $dec, '.', '');
    @endphp
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; color:#000; font-size:13px; max-width:800px; margin:20px auto; }
        .entete { display:flex; justify-content:space-between; align-items:flex-end; border-bottom:1.5px solid #000; padding-bottom:6px; }
        .entete .droite { font-size:11px; font-weight:bold; text-align:left; }
        h1 { font-size:15px; font-style:italic; text-align:center; margin:16px 0 4px; }
        h2 { font-size:18px; font-weight:normal; text-align:center; text-decoration:underline; margin:0 0 16px; }
        .infos { display:grid; grid-template-columns: 1.3fr 1fr 1fr 1.3fr; gap:4px 12px; margin-bottom:22px; }
        .infos b { margin-right:4px; }
        * { box-sizing:border-box; }
        /* table-layout fixe : la dernière colonne ne dépasse plus de la page (bordure droite coupée). */
        /* En border-collapse, la moitié de la bordure extérieure dépasse du tableau : on laisse 2 px de marge à droite. */
        table { width:calc(100% - 2px); margin-left:1px; border-collapse:collapse; table-layout:fixed; } td { overflow-wrap:anywhere; } th, td { border:1px solid #000; padding:6px; vertical-align:top; text-align:left; }
        th { font-weight:normal; font-size:15px; text-align:center; } td { height:42px; }
        .obs { font-size:11px; margin-top:6px; } .obs b { font-weight:bold; }
        .seances { margin-top:14px; font-size:12px; }
        .pied { margin-top:18px; font-weight:bold; }
        .actions { text-align:center; margin-bottom:14px; } .actions button { font:inherit; padding:8px 18px; background:#1e3a8a; color:#fff; border:0; border-radius:6px; cursor:pointer; }
        @media print { .actions { display:none; } body { margin:0; max-width:none; width:auto; } }
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Imprimer</button></div>
    <div class="entete">
        <div>{{ config('oncologie.etablissement') }}</div>
        <div class="droite">Oncologie<br>Édité le {{ now()->format('d/m/Y H:i') }}</div>
    </div>
    <h1>PRESCRIPTION CHIMIOTHÉRAPIE</h1>
    <h2>{{ $p['nom'] }}</h2>
    <div class="infos">
        <div><b>Patient :</b>{{ $patient->NomCli }} {{ $patient->Prenom }}</div>
        <div><b>N° dossier :</b>{{ $c->numDoss }}</div>
        <div><b>Âge :</b>{{ $patient->DatNai ? \Carbon\Carbon::parse($patient->DatNai)->age : '' }}</div>
        <div><b>Prescrit le :</b>{{ $c->Date_cre ? \Carbon\Carbon::parse($c->Date_cre)->format('d/m/Y') : '' }}</div>
        <div><b>Poids :</b>{{ $n($c->poids) }}</div>
        <div><b>Taille :</b>{{ $n($c->taille) }}</div>
        <div><b>BMI :</b>{{ $n($c->BMI) }}</div>
        <div><b>Surface corporelle :</b>{{ $n($c->Surface_corporelle, 3) }}</div>
    </div>
    <table>
        <thead><tr><th style="width:9%">Jour</th><th style="width:37%">Médicament</th><th style="width:24%">Dose</th><th style="width:18%">Dilution</th><th style="width:12%">Durée</th></tr></thead>
        <tbody>
        @forelse($p['lignes'] as $l)
            <tr>
                <td>{{ $l['jour'] }}</td>
                <td>{{ $l['medicament'] }}@if($l['observation'])<div class="obs"><b>Observation :</b> {{ $l['observation'] }}</div>@endif</td>
                <td>{{ $l['dose'] }}</td>
                <td>{{ $l['dilution'] }}</td>
                <td>{{ $l['duree'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Aucune ligne de protocole enregistrée.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="seances">
        {{ collect([$c->nbreCure ? $c->nbreCure.' cure(s)' : null, $c->nbreSeance ? $c->nbreSeance.' séance(s)' : null,
            $c->intervalleInterCure ? 'intervalle '.$c->intervalleInterCure.' jours' : null,
            $c->Date_debut_traitement ? 'début '.\Carbon\Carbon::parse($c->Date_debut_traitement)->format('d/m/Y') : null])->filter()->implode(' · ') }}
        @if(trim((string) $c->Observation))<br>Observation : {{ $c->Observation }}@endif
    </div>
    <div class="pied">Saisie par : {{ $c->prescripteur ?? $c->User_Cre }}</div>
</body>
</html>
