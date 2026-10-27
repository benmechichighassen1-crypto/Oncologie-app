<?php

/*
 * Paramètres de l'application de consultation oncologie.
 *
 * Toutes les bases Clinisys sont lues en lecture seule via la connexion
 * « clinisys » (config/database.php). Les noms de bases sont paramétrables
 * pour passer d'un serveur de test à la production, ou par un serveur lié
 * (CLINISYS_PREFIXE=[SRV_ERP]).
 */

return [

    'connexion' => env('CLINISYS_CONNEXION', 'clinisys'),

    // Nom imprimé en tête des prescriptions.
    'etablissement' => env('ONCO_ETABLISSEMENT', 'CLINIQUE INTERNATIONALE IBN SINA TANGER'),

    // Préfixe optionnel de serveur lié, par exemple « [SRV_ERP] ». Vide = même serveur.
    'prefixe' => env('CLINISYS_PREFIXE', ''),

    'bases' => [
        'erp' => env('CLINISYS_BASE_ERP', 'Gclinique_Maroc'),
        'rdv' => env('CLINISYS_BASE_RDV', 'rendez_vous_Maroc'),
        'ged' => env('CLINISYS_BASE_GED', 'GED'),
        'ged_doc' => env('CLINISYS_BASE_GED_DOC', 'GED_Doc'),
    ],

    // Lecture des comptes rendus de GED_Doc : désactivée tant que le serveur lié n'y a pas accès.
    'ged_doc_actif' => (bool) env('CLINISYS_GED_DOC_ACTIF', false),

    // Collation utilisée par les vues Clinisys pour joindre les bases entre elles.
    'collation' => 'SQL_Latin1_General_CP1256_CI_AS',

    // Natures de centre (rendez_vous_Maroc.dbo.centre.nature).
    'natures' => [
        'consultation' => 30,
        'chimio' => 20,      // centres 20 CHIMIOTHERAPIE et 23 CHIMIOTHERAPIE ANNEXE
        'radio' => 8,
    ],

    // Couleurs du calendrier par type de séance.
    'couleurs' => [
        'CONSULTATION' => '#2563eb',
        'CHIMIO' => '#ea580c',
        'RADIO' => '#7c3aed',
    ],

    // etatRDV des vues centre.RdvNorm* : libellé et couleur de bordure.
    'etats' => [
        4 => ['libelle' => 'Prévu', 'couleur' => '#94a3b8'],
        1 => ['libelle' => 'En salle', 'couleur' => '#0ea5e9'],
        5 => ['libelle' => 'En traitement', 'couleur' => '#f59e0b'],
        2 => ['libelle' => 'Terminé', 'couleur' => '#16a34a'],
        6 => ['libelle' => 'Absent', 'couleur' => '#dc2626'],
    ],

    'auth' => [
        // Table des comptes Clinisys (base ERP).
        'table' => env('CLINISYS_TABLE_COMPTES', 'dbo.AccessControl'),

        // Schéma de hachage : auto | pbkdf2-sha512 | pbkdf2-sha256 | pbkdf2-sha1.
        // « auto » essaie chaque schéma : à figer une fois identifié avec
        // « php artisan onco:tester-mot-de-passe ».
        'hash' => env('CLINISYS_HASH', 'auto'),

        // Comptes administrateurs de l'application (UserName Clinisys, séparés par des virgules).
        'admins' => array_filter(array_map('trim', explode(',', (string) env('ONCO_ADMINS', '')))),
    ],

    // Classement des pièces jointes de GED : libellé normalisé => code Type_Doc.
    // La catégorie vient ensuite de Type_Doc.id_categorie -> CategorieType_Doc.
    'synonymes_pj' => [
        'CIN' => '07', 'RECTO' => '07', 'VERSO' => '07', 'PASSEPORT' => '07',
        'PERMIS DE CONDUIRE' => '07', 'CARTE SEJOUR' => '07',
        'CARTE MUTUELLE' => '08', 'CARTE MUTELLE' => '08', 'CNSS' => '08', 'CNOPS' => '08',
        'ACCORD PEC' => '29', 'DEMANDE DE PEC' => '31', 'RETOUR PEC' => '30',
        'ACCORD COMPLEMENTAIRE' => '38', 'DOSSIER D EXPEDITION' => '28',
        'ACCUSE DE DEPOT' => '37', 'ANNULLE ET REMPLACE' => '39', 'RECOUVREMENT' => '36',
        'PEC' => '06', 'ORD' => '12', 'ORDONNANCE' => '12',
        'TDM' => '26', 'TDM TAP' => '26', 'IRM' => '26', 'ECHO' => '26', 'RX' => '26',
        'MAMMOGRAPHIE' => '26', 'SCANNER' => '26',
        'ANALYSE' => '05', 'BILAN' => '05',
        'PATH' => '25', 'ANAPTH' => '25', 'ANAPATH' => '25',
        'LETTRE MEDICALE' => '24', 'LETTRE MEDICAL' => '24', 'LETTRE' => '24', 'LT' => '24',
        'CR' => '04', 'CR2' => '04', 'RAPPORT MEDICAL' => '32',
        'FACTURE' => '01', 'ECG' => '23',
    ],

    // Mots-clés recherchés dans le libellé quand aucune correspondance exacte n'existe.
    'mots_cles_pj' => [
        'CHIMIO' => '33', 'RADIOTH' => '34', 'HORMONO' => '35', 'ANAPATH' => '25',
        'PEC' => '29', 'MUTUEL' => '08', 'CIN' => '07', 'ORDONN' => '12',
        'ANALYSE' => '05', 'RADIO' => '11', 'LETTRE' => '24', 'FACTURE' => '01',
    ],
];
