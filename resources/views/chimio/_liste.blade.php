{{-- Traitements saisis dans l'appli, directement visibles dans le dossier : $id, $traitementsChimio. --}}
@foreach($traitementsChimio as $t)
    @php
        // Cure à ouvrir : la première non finalisée, sinon la dernière.
        $encours = collect($t->cures)->first(fn ($c) => $c->statut !== 'FINALISÉE') ?? collect($t->cures)->last();
        $faites = collect($t->cures)->where('statut', 'FINALISÉE')->count();
        $f = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '';
    @endphp
    <div style="display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;padding:8px 12px;border-bottom:1px solid var(--bord)">
        <span class="puce" style="background:#15803d;font-size:11px">Appli</span>
        <a href="{{ route('chimio.index', [$id, 't' => $t->id]) }}" style="{{ $t->statut === 'arrete' ? 'text-decoration:line-through' : '' }}"><b>{{ $t->nom_protocole }}</b></a>
        <span class="meta">
            @if($t->statut === 'arrete')arrêté · {{ $faites }}/{{ $t->nb_cures }} cures finalisées
            @elseif($faites === (int) $t->nb_cures)terminé · {{ $t->nb_cures }} cures finalisées
            @else cure {{ $encours->numero }}/{{ $t->nb_cures }} · {{ mb_strtolower($encours->statut) }} · du {{ $f($encours->date_debut) }}@endif
            · {{ $t->prescrit_nom }}
        </span>
        @if($encours)
            <span style="margin-left:auto;display:flex;gap:6px">
                <a class="btn sec" style="padding:4px 10px" href="{{ route('chimio.cure', [$id, $encours->id]) }}">Ouvrir la cure {{ $encours->numero }}</a>
                <a class="btn sec" style="padding:4px 10px" href="{{ route('chimio.fiche', [$id, $encours->id]) }}" target="_blank" rel="noopener">@include('partials.icone', ['n' => 'imprimer']) Fiche</a>
            </span>
        @endif
    </div>
@endforeach
