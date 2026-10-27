@php
    // Icônes simples (trait 2 px), utilisées dans les boutons : @include('partials.icone', ['n' => 'telecharger'])
    $chemins = [
        'telecharger' => '<path d="M12 3v12m0 0l-5-5m5 5l5-5M4 19h16"/>',
        'imprimer' => '<path d="M7 9V3h10v6M7 17H5a2 2 0 01-2-2v-4a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2h-2M7 14h10v7H7z"/>',
        'agrandir' => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
        'precedent' => '<path d="M15 6l-6 6 6 6"/>',
        'suivant' => '<path d="M9 6l6 6-6 6"/>',
        'enregistrer' => '<path d="M5 12l5 5L20 7"/>',
        'ordonnance' => '<path d="M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'fermer' => '<path d="M6 6l12 12M18 6L6 18"/>',
    ];
@endphp
<svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $chemins[$n] ?? '' !!}</svg>
