{{-- Liste de médecins à cocher, avec recherche : $champ (nom du champ), $portee (radio associé), $choisis, $titre, $aide --}}
<div class="bloc-medecins" data-portee="{{ $portee }}" style="margin:12px 0">
    <b>{{ $titre }}</b> <span class="doux">({{ $aide }})</span>
    <div class="filtres" style="margin:6px 0">
        <input type="search" class="filtre-med" placeholder="Rechercher un médecin (nom ou code)…" style="flex:1;min-width:240px" autocomplete="off">
        <label><input type="checkbox" class="seulement-choisis"> Seulement les choisis</label>
        <span class="doux nb-choisis"></span>
    </div>
    <div class="liste-med" style="max-height:300px;overflow:auto;border:1px solid var(--bord);border-radius:6px;padding:4px 8px">
        @foreach($medecins as $m)
            <label class="ligne-med" style="display:block;padding:3px 0" data-texte="{{ mb_strtolower($m->NomMed.' '.$m->CodMed) }}">
                <input type="checkbox" name="{{ $champ }}[]" value="{{ $m->CodMed }}" @checked(in_array($m->CodMed, $choisis, true))>
                {{ $m->NomMed }} <span class="doux">{{ $m->CodMed }}</span>
            </label>
        @endforeach
    </div>
</div>
