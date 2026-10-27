@extends('layouts.app')
@section('titre', 'Accès')
@section('contenu')
<div class="carte">
    <h1>Paramétrage des accès</h1>
    <p class="doux">Par défaut, chaque médecin ne voit que ses patients. Ici, vous pouvez lui ouvrir l'activité d'autres médecins ou de tous, et désigner des administrateurs.</p>
    <form class="filtres" method="get">
        <input name="q" value="{{ $q }}" placeholder="Login, nom ou médecin" style="min-width:260px">
        <button class="btn" type="submit">Rechercher</button>
        <span class="doux">Sans recherche, seuls les comptes reliés à un médecin sont listés. Pour un agent (direction, secrétariat…), recherchez son login.</span>
    </form>
</div>
<div class="carte filtres">
    <b>Référentiels copiés de Clinisys</b>
    <span class="doux">Protocoles de chimiothérapie et liste des médicaments (DCI, pharmacie)
        @if($synchro) · dernière mise à jour le {{ \Carbon\Carbon::parse($synchro->synchro_le)->format('d/m/Y H:i') }}@endif</span>
    <form method="post" action="{{ route('admin.referentiels') }}" style="margin-left:auto">@csrf
        <button class="btn sec" type="submit">Mettre à jour depuis Clinisys</button></form>
    @if(session('statut'))<span class="doux" style="flex-basis:100%">{{ session('statut') }}</span>@endif
</div>
<div class="carte">
    <table>
        <thead><tr><th>Login</th><th>Nom</th><th>Médecin</th><th>Accès</th><th></th></tr></thead>
        <tbody>
        @foreach($comptes as $c)
            @php $a = $acces[$c->UserName] ?? null; @endphp
            <tr>
                <td>{{ $c->UserName }}</td>
                <td>{{ $c->Description }}</td>
                <td>{{ $c->NomMed }} <span class="doux">{{ $c->Code_Medecin_Infirmier }}</span></td>
                <td>{{ $portees[$a->portee ?? 'propre'] }}
                    @if(($a->portee ?? '') === 'liste') <span class="doux">(+{{ count($listes[$c->UserName] ?? []) }} médecins)</span>@endif
                    <br><span class="doux">Statistiques : {{ \App\Services\Droits::PORTEES_STATS[$a->stats_portee ?? 'identique'] ?? '' }}</span>
                    @if($a->admin ?? false)<span class="badge" style="background:#1e3a8a">admin</span>@endif</td>
                <td><a class="btn" href="{{ route('admin.acces.edit', $c->UserName) }}">Modifier</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
