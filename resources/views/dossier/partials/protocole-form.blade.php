{{-- Prescription d'un protocole paramétré (poids, taille, SC, doses calculées). $onglet = onglet où revenir après validation. --}}
<form method="post" action="{{ route('dossier.protocole.prescrire', $id) }}" class="form-protocole" style="padding:8px 12px 12px">
    @csrf
    <input type="hidden" name="onglet" value="{{ $onglet }}">
    <label class="meta" style="display:block">Protocole
        <select name="cod_prot" class="pp-protocole" required style="width:100%">
            <option value="">Choisir un protocole paramétré…</option>
            @foreach($protocolesActifs as $code => $nom)<option value="{{ $code }}">{{ $nom }}</option>@endforeach
        </select></label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <label class="meta">Poids (kg)<br><input name="poids" class="pp-poids" type="number" step="0.1" min="2" max="300" required style="width:90px"></label>
        <label class="meta">Taille (cm)<br><input name="taille" class="pp-taille" type="number" step="0.1" min="40" max="250" required style="width:90px"></label>
        <label class="meta">Surface corporelle<br><b class="pp-sc" style="display:inline-block;padding:7px 0;color:var(--texte)">—</b></label>
        <label class="meta">Nb de cures<br><input name="nb_cures" type="number" min="1" max="99" style="width:80px"></label>
        <label class="meta">Intervalle (j)<br><input name="intervalle" type="number" min="1" max="365" style="width:80px" placeholder="21"></label>
        <label class="meta">Début<br><input name="date_debut" type="date" value="{{ date('Y-m-d') }}"></label>
    </div>
    <table class="table-protocole pp-lignes" style="margin-top:8px">
        <thead><tr><th>Jour</th><th>Médicament</th><th>Dose initiale</th><th>Dose</th><th>Dilution</th><th>Durée</th></tr></thead>
        <tbody><tr><td colspan="6" class="meta">Choisissez un protocole pour afficher ses lignes.</td></tr></tbody>
    </table>
    <p class="meta" style="margin:4px 0"><span style="display:inline-block;width:10px;height:10px;background:#fecaca;border:1px solid #f87171;vertical-align:-1px"></span>
        Produits de chimiothérapie : dose = dose initiale × surface corporelle (formule de Boyd, comme Clinisys). Doses, dilutions et durées restent modifiables avant de valider.</p>
    <label class="meta" style="display:block">Observation<textarea name="observation" rows="2" maxlength="2000" style="width:100%"></textarea></label>
    <div style="text-align:right;margin-top:6px"><button class="btn" type="submit">@include('partials.icone', ['n' => 'enregistrer']) Valider la prescription</button></div>
</form>
