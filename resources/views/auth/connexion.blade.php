<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion · Oncologie · Clinique Internationale Ibn Sina</title>
    {{-- Même présentation que l'application de pilotage d'activité. Police locale si internet est coupé. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --marine:#1f1d5a; --sarcelle:#1f9e8f; --sarcelle-fonce:#18867a; --or:#e6b94d; --texte:#2b2d42; --doux:#6b7280; --bord:#dfe3ea; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
               font-family:'Lexend', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color:var(--texte);
               background:linear-gradient(135deg, #e3f3f1 0%, #eef0f8 55%, #e7e9f6 100%); }
        .carte { width:100%; max-width:1440px; min-height:min(800px, calc(100vh - 48px)); display:grid; grid-template-columns:52% 48%;
                 background:#fff; border-radius:26px; overflow:hidden; box-shadow:0 30px 70px rgba(31, 29, 90, .18); }
        .visuel { position:relative; background:#2d2b63 url('{{ asset('images/reception-ibnsina.jpg') }}') center 35% / cover no-repeat; }
        .visuel::after { content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(31,29,90,.05) 35%, rgba(31,29,90,.55) 62%, rgba(31,29,90,.92) 100%); }
        .visuel .texte { position:absolute; left:44px; right:44px; bottom:22px; z-index:1; color:#fff; }
        .surtitre { color:var(--or); font-size:15px; font-weight:500; letter-spacing:.16em; text-transform:uppercase; }
        .visuel h2 { font-size:38px; font-weight:600; margin:10px 0 12px; }
        .visuel p { font-size:18px; font-weight:300; line-height:1.5; margin:0 0 26px; max-width:560px; }
        .trait { width:70px; height:4px; border-radius:2px; background:var(--sarcelle); }
        .formulaire { padding:46px 50px 34px; display:flex; flex-direction:column; }
        .marque { display:flex; align-items:center; gap:16px; }
        .marque img { width:68px; height:68px; border-radius:50%; }
        .marque b { display:block; color:var(--marine); font-size:19px; font-weight:600; }
        .marque span { color:var(--doux); font-size:15px; }
        h1 { color:var(--marine); font-size:32px; font-weight:600; margin:36px 0 10px; }
        .intro { color:var(--doux); font-size:17px; margin:0 0 22px; }
        label { display:block; font-size:17px; margin:18px 0 10px; }
        .champ { position:relative; }
        input { width:100%; font:inherit; font-size:17px; color:var(--texte); padding:13px 16px; border:1px solid var(--bord); border-radius:12px; outline:none; }
        input::placeholder { color:#9ca3af; font-weight:300; }
        input:focus { border-color:var(--sarcelle); box-shadow:0 0 0 3px rgba(31,158,143,.15); }
        .oeil { position:absolute; right:10px; top:50%; transform:translateY(-50%); border:0; background:none; cursor:pointer; color:#374151; padding:6px; }
        .bouton { width:100%; margin-top:22px; padding:16px; font:inherit; font-size:18px; color:#fff; background:var(--sarcelle); border:0; border-radius:12px; cursor:pointer; }
        .bouton:hover { background:var(--sarcelle-fonce); }
        .erreur { color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:10px 14px; margin:16px 0 0; font-size:15px; }
        .avertissement { display:flex; gap:14px; margin-top:34px; padding:16px 20px; background:#e8f6f4; border:1px solid #b9e3dd; border-radius:12px; font-size:14.5px; line-height:1.55; color:#374151; }
        .avertissement b { color:var(--marine); }
        .avertissement svg { flex:none; margin-top:3px; }
        .pied { color:var(--doux); font-size:13.5px; margin-top:18px; line-height:1.6; }
        @media (max-width: 900px) {
            body { padding:12px; }
            .carte { grid-template-columns:1fr; min-height:0; }
            .visuel { min-height:230px; }
            .visuel .texte { left:24px; right:24px; }
            .visuel h2 { font-size:28px; } .visuel p { font-size:15px; margin-bottom:16px; }
            .formulaire { padding:28px 22px; }
        }
    </style>
</head>
<body>
<main class="carte">
    <section class="visuel" aria-hidden="true">
        <div class="texte">
            <div class="surtitre">Clinique Internationale Ibn Sina · Tanger</div>
            <h2>Oncologie</h2>
            <p>Dossier patient, protocoles de chimiothérapie, planning des séances et statistiques, à partir des données de Clinisys.</p>
            <div class="trait"></div>
        </div>
    </section>

    <section class="formulaire">
        <div class="marque">
            <img src="{{ asset('images/logo-ibnsina.png') }}" alt="Logo Clinique Internationale Ibn Sina">
            <div><b>Clinique Internationale Ibn Sina</b><span>Tanger · Oncologie</span></div>
        </div>

        <h1>Connexion</h1>
        <p class="intro">Accès réservé au personnel autorisé de la clinique</p>

        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <label for="username">Identifiant</label>
            <input id="username" name="username" value="{{ old('username') }}" placeholder="votre identifiant Clinisys" required autofocus autocomplete="username">

            <label for="password">Mot de passe</label>
            <div class="champ">
                <input id="password" type="password" name="password" placeholder="votre mot de passe" required autocomplete="current-password" style="padding-right:48px">
                <button type="button" class="oeil" title="Afficher le mot de passe" aria-label="Afficher le mot de passe"
                        onclick="const c = document.getElementById('password'); c.type = c.type === 'password' ? 'text' : 'password';">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>

            @error('username')<p class="erreur">{{ $message }}</p>@enderror
            <button class="bouton" type="submit">Se connecter</button>
        </form>

        <div class="avertissement">
            <svg width="16" height="18" viewBox="0 0 24 24" fill="var(--marine)"><path d="M6 10V7a6 6 0 1 1 12 0v3h1a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1h1zm2 0h8V7a4 4 0 1 0-8 0v3zm4 4a2 2 0 0 0-1 3.73V20h2v-2.27A2 2 0 0 0 12 14z"/></svg>
            <div><b>Accès réservé.</b> Cette application contient des données de santé, couvertes par le secret médical et professionnel. Toute connexion ou tentative de connexion sans autorisation est interdite et expose son auteur à des poursuites (loi n° 07-03 relative aux atteintes aux systèmes de traitement automatisé des données ; loi n° 09-08 relative à la protection des données à caractère personnel). Chaque connexion est enregistrée.</div>
        </div>
        <p class="pied">Identifiant et mot de passe Clinisys · @php $duree = (int) config('session.lifetime'); @endphp
            session fermée après {{ $duree % 60 === 0 ? ($duree / 60).' heure'.($duree > 60 ? 's' : '') : $duree.' minutes' }} sans activité ou à la fermeture du navigateur.</p>
    </section>
</main>
</body>
</html>
