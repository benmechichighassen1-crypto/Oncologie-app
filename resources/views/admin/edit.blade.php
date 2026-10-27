@extends('layouts.app')
@section('titre', 'Accès de '.$compte->UserName)
@php
    $statsPortee = ($acces->stats_portee ?? null) ?: 'identique';
@endphp
@section('contenu')
<div class="carte" style="max-width:760px">
    <h1>Accès de {{ $compte->UserName }}</h1>
    <p class="doux">{{ $compte->Description }} · médecin associé : {{ $compte->NomMed ?: 'aucun' }}</p>
    <form method="post" action="{{ route('admin.acces.update', $compte->UserName) }}">
        @csrf @method('PUT')

        <h2>Patients</h2>
        @foreach($portees as $code => $libelle)
            <p><label><input type="radio" name="portee" value="{{ $code }}" @checked(($acces->portee ?? 'propre') === $code)> {{ $libelle }}</label></p>
        @endforeach
        @include('admin.partials.medecins', ['champ' => 'medecins', 'portee' => 'portee', 'choisis' => $choisis,
            'titre' => 'Médecins visibles en plus', 'aide' => 'portée « Ses patients et des médecins choisis »'])

        <h2 style="margin-top:22px">Statistiques</h2>
        <p class="doux" style="margin-top:-4px">Réglé à part : par exemple, la direction peut voir toutes les statistiques sans ouvrir les dossiers, ou un médecin peut ne voir que sa propre activité.</p>
        @foreach($porteesStats as $code => $libelle)
            <p><label><input type="radio" name="stats_portee" value="{{ $code }}" @checked($statsPortee === $code)> {{ $libelle }}</label></p>
        @endforeach
        @include('admin.partials.medecins', ['champ' => 'stats_medecins', 'portee' => 'stats_portee', 'choisis' => $statsChoisis,
            'titre' => 'Médecins visibles dans les statistiques', 'aide' => 'portée « Son activité et des médecins choisis »'])

        <p><label><input type="hidden" name="admin" value="0"><input type="checkbox" name="admin" value="1" @checked($acces->admin ?? false)> Administrateur de l'application (paramétrage des accès et journal)</label></p>
        @if($errors->any())<p class="erreur">{{ $errors->first() }}</p>@endif
        <button class="btn" type="submit">Enregistrer</button>
        <a href="{{ route('admin.acces') }}">Annuler</a>
    </form>
</div>
@endsection
@push('scripts')
<script>
(function () {
    const sansAccents = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    document.querySelectorAll('.bloc-medecins').forEach(bloc => {
        const filtre = bloc.querySelector('.filtre-med');
        const seulement = bloc.querySelector('.seulement-choisis');
        const liste = bloc.querySelector('.liste-med');
        const lignes = [...bloc.querySelectorAll('.ligne-med')];
        const portee = bloc.dataset.portee;
        function appliquer() {
            const mots = sansAccents(filtre.value).split(/\s+/).filter(Boolean);
            let n = 0;
            lignes.forEach(l => {
                const coche = l.querySelector('input').checked;
                if (coche) n++;
                const texte = sansAccents(l.dataset.texte);
                l.style.display = (!seulement.checked || coche) && mots.every(m => texte.includes(m)) ? 'block' : 'none';
            });
            bloc.querySelector('.nb-choisis').textContent = n + ' choisi' + (n > 1 ? 's' : '');
        }
        filtre.addEventListener('input', appliquer);
        seulement.addEventListener('change', appliquer);
        // Cocher un médecin bascule automatiquement sur la portée « liste » (sauf « tous »).
        liste.addEventListener('change', () => {
            appliquer();
            const choix = document.querySelector('input[name=' + portee + '][value=liste]');
            if (choix && !document.querySelector('input[name=' + portee + '][value=tous]').checked) choix.checked = true;
        });
        appliquer();
    });
})();
</script>
@endpush
