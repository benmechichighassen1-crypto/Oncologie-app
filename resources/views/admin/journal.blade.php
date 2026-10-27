@extends('layouts.app')
@section('titre', 'Journal')
@section('contenu')
<div class="carte">
    <h1>Journal de traçabilité</h1>
    <form class="filtres" method="get">
        <input name="user" value="{{ $filtres['user'] ?? '' }}" placeholder="Login">
        <input name="identifiant" value="{{ $filtres['identifiant'] ?? '' }}" placeholder="Identifiant patient">
        <select name="action">
            <option value="">Toutes les actions</option>
            @foreach(['connexion', 'connexion_refusee', 'deconnexion', 'ouverture_dossier', 'ouverture_document', 'classement_document', 'acces_refuse', 'modification_droits', 'modification_note', 'modification_note_refusee', 'masquage_note', 'masquage_antecedent', 'saisie_consultation', 'saisie_prescription', 'modification_prescription', 'suppression_prescription', 'prescription_protocole', 'arret_protocole', 'impression_protocole', 'impression_ordonnance', 'synchro_referentiels', 'page'] as $a)
                <option value="{{ $a }}" @selected(($filtres['action'] ?? '') === $a)>{{ str_replace('_', ' ', $a) }}</option>
            @endforeach
        </select>
        <label>Du <input type="date" name="du" value="{{ $filtres['du'] ?? '' }}"></label>
        <label>Au <input type="date" name="au" value="{{ $filtres['au'] ?? '' }}"></label>
        <button class="btn" type="submit">Filtrer</button>
    </form>
</div>
<div class="carte">
    <table>
        <thead><tr><th>Date</th><th>Login</th><th>Action</th><th>Patient</th><th>Dossier / document</th><th>Poste</th><th>Détails</th></tr></thead>
        <tbody>
        @foreach($lignes as $l)
            <tr>
                <td>{{ \Carbon\Carbon::parse($l->cree_le)->format('d/m/Y H:i:s') }}</td>
                <td>{{ $l->user_name }}</td>
                <td>{{ str_replace('_', ' ', $l->action) }}</td>
                <td>@if($l->identifiant_patient)<a href="{{ route('dossier', $l->identifiant_patient) }}">{{ $l->identifiant_patient }}</a>@endif</td>
                <td>{{ $l->num_doss }} {{ $l->document }}</td>
                <td>{{ $l->ip }}</td>
                <td style="max-width:520px;word-break:break-word;font-size:13px">
                    @php
                        $i = $l->infos;
                        // Valeurs imbriquées (droits avant / après…) mises à plat en texte lisible.
                        $lisible = function ($v) use (&$lisible) {
                            if ($v === null || $v === '') { return '—'; }
                            if (is_bool($v)) { return $v ? 'oui' : 'non'; }
                            if (is_scalar($v)) { return (string) $v; }
                            $v = (array) $v;
                            return array_is_list($v)
                                ? ($v === [] ? 'aucun' : implode(', ', array_map($lisible, $v)))
                                : collect($v)->map(fn ($x, $k) => $k.' : '.$lisible($x))->implode(' · ');
                        };
                    @endphp
                    @if(isset($i['avant']) && is_array($i['avant']))
                        @if(isset($i['compte']))<div class="doux">Compte {{ $i['compte'] }}</div>@endif
                        <div style="background:#fef2f2;border-left:3px solid #dc2626;padding:4px 8px;margin:3px 0"><b>Avant :</b> {{ $lisible($i['avant']) }}</div>
                        <div style="background:#f0fdf4;border-left:3px solid #16a34a;padding:4px 8px"><b>Après :</b> {{ $lisible($i['apres'] ?? null) }}</div>
                    @elseif(isset($i['avant']))
                        <div class="doux">{{ $i['type'] ?? 'Note' }} du {{ isset($i['date']) ? \Carbon\Carbon::parse($i['date'])->format('d/m/Y') : '' }}</div>
                        <div style="background:#fef2f2;border-left:3px solid #dc2626;padding:4px 8px;margin:3px 0;white-space:pre-wrap"><b>Avant :</b> {{ $i['avant'] }}</div>
                        <div style="background:#f0fdf4;border-left:3px solid #16a34a;padding:4px 8px;white-space:pre-wrap"><b>Après :</b> {{ $i['apres'] }}</div>
                    @elseif(isset($i['texte']))
                        <div class="doux">{{ $i['type'] ?? 'Note' }}{{ isset($i['date']) ? ' du '.\Carbon\Carbon::parse($i['date'])->format('d/m/Y') : '' }} — contenu retiré :</div>
                        <div style="background:#fef2f2;border-left:3px solid #dc2626;padding:4px 8px;white-space:pre-wrap">{{ $i['texte'] }}</div>
                    @elseif(isset($i['antecedent']))
                        Antécédent retiré : <b>{{ $i['antecedent'] }}</b> {{ $i['details'] ?? '' }}
                    @elseif($i)
                        <span class="doux">{{ $lisible($i) }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $lignes->links() }}
</div>
@endsection
