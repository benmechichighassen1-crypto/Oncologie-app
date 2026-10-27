@extends('layouts.app')
@section('titre', 'Patients')
@section('contenu')
<div class="carte">
    <h1>Mes patients</h1>
    <form class="filtres" method="get">
        <input name="q" value="{{ $q }}" placeholder="Nom, identifiant ou n° de dossier" style="min-width:280px">
        <label>Admis depuis
            <select name="mois">
                @foreach([1 => '1 mois', 3 => '3 mois', 6 => '6 mois', 12 => '1 an', 24 => '2 ans'] as $v => $l)
                    <option value="{{ $v }}" @selected($mois === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn" type="submit">Rechercher</button>
    </form>
</div>
<div class="carte">
    <table>
        <thead><tr><th>Patient</th><th>Identifiant</th><th>Naissance</th><th>Médecin traitant</th><th>Dernière admission</th><th>Admissions</th></tr></thead>
        <tbody>
        @forelse($patients as $p)
            <tr>
                <td><a href="{{ route('dossier', trim($p->Identifiant)) }}">{{ $p->NomCli }} {{ $p->Prenom }}</a></td>
                <td>{{ $p->Identifiant }}</td>
                <td>{{ $p->DatNai ? \Carbon\Carbon::parse($p->DatNai)->format('d/m/Y') : '' }}</td>
                <td>{{ $p->medecin }}</td>
                <td>{{ \Carbon\Carbon::parse($p->derniere_admission)->format('d/m/Y') }}</td>
                <td>{{ $p->nb_admissions }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="doux">Aucun patient sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if(count($patients) === 500)<p class="doux">Seuls les 500 patients les plus récents sont affichés : affinez la recherche.</p>@endif
</div>
@endsection
