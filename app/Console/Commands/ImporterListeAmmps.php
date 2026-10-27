<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Importe la « Liste marocaine des médicaments » publiée par l'AMMPS (ministère de la Santé).
 * Le site n'offre pas de fichier : les pages de la liste sont relues une à une, avec une
 * pause entre deux pages, puis tout est remplacé d'un coup dans onco_ref_medicaments.
 */
class ImporterListeAmmps extends Command
{
    private const URL = 'https://www.ammps.gov.ma/basesdedonnes/liste_marocaine_des_medicaments';

    protected $signature = 'onco:ammps {--pause=1 : secondes entre deux pages} {--pages= : nombre maximal de pages (essai)}';

    protected $description = 'Importe la liste marocaine des médicaments (AMMPS) dans oncologie_app';

    public function handle(): int
    {
        $medicaments = [];
        $page = 1;
        $derniere = null;
        do {
            $html = $this->lire($page);
            if ($html === null) {
                $this->error("Page $page illisible : import abandonné, la liste actuelle est conservée.");

                return self::FAILURE;
            }
            if ($derniere === null) {
                preg_match_all('/[?&]page=(\d+)/', $html, $m);
                $derniere = max(array_map('intval', $m[1] ?: [1]));
                if ($this->option('pages')) {
                    $derniere = min($derniere, (int) $this->option('pages'));
                }
                $this->info("$derniere pages à lire.");
            }
            $lus = $this->analyser($html);
            array_push($medicaments, ...$lus);
            $this->line("page $page/$derniere : ".count($lus).' médicaments');
            $page++;
            if ($page <= $derniere) {
                sleep((int) $this->option('pause'));
            }
        } while ($page <= $derniere);

        $medicaments = collect($medicaments)->unique('code')->values()->all();
        if (count($medicaments) < 100) {
            $this->error('Trop peu de médicaments lus ('.count($medicaments).') : la page du site a peut-être changé. Liste actuelle conservée.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($medicaments) {
            DB::table('onco_ref_medicaments')->where('source', 'ammps')->delete();
            foreach (array_chunk($medicaments, 100) as $paquet) {
                DB::table('onco_ref_medicaments')->insert($paquet);
            }
            DB::table('onco_ref_synchro')->updateOrInsert(['referentiel' => 'ammps'],
                ['lignes' => count($medicaments), 'par' => 'commande', 'synchro_le' => now()->format('Y-m-d H:i:s')]);
        });
        $this->info(count($medicaments).' médicaments importés.');

        return self::SUCCESS;
    }

    private function lire(int $page): ?string
    {
        for ($essai = 1; $essai <= 3; $essai++) {
            try {
                // Le réseau de la clinique inspecte le HTTPS : PHP doit faire confiance aux mêmes
                // autorités que Windows (fichier exporté par mise-a-jour-medicaments.bat).
                $ca = storage_path('app/certificats-windows.pem');
                $r = Http::timeout(60)->withOptions(is_file($ca) ? ['verify' => $ca] : [])
                    ->withHeaders(['User-Agent' => 'Oncologie-Clinique/1.0 (liste des medicaments)'])
                    ->get(self::URL, ['page' => $page]);
                if ($r->successful()) {
                    return $r->body();
                }
            } catch (\Throwable $e) {
                $this->warn("page $page, essai $essai : ".$e->getMessage());
            }
            sleep(5 * $essai);
        }

        return null;
    }

    /** Chaque médicament a une fenêtre de détail : nom, « dosage | forme », puis des paires libellé / valeur. */
    private function analyser(string $html): array
    {
        $texte = fn ($s) => ($s = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')))) === '' ? null : $s;
        $prix = function ($s) {
            $s = preg_replace('/[^0-9.,]/', '', (string) $s);
            if ($s === '') {
                return null;
            }
            // « 4.303.00 » : le dernier séparateur est la virgule décimale.
            $s = preg_replace('/[.,](?=\d{3}[.,])/', '', $s);

            return is_numeric($s = str_replace(',', '.', $s)) ? (float) $s : null;
        };

        $medicaments = [];
        preg_match_all('/id="medicamentModal(\d+)".*?(?=id="medicamentModal\d+"|<\/table>)/s', $html, $blocs, PREG_SET_ORDER);
        foreach ($blocs as [$bloc, $code]) {
            preg_match('/<h5[^>]*>(.*?)<\/h5>/s', $bloc, $nom);
            preg_match('/ammps-modal-subtitle">(.*?)<\/div>/s', $bloc, $sous);
            preg_match_all('/ammps-modal-label">(.*?)<\/span>\s*<span class="ammps-modal-value">(.*?)<\/span>\s*<\/div>/s', $bloc, $champs, PREG_SET_ORDER);
            $f = [];
            foreach ($champs as [, $libelle, $valeur]) {
                $f[$texte($libelle)] = $valeur;
            }
            [$dosage, $forme] = array_pad(array_map('trim', explode('|', (string) $texte($sous[1] ?? ''), 2)), 2, null);
            $libelle = $texte($nom[1] ?? '');
            if (! $libelle) {
                continue;
            }
            $medicaments[] = [
                'source' => 'ammps', 'code' => 'AMMPS-'.$code, 'libelle' => mb_substr($libelle, 0, 255),
                'dci' => mb_substr((string) $texte($f['Substance active'] ?? ''), 0, 150) ?: null,
                'dosage' => mb_substr((string) $dosage, 0, 100) ?: null, 'forme' => mb_substr((string) $forme, 0, 100) ?: null,
                'presentation' => mb_substr((string) $texte($f['Présentation'] ?? ''), 0, 255) ?: null,
                'classe' => mb_substr((string) $texte($f['Classe thérapeutique'] ?? ''), 0, 150) ?: null,
                'laboratoire' => mb_substr((string) $texte($f['EPI'] ?? ''), 0, 150) ?: null,
                'statut' => mb_substr((string) $texte($f['Statut commercialisation'] ?? ''), 0, 60) ?: null,
                'pp_gn' => mb_substr((string) $texte($f['PP / GN'] ?? ''), 0, 20) ?: null,
                'ppv' => $prix($texte($f['PPV'] ?? '')), 'ph' => $prix($texte($f['PH'] ?? '')),
            ];
        }

        return $medicaments;
    }
}
