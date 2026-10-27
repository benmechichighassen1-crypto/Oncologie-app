<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Ordonnance · {{ $patient->NomCli }} {{ $patient->Prenom }}</title>
    <style>
        /* A5 sur papier à en-tête : 3 cm laissés libres en haut pour l'en-tête imprimé de la clinique. */
        @page { size: A5 portrait; margin: 30mm 12mm 15mm 12mm; }
        body { font-family: "Segoe UI", Arial, sans-serif; color:#0f172a; font-size:12.5px; margin:0; background:#e5e7eb; }
        .feuille { width:148mm; min-height:210mm; margin:16px auto; padding:30mm 12mm 15mm; box-sizing:border-box; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.2); position:relative; }
        .feuille::before { content:'en-tête de la clinique (3 cm)'; position:absolute; top:0; left:0; right:0; height:30mm; display:flex; align-items:center; justify-content:center;
                           color:#94a3b8; font-size:11px; border-bottom:1px dashed #cbd5e1; }
        header { display:flex; justify-content:flex-end; margin-bottom:10px; }
        h1 { font-size:17px; margin:10px 0 8px; text-align:center; letter-spacing:.08em; }
        .patient { border:1px solid #cbd5e1; padding:6px 10px; border-radius:4px; margin-bottom:12px; line-height:1.5; }
        ol { padding-left:18px; } ol li { margin-bottom:10px; } .poso { color:#334155; } .com { color:#64748b; font-size:11.5px; }
        .signature { margin-top:36px; text-align:right; }
        .actions { text-align:center; margin:14px 0 0; } .actions button { font:inherit; font-size:14px; padding:8px 18px; background:#1e3a8a; color:#fff; border:0; border-radius:6px; cursor:pointer; }
        .actions span { display:block; color:#475569; font-size:12px; margin-top:6px; }
        @media print {
            .actions { display:none; } body { background:#fff; }
            .feuille { width:auto; min-height:0; margin:0; padding:0; box-shadow:none; } .feuille::before { display:none; }
        }
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Imprimer</button><span>Format A5, à imprimer sur le papier à en-tête de la clinique</span></div>
    <div class="feuille">
    <header>
        <div>Tanger, le {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</div>
    </header>
    <div class="patient">
        <b>{{ $patient->NomCli }} {{ $patient->Prenom }}</b>
        @if($patient->DatNai) · né(e) le {{ \Carbon\Carbon::parse($patient->DatNai)->format('d/m/Y') }} ({{ \Carbon\Carbon::parse($patient->DatNai)->age }} ans)@endif
        · {{ trim($patient->Identifiant) }}
        @if($derniere?->organisme)<br>Organisme : {{ $derniere->organisme }}@endif
        @if($fiche?->pathologie)<br>Pathologie : {{ $fiche->pathologie }}@endif
    </div>
    <h1>ORDONNANCE</h1>
    <ol>
        @foreach($lignes as $l)
            <li><b>{{ $l->libelle }}</b>
                <div class="poso">{{ collect([$l->dose, $l->voie, $l->frequence, $l->duree ? 'pendant '.$l->duree : null, $l->nb_cures ? $l->nb_cures.' cures' : null])->filter()->implode(' · ') }}</div>
                @if($l->commentaire)<div class="com">{{ $l->commentaire }}</div>@endif</li>
        @endforeach
    </ol>
    <div class="signature">{{ $medecin }}<br><br>Signature et cachet</div>
    </div>
</body>
</html>
