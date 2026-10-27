<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titre', 'Oncologie') · Consultation</title>
    <style>
        :root { --bleu:#1e3a8a; --fond:#f1f5f9; --bord:#e2e8f0; --texte:#0f172a; --doux:#64748b; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--fond); color:var(--texte); font-size:14px; }
        header { background:var(--bleu); color:#fff; display:flex; align-items:center; gap:24px; padding:10px 20px; flex-wrap:wrap; }
        header a { color:#dbeafe; text-decoration:none; font-weight:500; }
        header a.actif, header a:hover { color:#fff; }
        header .marque { font-weight:700; font-size:16px; color:#fff; }
        header .droite { margin-left:auto; display:flex; align-items:center; gap:12px; }
        header button { background:transparent; border:1px solid #93c5fd; color:#fff; border-radius:6px; padding:4px 10px; cursor:pointer; }
        main { padding:20px; max-width:1500px; margin:0 auto; }
        .carte { background:#fff; border:1px solid var(--bord); border-radius:10px; padding:16px; margin-bottom:16px; }
        h1 { font-size:20px; margin:0 0 12px; } h2 { font-size:16px; margin:0 0 10px; }
        table { width:100%; border-collapse:collapse; } th, td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--bord); vertical-align:top; }
        th { color:var(--doux); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
        input, select, textarea { font:inherit; padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; }
        input:focus, select:focus, textarea:focus { outline:2px solid #93c5fd; border-color:#3b82f6; }
        /* Boutons : principal (bleu), secondaire (contour), discret (texte) ; icône SVG optionnelle. */
        .btn { font:inherit; font-weight:600; display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:8px;
               border:1px solid var(--bleu); background:var(--bleu); color:#fff; cursor:pointer; text-decoration:none; line-height:1.1;
               transition:background .15s, box-shadow .15s; white-space:nowrap; }
        .btn:hover { background:#1e40af; box-shadow:0 2px 6px rgba(30,58,138,.25); }
        .btn:disabled, .btn[aria-disabled=true] { opacity:.45; cursor:not-allowed; box-shadow:none; }
        .btn.sec { background:#fff; color:var(--bleu); border-color:#bfdbfe; } .btn.sec:hover { background:#eff6ff; }
        .btn.vert { background:#15803d; border-color:#15803d; } .btn.vert:hover { background:#166534; }
        .btn.discret { background:none; border-color:transparent; color:var(--doux); padding:6px 8px; } .btn.discret:hover { color:var(--texte); background:#f1f5f9; box-shadow:none; }
        .btn svg { width:16px; height:16px; flex:none; }
        .filtres { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; color:#fff; }
        .doux { color:var(--doux); } .alerte { background:#fef9c3; border:1px solid #fde047; padding:8px 12px; border-radius:6px; margin-bottom:12px; }
        .erreur { color:#b91c1c; }
        .notif { position:fixed; top:70px; right:20px; z-index:100; max-width:380px; padding:12px 16px; border-radius:10px; cursor:pointer;
                 background:#15803d; color:#fff; font-weight:600; box-shadow:0 6px 20px rgba(0,0,0,.18); animation:notif-entree .25s ease-out; transition:opacity .4s, transform .4s; }
        .notif.sortie { opacity:0; transform:translateY(-8px); }
        @keyframes notif-entree { from { opacity:0; transform:translateY(-8px); } }
        .grille { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:8px 20px; }
        .grille div span { display:block; font-size:12px; color:var(--doux); }
    </style>
    @stack('tete')
</head>
<body>
@auth
<header>
    <span class="marque">Oncologie</span>
    <a href="{{ route('planning') }}" class="{{ request()->routeIs('planning') ? 'actif' : '' }}">Calendrier</a>
    <a href="{{ route('patients') }}" class="{{ request()->routeIs('patients', 'dossier') ? 'actif' : '' }}">Patients</a>
    @if(app(\App\Services\Droits::class)->voitStatistiques(auth()->user()))
        <a href="{{ route('statistiques') }}" class="{{ request()->routeIs('statistiques') ? 'actif' : '' }}">Statistiques</a>
    @endif
    @if(auth()->user()->admin)
        <a href="{{ route('admin.acces') }}" class="{{ request()->routeIs('admin.acces*') ? 'actif' : '' }}">Accès</a>
        <a href="{{ route('admin.journal') }}" class="{{ request()->routeIs('admin.journal') ? 'actif' : '' }}">Journal</a>
    @endif
    <div class="droite">
        <span>{{ auth()->user()->nomAffiche() }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Déconnexion</button></form>
    </div>
</header>
@endauth
<main>
    @if(session('statut'))
        {{-- Notification : disparaît seule après 5 secondes, ou au clic. --}}
        <div class="notif" id="notif" role="status" onclick="this.remove()">{{ session('statut') }}</div>
        <script>setTimeout(() => { const n = document.getElementById('notif'); if (n) { n.classList.add('sortie'); setTimeout(() => n.remove(), 400); } }, 5000);</script>
    @endif
    @yield('contenu')
</main>
@stack('scripts')
</body>
</html>
