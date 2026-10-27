<?php

namespace App\Http\Controllers;

use App\Services\Droits;
use App\Support\Clinisys as C;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Liste des patients du médecin connecté (ou des médecins autorisés). */
class PatientsController extends Controller
{
    public function index(Request $request, Droits $droits): View
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'mois' => ['nullable', 'integer', 'in:1,3,6,12,24'],
        ]);
        $mois = (int) ($data['mois'] ?? 6);
        $q = trim((string) ($data['q'] ?? ''));
        $autorises = $droits->medecinsAutorises($request->user());

        $patients = [];
        if ($autorises !== []) {
            $sql = 'SELECT TOP 500 c.Identifiant, MAX(c.NomCli) AS NomCli, MAX(c.Prenom) AS Prenom, MAX(c.DatNai) AS DatNai,
                           MAX(c.DatArr) AS derniere_admission, COUNT(*) AS nb_admissions, MAX(m.NomMed) AS medecin
                    FROM '.C::t('erp', 'dbo.Client').' c
                    JOIN '.C::t('erp', 'dbo.chambre').' ch ON ch.NumCha = c.NumCha
                    JOIN '.C::t('erp', 'dbo.Reception').' r ON r.NumSer = ch.NumSer
                    LEFT JOIN '.C::t('erp', 'dbo.Medecin')." m ON m.CodMed = c.MedTrait
                    WHERE r.NumRecep IN ('9', '20') AND c.NumDoss NOT LIKE '%T%'
                      AND c.DatArr >= DATEADD(month, -?, CAST(GETDATE() AS date))";
            $bindings = [$mois];
            if ($autorises !== null) {
                $sql .= ' AND c.MedTrait IN ('.C::in($autorises).')';
                array_push($bindings, ...$autorises);
            }
            if ($q !== '') {
                $sql .= ' AND (c.NomCli + \' \' + c.Prenom LIKE ? OR c.Prenom + \' \' + c.NomCli LIKE ? OR c.Identifiant = ? OR c.NumDoss = ?)';
                array_push($bindings, "%$q%", "%$q%", $q, $q);
            }
            $sql .= ' GROUP BY c.Identifiant ORDER BY MAX(c.DatArr) DESC';
            $patients = C::db()->select($sql, $bindings);
        }

        return view('patients.index', compact('patients', 'q', 'mois'));
    }
}
