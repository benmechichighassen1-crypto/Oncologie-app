# Oncologie : application de suivi des patients en oncologie

Application web interne de la **Clinique Internationale Ibn Sina (Tanger)** pour le service d'oncologie. Elle se branche sur le logiciel hospitalier **Clinisys** et donne aux oncologues, en un seul écran :

- le calendrier de leurs patients (consultations, chimiothérapie, radiothérapie) ;
- le dossier patient complet (identité, admissions, séances, documents de la GED) ;
- une saisie médicale propre à l'oncologie (consultations, diagnostic, antécédents, décisions RCP, prescriptions) ;
- la prescription et le suivi des traitements de chimiothérapie, de la création du protocole jusqu'à la signature de l'administration par l'infirmier ;
- la prescription du scanner de dosimétrie ;
- des statistiques d'activité par centre et par médecin ;
- une traçabilité complète de chaque action.

**Règle fondamentale : l'application n'écrit jamais dans Clinisys.** Les bases Clinisys sont lues avec un compte SQL en lecture seule. Tout ce que l'application enregistre (droits, journal, saisies médicales, traitements de chimio, copies des référentiels) va dans une base dédiée, `oncologie_app`.

Ce document décrit tout ce qui a été développé, pour pouvoir reprendre le projet.

---

## Sommaire

1. [Architecture](#1-architecture)
2. [Fonctionnalités](#2-fonctionnalités)
3. [Connexion et droits](#3-connexion-et-droits)
4. [Traçabilité](#4-traçabilité)
5. [Données Clinisys utilisées](#5-données-clinisys-utilisées)
6. [Base de l'application `oncologie_app`](#6-base-de-lapplication-oncologie_app)
7. [Installation et configuration](#7-installation-et-configuration)
8. [Mise à jour, commandes et tâches planifiées](#8-mise-à-jour-commandes-et-tâches-planifiées)
9. [Organisation du code](#9-organisation-du-code)
10. [Points de vigilance](#10-points-de-vigilance)
11. [État d'avancement et pistes restantes](#11-état-davancement-et-pistes-restantes)

---

## 1. Architecture

| Élément | Choix |
|---|---|
| Framework | Laravel 13 (PHP 8.3 ou plus) |
| Interface | Vues Blade rendues côté serveur, un peu de JavaScript natif. [FullCalendar 6](https://fullcalendar.io) pour le calendrier et [Chart.js 4](https://www.chartjs.org) pour les statistiques, chargés depuis un CDN. Pas de build front (Vite inutilisé). |
| Serveur web | IIS sous Windows, PHP en FastCGI (script `deploiement/installer-iis.ps1`) |
| Base Clinisys | SQL Server, lecture seule, connexion Laravel `clinisys` |
| Base de l'appli | SQL Server, base `oncologie_app`, connexion Laravel par défaut `sqlsrv` |
| Authentification | Comptes Clinisys de la table `AccessControl`, mot de passe vérifié par l'appli (PBKDF2) |
| Sessions et cache | Fichiers (`storage/framework`) en production |

```
 Postes de la clinique (navigateur)
              │  http://<serveur>:<port>
              ▼
   IIS + PHP (FastCGI) ── Laravel « oncologie-app »
              │                              │
   connexion « clinisys »            connexion « sqlsrv »
   (lecture seule)                   (lecture / écriture)
              ▼                              ▼
   Serveur SQL Clinisys              Base oncologie_app
   Gclinique_Maroc (ERP)             droits, journal, saisies,
   rendez_vous_Maroc (RDV)           traitements chimio,
   GED, GED_Doc                      copies des référentiels
```

### Serveur lié

En production, l'appli se connecte à un serveur SQL (le même qui héberge `oncologie_app`), et Clinisys est atteint par un **serveur lié** : `CLINISYS_PREFIXE=[erp_link]`, avec `CLINISYS_DATABASE=master`. Toutes les requêtes Clinisys utilisent donc des noms à quatre parties, `[erp_link].Gclinique_Maroc.dbo.Client`. La classe `App\Support\Clinisys` construit ces noms :

- `Clinisys::t('erp', 'dbo.Client')` donne le nom complet, avec le préfixe du serveur lié s'il existe. Le nom de base et l'objet sont validés par expression régulière avant d'être placés dans le SQL. Les valeurs saisies par l'utilisateur passent toujours par des paramètres liés.
- `Clinisys::distant($sql)` exécute une requête d'agrégation **sur le serveur Clinisys lui-même** via `OPENQUERY`, pour que seul le résultat regroupé transite par le lien (utilisé par les statistiques).
- `Clinisys::collate()` renvoie `COLLATE SQL_Latin1_General_CP1256_CI_AS` : les bases Clinisys n'ont pas toutes la même collation, et les jointures sur `NumDoss` entre l'ERP et la base RDV en ont besoin.

Sans serveur lié (connexion directe au serveur Clinisys), il suffit de laisser `CLINISYS_PREFIXE` vide.

### Performances

- Les lectures Clinisys d'un dossier (patient, admissions, séances) sont gardées **2 minutes** en cache par patient. Les listes de pièces jointes aussi. Les saisies de l'appli sont toujours relues en direct.
- Les libellés des chaises et appareils, et le référentiel des tumeurs, sont gardés une heure.
- Les protocoles, médicaments et examens sont **copiés** dans `oncologie_app` (voir [section 8](#8-mise-à-jour-commandes-et-tâches-planifiées)), parce que les lire à travers le serveur lié à chaque frappe serait trop lent.
- Le middleware `MesurerTemps` ajoute un en-tête `Server-Timing` (onglet Réseau du navigateur) et écrit dans `storage/logs/lenteurs.log` toute requête de plus de 2 secondes, avec les 5 requêtes SQL les plus longues.
- Le délai maximal d'une requête Clinisys est de 30 secondes.

---

## 2. Fonctionnalités

### 2.1 Calendrier (page d'accueil, `/planning`)

- Vue semaine par médecin, construite avec FullCalendar. Le médecin connecté est sélectionné par défaut. Le filtre ne propose que les médecins que l'utilisateur a le droit de voir.
- Trois types de séances, une couleur chacun : **consultation** (bleu, centres de nature 30), **chimiothérapie** (orange, nature 20, centres 20 « Chimiothérapie » et 23 « Chimiothérapie annexe », avec un filtre par centre) et **radiothérapie** (violet, nature 8).
- La bordure indique le statut du RDV (`etatRDV`) : 4 Prévu (gris), 1 En salle (bleu clair), 5 En traitement (orange), 2 Terminé (vert), 6 Absent (rouge).
- Une cure de chimio dure plusieurs heures : elle est affichée sur 15 minutes pour garder le calendrier lisible. Les vraies heures (début et fin de traitement saisis dans Clinisys) sont dans le détail (icône « i »), avec la chaise ou l'appareil, l'organisme, l'âge du patient et le n° de séance.
- **Radiothérapie** : un RDV ajouté quand la journée est pleine est placé en fin de journée (22 h, 23 h…) alors que le patient passe plus tôt. Pour une séance terminée, l'appli la place à l'heure réelle de début de traitement (`centre.Rdv.debut_trait`) et garde l'heure prévue dans le détail.
- **Recherche d'un patient** (nom, n° de dossier ou identifiant, plusieurs mots possibles) : elle liste ses RDV de 3 mois avant à 6 mois après aujourd'hui, les prochains d'abord, et permet d'y aller directement.
- Un clic sur une séance ouvre le dossier du patient.
- Les dossiers administratifs « T » (sous-dossiers servant à saisir les prestations extras, par exemple `26T33669`) sont exclus partout : `NumDoss NOT LIKE '%T%'`.
- Période demandée limitée à 62 jours.

### 2.2 Liste des patients (`/patients`)

Patients du médecin connecté, ou des médecins qu'il a le droit de voir.

### 2.3 Dossier patient (`/patients/{identifiant}`)

L'accès est refusé (et tracé) si le patient n'est suivi par aucun médecin autorisé : l'appli regarde le médecin traitant de chaque admission et le médecin de chaque séance.

En-tête : identité, âge, CIN, téléphone, organisme, mutuelle, médecin traitant et médecin correspondant de la dernière admission.

Quatre onglets :

**Dossier**
- Synthèse oncologique, **versionnée** : pathologie (référentiel des tumeurs de Clinisys, libellé principal sans le détail du site), diagnostic, date du diagnostic, localisation, latéralité, histologie, grade, TNM et stade, marqueurs (RH, HER2, Ki67…), statut (bilan initial, traitement en cours, surveillance, rémission, récidive, palliatif, décédé), traitements reçus, ECOG, commentaire. Chaque enregistrement crée une nouvelle version, les anciennes restent consultables.
- **Formulaire unique de saisie** : selon le type choisi (consultation, histoire de la maladie, évolution, diagnostic, antécédent, décision RCP), l'entrée devient une note datée, une nouvelle version du diagnostic ou un antécédent. Des médicaments peuvent être prescrits dans le même formulaire.
- Antécédents : ceux de Clinisys (lecture seule) et ceux saisis dans l'appli, par famille (médical, chirurgical, familial, allergie, gynéco-obstétrical, habitudes, autre). Tumeurs déjà enregistrées dans Clinisys.
- **Historique des consultations** regroupé par jour, avec le type de dossier du jour (chimio, radio, consultation).
- Une note ne peut être modifiée que par son auteur ; l'ancien et le nouveau texte vont au journal. Une note ou un antécédent peut être « retiré » : il est masqué, jamais effacé, et son contenu est recopié dans le journal.

**Documents**
- Pièces jointes de la **GED** de Clinisys, rangées par rubrique oncologie : Imagerie, Bilans biologiques, Anapath, RCP, Autres documents.
- Le classement est automatique, dans cet ordre : correction manuelle du médecin > `type_pj` du document > libellé identique à un `Type_Doc` > synonyme (`synonymes_pj` dans `config/oncologie.php`) > mot-clé contenu dans le libellé (`mots_cles_pj`) > « Non classé ».
- Le médecin peut corriger la catégorie d'un document. La correction est enregistrée dans `onco_pj_categories` (jamais dans Clinisys) et tracée.
- Le contenu d'un fichier n'est lu qu'à son ouverture, et seulement après avoir vérifié qu'il appartient bien au patient. Chaque ouverture est tracée.
- Les documents sont retrouvés par n° de dossier, par identifiant (dossier « SANS ADMISSION » de l'arborescence), par `NumDos` ou par n° de devis (pièces de prise en charge sans dossier).
- Les comptes rendus radiologiques de **GED_Doc** sont prévus mais **désactivés** (`CLINISYS_GED_DOC_ACTIF=false`) tant que le serveur lié n'a pas les droits sur cette base. Une base GED illisible ne bloque pas le dossier : un message l'indique.

**Prescriptions**
- Nouvelle prescription (médicament, protocole, examen, autre) avec dose, voie, fréquence, durée et nombre de cures. Recherche de médicaments pendant la frappe : pharmacie de la clinique d'abord, puis liste marocaine AMMPS (commercialisés en tête), puis DCI. Catalogue des examens de radiologie de Clinisys pour les examens.
- Statut : prescrite, en cours, arrêtée. Modification et suppression par l'auteur seulement ; l'avant et l'après vont au journal, une suppression masque la ligne.
- **Ordonnance imprimable** : prescriptions non arrêtées d'une date, avec le nom de l'établissement en tête.
- **Scanners de dosimétrie** (voir 2.5), **traitements de chimiothérapie** (voir 2.4), protocoles prescrits dans l'appli (ancien écran, voir plus bas) et protocoles prescrits dans Clinisys (lecture seule, imprimables avec la même présentation que Clinisys).

**Historique**
Chronologie de tout le dossier : saisies des médecins, admissions, séances, programmes de traitement (n/N séances faites), prescriptions et documents.

### 2.4 Traitements de chimiothérapie (`/patients/{identifiant}/chimio`)

Module refait sur le modèle du logiciel de cabinet de l'oncologue. Structure : **traitement > cures > jours (J1, J8, J15…) > produits**. Tout est enregistré dans `oncologie_app`, rien dans Clinisys.

**Création d'un traitement**
1. Choix du protocole parmi les protocoles actifs de Clinisys (`ProtocoleCure`), avec un filtre par nom (« folfiri », « ac60 », « carbo »…).
2. Saisie du poids et de la taille (préremplis avec les dernières mesures connues). La **surface corporelle** est calculée avec la formule de **Boyd**, celle de Clinisys, tronquée à 3 décimales comme Clinisys (vérifié : 100 kg et 180 cm donnent 2,265 m²).
3. Date de début, nombre de cures (6 par défaut), intercure en jours (21 par défaut), jours du cycle (« 1 » ou « 1, 8, 15 »). La date de fin prévue est calculée. Le dernier jour du cycle doit tomber avant la cure suivante, sinon le formulaire refuse (risque de double dose ; « J1J21 » dans un nom de protocole veut dire une cure tous les 21 jours, jours « 1 », intercure 21).
4. Clairance de la créatinine (facultative, nécessaire pour les produits dosés en AUC comme le carboplatine).
5. Localisation, histologie, TNM, stade (préremplis depuis la synthèse du dossier), allergies, rechute, observations et recommandations.
6. Les lignes du protocole sont affichées et **modifiables avant l'enregistrement** : dose arrondie, produit retiré ou ajouté, voie, solvant, volume, durée. Les lignes vides de Clinisys (sans DCI ni dose) sont ignorées.

**Calcul des doses** (`App\Repositories\TraitementsChimio`)

| Type de dose | Calcul |
|---|---|
| mg/m² | dose de référence × surface corporelle |
| mg/kg | dose de référence × poids |
| AUC | formule de Calvert : AUC × (clairance + 25), clairance plafonnée à 125 ml/min. Pas de dose tant que la clairance manque. |
| DT (dose totale) | la dose de référence telle quelle |

- La dose retenue par le médecin peut différer du calcul : l'écart en % s'affiche à l'écran, il n'est jamais imprimé.
- Plafond par prise affiché en avertissement (aujourd'hui : vincristine 2 mg). Le médecin décide.
- La dilution de Clinisys (« mg dans 250cc G5% ») est décodée en voie, solvant et volume ; ce qui n'est pas reconnu reste à compléter.
- Toutes les cures, tous les jours et tous les produits sont créés d'un coup. Un jour non décrit par le protocole reprend les produits du J1 ; le médecin retire ensuite ce qui ne s'applique pas.

**Suivi d'une cure** (`/chimio/cures/{cure}`)
- Statut de la cure : NON ENTAMÉE, EN COURS ou FINALISÉE (selon les « OK chimio » donnés).
- Nouveau poids, nouvelle taille ou nouvelle clairance pour une cure : les doses calculées sont refaites pour les jours qui n'ont pas encore reçu le « OK chimio ». Une dose arrondie à la main est gardée et son écart s'affiche.
- Par jour : observation, tension, température, douleur.
- **« OK chimio »** du médecin pour le jour. Il ne peut pas être retiré si un produit a déjà été administré.
- **Signature de l'administration** par l'infirmier, produit par produit ou tout le jour d'un coup, seulement après le « OK chimio ».
- Ajout et retrait de produits sur un jour.
- **Fiche A4 paysage** imprimable de la cure, refusée tant qu'une dose manque (ou qu'une dose AUC n'a pas de clairance).
- Arrêt d'un traitement (il reste visible et tracé). Suppression par son auteur ou un administrateur, seulement si aucun produit n'a été administré : le traitement est masqué, jamais effacé.

**Ancien écran de prescription de protocole** : la table `onco_cures` / `onco_cure_lignes` et les routes `dossier.protocole.*` correspondent à la première version (prescription d'un protocole en un seul bloc, impression au format Clinisys). Elles restent en place pour l'historique ; le module ci-dessus les remplace.

### 2.5 Scanner de dosimétrie

Prescription inspirée de la « fiche 1 » du logiciel de cabinet : date, localisations (ORL, abdomen, sein, membres, thorax, pelvis), produits de contraste (IV, oral, vésical, rectal), avertir le médecin, allergie aux produits de contraste, immobilisation et son type, limites de l'acquisition (du… jusqu'à…), épaisseur des coupes, observations. Fiche imprimable. Retrait par son auteur seulement (masqué et tracé).

### 2.6 Statistiques (`/statistiques`)

- Activité des centres (consultation, chimio, radio) : séances où le patient est venu, ou nombre de patients distincts (mode « patients »).
- Filtres : période (3 ans au plus), pas (auto, jour, semaine, mois), types, médecin, centre de chimio (20, 23 ou les deux), nombre d'années pour la comparaison mensuelle.
- Indicateurs avec comparaison à la période précédente, courbe sur la période, courbes mensuelles année par année, tableau par médecin.
- Médecin retenu : celui de la consultation, ou le médecin traitant du dossier pour la chimio et la radio. Dossiers « T » exclus.
- Les regroupements sont faits sur le serveur Clinisys (`OPENQUERY`), donc toutes les valeurs injectées sont validées (dates AAAAMMJJ, codes alphanumériques), `OPENQUERY` n'acceptant pas de paramètres.
- L'accès aux statistiques se règle à part (voir 3.3).

### 2.7 Administration (réservée aux administrateurs)

- **`/acces`** : liste des comptes et paramétrage de chaque utilisateur (portée sur les patients, liste de médecins, administrateur oui/non, accès aux statistiques, droits chimio). Bouton « Mettre à jour depuis Clinisys » qui recopie protocoles, médicaments et examens.
- **`/tracabilite`** : consultation du journal, filtrable et paginée.

Les adresses ne contiennent volontairement pas le mot « admin ».

---

## 3. Connexion et droits

### 3.1 Connexion avec le compte Clinisys

- L'utilisateur se connecte avec son **login et son mot de passe Clinisys**. Il n'y a aucun compte propre à l'appli (la table Laravel `users` existe mais n'est pas utilisée).
- Le fournisseur `App\Auth\AccessControlUserProvider` lit `Gclinique_Maroc.dbo.AccessControl` : `UserName`, `Description`, `Code_Medecin_Infirmier` (lien vers `dbo.Medecin`), `Actif`, `expire`, `CompteExpire`, `dateExpirationCompte`. Un compte inactif ou expiré est refusé.
- Le mot de passe est vérifié par `App\Auth\VerificateurMotDePasse` contre `hasched_password`, `salt` et `hash_iterations` (PBKDF2, encodés en base64). Le schéma utilisé par Clinisys a été identifié : **PBKDF2-SHA1** (`CLINISYS_HASH=pbkdf2-sha1`). Le mot de passe est essayé en UTF-8 puis en UTF-16LE.
- Aucun mot de passe, hash ou sel n'est gardé en session ou dans l'objet utilisateur, et rien n'est jamais écrit dans `AccessControl` (pas de « se souvenir de moi », pas de re-hachage).
- La commande `php artisan onco:tester-mot-de-passe <login>` sert à vérifier le schéma avec un compte dont on connaît le mot de passe.

### 3.2 Administrateurs

Un utilisateur est administrateur s'il figure dans `ONCO_ADMINS` (logins séparés par des virgules, dans `.env`) ou s'il a la case « admin » dans `onco_acces`.

### 3.3 Portée sur les patients et les statistiques

Service `App\Services\Droits`, table `onco_acces` :

| Portée | Ce que l'utilisateur voit |
|---|---|
| `propre` (défaut d'un médecin) | ses patients uniquement |
| `liste` | ses patients et ceux des médecins choisis (`onco_acces_medecins`) |
| `tous` (défaut d'un administrateur) | tous les médecins |

Un compte sans médecin associé et sans paramétrage ne voit rien.

Statistiques (`stats_portee`) : `identique` (comme pour les patients, par défaut), `aucun`, `propre`, `liste` (médecins de `onco_acces_stats_medecins`) ou `tous`.

### 3.4 Droits chimiothérapie

| Droit | Qui l'a |
|---|---|
| Saisie d'un traitement (`chimio_saisie`) | tout compte lié à un médecin, ou un agent à qui on le donne dans `/acces` |
| « OK chimio » (`chimio_ok`) | tout compte lié à un médecin, ou sur droit donné |
| Signature de l'administration (`chimio_administration`) | **uniquement** sur droit donné dans `/acces` (infirmiers) |

---

## 4. Traçabilité

Service `App\Services\Journal`, table `onco_journal`. Chaque ligne garde : utilisateur, action, identifiant patient, n° de dossier, document, détails (JSON), adresse IP, navigateur, URL et date. **Le journal ne se modifie jamais.** Quand une saisie est modifiée ou retirée, l'ancien contenu est recopié dans le journal.

Actions tracées :

| Domaine | Actions |
|---|---|
| Connexion | `connexion`, `connexion_refusee`, `deconnexion`, `acces_refuse` (dossier d'un patient non autorisé) |
| Consultation | `page` (chaque page GET), `ouverture_dossier`, `ouverture_document` |
| Documents | `classement_document` |
| Dossier | `saisie_<type>`, `saisie_fiche`, `saisie_antecedent`, `saisie_note`, `modification_note`, `modification_note_refusee`, `masquage_note`, `masquage_antecedent` |
| Prescriptions | `saisie_prescription`, `modification_prescription`, `modification_prescription_refusee`, `suppression_prescription`, `statut_prescription`, `impression_ordonnance` |
| Protocoles (ancien écran) | `prescription_protocole`, `arret_protocole`, `impression_protocole` |
| Chimiothérapie | `traitement_chimio`, `arret_traitement_chimio`, `suppression_traitement_chimio`, `mesures_cure_chimio`, `saisie_jour_chimio`, `ok_chimio`, `retrait_ok_chimio`, `ajout_produit_chimio`, `retrait_produit_chimio`, `administration_chimio`, `impression_fiche_chimio` |
| Scanner | `prescription_scanner`, `retrait_scanner`, `impression_scanner` |
| Administration | `modification_droits`, `synchro_referentiels` |

---

## 5. Données Clinisys utilisées

Toutes en **lecture seule**. Bases (noms paramétrables dans `.env`) :

| Clé | Base par défaut | Contenu |
|---|---|---|
| `erp` | `Gclinique_Maroc` | patients, admissions, médecins, vues du planning, GED (vue `dbo.Documents`), chimio, référentiels |
| `rdv` | `rendez_vous_Maroc` | RDV et centres (lus directement ou par les vues `centre.*` de l'ERP) |
| `ged` | `GED` | arborescence de la GED |
| `ged_doc` | `GED_Doc` | comptes rendus radiologiques (désactivé) |

### 5.1 Tables et vues

| Objet | Utilisé pour |
|---|---|
| `erp.dbo.AccessControl` | comptes, mot de passe, médecin associé |
| `erp.dbo.Medecin` | noms des médecins, filtre du calendrier (`Actif = 1`) |
| `erp.dbo.Client` | identité et admissions (`Identifiant`, `NumDoss`, `DatArr`, `MedTrait`, `NumSoc`, `NumSocMutuelle`, `NumDevis`…) |
| `erp.dbo.Societe` | organisme et mutuelle (`DesSoc`) |
| `erp.dbo.motif`, `dbo.chambre`, `dbo.Reception` | motif d'admission et réception |
| `erp.dbo.correspendanceAdmission` | médecin correspondant |
| `erp.dbo.Devis` | devis du patient (pièces de prise en charge, diagnostic) |
| `erp.centre.RdvNormCHIMIO` | séances de chimiothérapie |
| `erp.centre.RdvNormRADIOTHERAPIE` | séances de radiothérapie |
| `erp.centre.RdvNorm` | toutes les séances (consultations : `Nature = 30`) |
| `erp.centre.Rdv` | centre (20 ou 23) et heures réelles de traitement (`debut_trait`, `fin_trait`) |
| `erp.centre.Salle` | libellés des chaises et appareils (`codeSalle`, `Designation`) |
| `erp.dbo.Examen_Complementaire` | programmes de chimio et de radio (`nbreCure`, `nbreSeance`, `Date_debut_traitement`…) |
| `rdv.dbo.rdv` | séances faites d'un programme (`Numdemande = Code_Examen`, `valide = 1`) et statistiques |
| `rdv.dbo.centre` | nature des centres (statistiques) |
| `erp.dbo.Tumeurs`, `dbo.Details_Tumeur` | référentiel des tumeurs (CIM) et tumeurs du patient |
| `erp.dbo.Antecedent`, `dbo.Famille_Antecedent`, `dbo.Details_Antecedent` | antécédents saisis dans Clinisys |
| `erp.dbo.Documents` (vue sur la GED, avec `numdevis`) + `ged.dbo.Arborescence` | pièces jointes |
| `ged_doc.dbo.Documents` | comptes rendus radiologiques |
| `erp.dbo.Type_Doc`, `dbo.CategorieType_Doc` | types et catégories des pièces jointes |
| `erp.dbo.Cure_Prescription` | protocoles de chimio prescrits dans Clinisys |
| `erp.dbo.ProtocoleCure`, `dbo.ProduitProtocoleCure`, `dbo.medicament_ProtocoleCure` | protocoles paramétrés et leurs lignes (jour, DCI, dose, dilution, durée, produit de chimio, DT) |
| `erp.dbo.medicamentCurePresc`, `dbo.ArtDet` | doses ajustées par patient dans Clinisys |
| `erp.dbo.DetailsCurePresc` | séances prévues d'une cure Clinisys (1-J01, 2-J01…) |
| `erp.dbo.Stock` | médicaments de la pharmacie (familles `F07` médicaments et `F18` sérums) |
| `erp.dbo.DCI` | liste des DCI |
| `erp.dbo.EXAMRAD` | catalogue des examens de radiologie |

### 5.2 Requêtes principales

**Calendrier** (`PlanningRepository::seances`) : `UNION ALL` de trois blocs.

```sql
-- Chimio (et de même pour la radio avec centre.RdvNormRADIOTHERAPIE)
SELECT 'CHIMIO' AS type, v.num_rdv, v.numdoss, v.Identifiant, v.nom, v.startDate, v.endDate,
       v.Seance, v.CodeRessource, v.etatRDV, v.Organisme, c.MedTrait, v.nomMedecin,
       r.Code_Centre, r.debut_trait, r.fin_trait, c.DatNai
FROM Gclinique_Maroc.centre.RdvNormCHIMIO v
JOIN Gclinique_Maroc.dbo.Client c ON c.NumDoss = v.numdoss COLLATE SQL_Latin1_General_CP1256_CI_AS
LEFT JOIN Gclinique_Maroc.centre.Rdv r ON r.Num_RDV = v.num_rdv
WHERE v.date_rdv >= @du AND v.date_rdv < DATEADD(day, 1, @au)
  AND v.numdoss NOT LIKE '%T%'
  AND c.MedTrait IN (@medecins)          -- sauf portée « tous »
  [AND r.Code_Centre = @centre]          -- filtre 20 / 23

-- Consultations
SELECT 'CONSULTATION', r.Num_RDV, r.NumDoss, c.Identifiant, r.Nom + ' ' + r.Prenom, r.startDate, r.endDate,
       r.CodeRessource, r.etatRDV, s.DesSoc, r.CodeMed, m.NomMed, c.DatNai
FROM Gclinique_Maroc.centre.RdvNorm r
LEFT JOIN Gclinique_Maroc.dbo.Client c ON c.NumDoss = r.NumDoss COLLATE ...
LEFT JOIN Gclinique_Maroc.dbo.Societe s ON s.NumSoc = c.NumSoc
LEFT JOIN Gclinique_Maroc.dbo.Medecin m ON m.CodMed = r.CodeMed COLLATE ...
WHERE r.Nature = 30 AND r.Date_RDV >= @du AND r.Date_RDV < DATEADD(day, 1, @au)
  AND ISNULL(r.NumDoss, '') NOT LIKE '%T%'
```

Points techniques : les dates sont passées au format `AAAAMMJJ` (seul format lu sans ambiguïté quelle que soit la langue du login SQL) ; chaque colonne texte est ramenée à `COLLATE DATABASE_DEFAULT` parce que les vues mélangent CP1256 et French_CI_AS, ce que `UNION ALL` refuse. Pour la chimio, le médecin est le médecin traitant du dossier (`Client.MedTrait`) ; pour la consultation, le médecin du RDV.

**Dossier** (`DossierRepository`) : patient (`TOP 1` de `Client` par identifiant, admission la plus récente), admissions avec médecin traitant, motif, réception, organisme, mutuelle, correspondant et diagnostic du devis ; programmes de `Examen_Complementaire` (natures 20 et 8) avec le nombre de séances validées dans `rendez_vous_Maroc.dbo.rdv` ; séances de `centre.RdvNorm` (natures 30, 20 et 8).

**Pièces jointes** (`PiecesJointesRepository`) : liste sans le contenu binaire, puis lecture de la colonne `Doc` par `DocID` seulement à l'ouverture, après vérification que le document figure dans la liste du patient. Le `DocID` doit être un GUID.

**Statistiques** (`StatistiquesRepository`) : comptage sur `rendez_vous_Maroc.dbo.rdv` joint à `dbo.centre` (nature), `Client` et `Medecin`, regroupé par période, par mois ou par médecin, exécuté par `OPENQUERY` sur le serveur Clinisys.

### 5.3 Correspondances utiles (relevées pendant l'analyse)

- Natures de centre (`rendez_vous_Maroc.dbo.centre.nature`) : 30 consultation, 20 chimiothérapie (centres 20 et 23), 8 radiothérapie, 21 curiethérapie (non affichée).
- `etatRDV` : 4 Prévu, 1 En salle, 5 En traitement, 2 Terminé, 6 Absent (radio). Le champ `Position` n'est pas toujours à jour : l'appli se fie à `etatRDV`.
- Réceptions : 9 consultation 1er étage, 20 oncologie, 11 consultation à domicile, 4 radio, 8 urgence, 1 principale.
- Chaîne de jointure : `rdv.Numdemande = Examen_Complementaire.Code_Examen`, `Examen_Complementaire.Numero_Dossier = Client.NumDoss`, `Client.NumDevis = Devis.numDevis`, `Devis.numSoc = Societe.NumSoc`.
- Catégories de pièces jointes (`CategorieType_Doc`) : 0 pièces administratives, 1 état civil, 2 assurance et PEC, 3 rapports médicaux, 4 imagerie médicale, 5 biologie et anapath, 6 demande d'examen, 07 ECG, 08 lettre médicale.

---

## 6. Base de l'application `oncologie_app`

Créée par les migrations Laravel (`database/migrations`). Le fichier `database/oncologie_app.sql` contient le même schéma en SQL pour une création manuelle, **mais seulement jusqu'à la migration du 27/10/2026** : les tables de novembre (traitements chimio, scanners, examens) ne sont créées que par `php artisan migrate` (ou `mise-a-jour.bat`).

| Table | Contenu |
|---|---|
| `onco_acces` | droits par utilisateur : `portee`, `admin`, `stats_portee`, `chimio_saisie`, `chimio_ok`, `chimio_administration`, auteur et date de modification |
| `onco_acces_medecins` | médecins visibles pour la portée `liste` |
| `onco_acces_stats_medecins` | médecins visibles dans les statistiques pour la portée `liste` |
| `onco_journal` | traçabilité (voir section 4) |
| `onco_pj_categories` | corrections manuelles du type d'une pièce jointe (`source`, `doc_id`, `code_type`) |
| `onco_fiches` | synthèse oncologique, une ligne par version (`identifiant`, `version`, pathologie, TNM, stade, marqueurs, statut, ECOG…) |
| `onco_antecedents` | antécédents saisis dans l'appli (masquables) |
| `onco_notes` | notes datées : consultation, histoire, évolution, RCP (modifiables par l'auteur, masquables) |
| `onco_prescriptions` | prescriptions (médicament, protocole, examen, autre), statut, modification et masquage tracés |
| `onco_cures`, `onco_cure_lignes` | protocoles prescrits avec l'ancien écran |
| `onco_traitements` | traitements de chimio : protocole, nombre de cures, intercure, jours du cycle, dates, rechute, localisation, histologie, stade, TNM, allergies, statut, masquage |
| `onco_traitement_cures` | cures : numéro, dates, poids, taille, surface corporelle (3 décimales), clairance |
| `onco_traitement_jours` | jours de cure : date prévue, observation, tension, température, douleur, « OK chimio » (qui, quand) |
| `onco_traitement_produits` | produits d'un jour : médicament, chimio oui/non, dose de référence, type de dose, dose calculée, dose retenue, voie, solvant, volume, durée, administration (qui, quand) |
| `onco_scanners` | prescriptions de scanner de dosimétrie |
| `onco_ref_protocoles`, `onco_ref_protocole_lignes` | copie des protocoles de chimio de Clinisys |
| `onco_ref_medicaments` | copie des médicaments : pharmacie, DCI et liste AMMPS (dosage, forme, présentation, classe, laboratoire, statut, princeps / générique, PPV, PH) |
| `onco_ref_examens` | copie du catalogue des examens de radiologie |
| `onco_ref_synchro` | date, auteur et nombre de lignes de la dernière copie de chaque référentiel |
| `sessions`, `cache`, `cache_locks`, `jobs`… | tables standard de Laravel (les sessions et le cache sont en fichiers en production) |

Conventions : identifiant patient Clinisys (`identifiant`) comme clé de rattachement ; rien n'est supprimé physiquement pour les données médicales (`masque_par` / `masque_le`) ; l'auteur est le login Clinisys (`saisi_par`, `prescrit_par`…), avec le nom du médecin copié pour l'affichage.

---

## 7. Installation et configuration

### 7.1 Prérequis

- Windows Server avec IIS (CGI / FastCGI activé), ou tout serveur PHP pour le développement.
- PHP 8.3 ou plus, avec les extensions `pdo_sqlsrv`, `sqlsrv`, `mbstring`, `openssl`, `fileinfo`. **ODBC Driver 18 for SQL Server.**
- Composer.
- Un serveur SQL Server pour `oncologie_app` et un accès à Clinisys (direct ou par serveur lié).
- Accès Internet des postes vers les CDN de FullCalendar et Chart.js (voir les points de vigilance).

### 7.2 Bases et comptes SQL

```sql
CREATE DATABASE oncologie_app;
```

- Compte de l'appli sur `oncologie_app` : `db_datareader`, `db_datawriter` et `db_ddladmin` (pour les migrations).
- Compte Clinisys : **lecture seule** (`SELECT`) sur `Gclinique_Maroc`, `rendez_vous_Maroc`, `GED` et, plus tard, `GED_Doc`. La lecture seule est garantie par les droits de ce compte, pas par le code.
- Avec un serveur lié, le compte du lien doit aussi pouvoir lire ces bases, et `OPENQUERY` doit être autorisé (statistiques).

### 7.3 Fichier `.env`

Partir de `.env.example`, puis :

```ini
APP_NAME=Oncologie
APP_ENV=production            # local en développement
APP_DEBUG=false               # true en développement
APP_URL=http://<serveur>:<port>
APP_LOCALE=fr

# Base de l'application
DB_CONNECTION=sqlsrv
DB_HOST=<serveur SQL>
DB_PORT=1433
DB_DATABASE=oncologie_app
DB_USERNAME=<compte appli>
DB_PASSWORD=<mot de passe>
DB_ENCRYPT=no

SESSION_DRIVER=file
CACHE_STORE=file

# Clinisys (lecture seule)
CLINISYS_HOST=<serveur SQL>
CLINISYS_PORT=1433
CLINISYS_DATABASE=master            # base ouverte à la connexion : master avec un serveur lié, sinon la base ERP
CLINISYS_USERNAME=<compte lecture seule>
CLINISYS_PASSWORD=<mot de passe>
CLINISYS_PREFIXE=[erp_link]         # serveur lié ; vide si connexion directe
CLINISYS_BASE_ERP=Gclinique_Maroc
CLINISYS_BASE_RDV=rendez_vous_Maroc
CLINISYS_BASE_GED=GED
CLINISYS_BASE_GED_DOC=GED_Doc
CLINISYS_GED_DOC_ACTIF=false        # true quand le serveur lié lira GED_Doc
CLINISYS_ENCRYPT=no
CLINISYS_TRUST_SERVER_CERTIFICATE=true
CLINISYS_HASH=pbkdf2-sha1           # schéma des mots de passe AccessControl
# CLINISYS_TABLE_COMPTES=dbo.AccessControl

# Application
ONCO_ADMINS=<login1>,<login2>       # administrateurs de l'appli (logins Clinisys)
# ONCO_ETABLISSEMENT="CLINIQUE INTERNATIONALE IBN SINA TANGER"
```

En production actuelle, l'appli et `oncologie_app` sont sur le serveur `192.168.1.227` (SQL Server sur le port 1416) et Clinisys est atteint par le serveur lié `[erp_link]`. Les mots de passe ne sont jamais versionnés (`.env` est dans `.gitignore`).

Autres paramètres dans `config/oncologie.php` : natures de centre, couleurs du calendrier, libellés et couleurs des statuts, collation, synonymes et mots-clés du classement des pièces jointes.

### 7.4 Première installation

```bash
composer install --no-dev --optimize-autoloader
copy .env.example .env               # puis remplir
php artisan key:generate
php artisan migrate --force          # crée les tables onco_* dans oncologie_app
php artisan onco:tester-mot-de-passe <login>   # vérifie le schéma de hachage
php artisan onco:referentiels        # copie protocoles, médicaments et examens
php artisan onco:ammps               # (facultatif) liste marocaine des médicaments
php artisan optimize
```

Sur le serveur IIS, l'installation est automatisée :

1. Placer côte à côte les dossiers `oncologie-app` et `php-oncologie` (PHP portable) et le fichier `deploiement/INSTALLER.bat`.
2. Clic droit sur `INSTALLER.bat` > Exécuter en tant qu'administrateur. Le script `installer-iis.ps1` vérifie IIS et CGI, règle `php.ini`, déclare le gestionnaire FastCGI (8 processus PHP), choisit un port libre entre 8090 et 8199 (ou garde celui du site existant), crée le site et son pool, donne les droits (lecture sur l'appli et PHP, écriture sur `storage` et `bootstrap\cache`), ouvre le port dans le pare-feu, passe l'appli en production, programme la copie des référentiels **chaque dimanche à 2 h** et teste la page de connexion.

En développement : `php artisan serve` (voir `.claude/launch.json`).

---

## 8. Mise à jour, commandes et tâches planifiées

### Livraison d'une mise à jour

Les mises à jour ont été livrées sous forme de zips (`maj-oncologie-AAAAMMJJ-n.zip`, dossier `D:\Projet\livraison`) contenant seulement les fichiers modifiés. Sur le serveur : extraire le zip par-dessus `oncologie-app`, puis double-cliquer sur **`mise-a-jour.bat`**, qui :

1. applique les nouvelles migrations (`php artisan migrate --force`) ;
2. recopie protocoles et médicaments depuis Clinisys (`php artisan onco:referentiels`) ;
3. reconstruit les caches (`optimize:clear` puis `optimize`).

Attention : un zip non installé laisse le serveur en retard (c'est arrivé avec la correction de la pagination du journal). En cas de doute, comparer les fichiers du serveur avec ce dépôt.

### Commandes artisan

| Commande | Rôle |
|---|---|
| `onco:tester-mot-de-passe {login}` | vérifie un mot de passe Clinisys et affiche le schéma de hachage reconnu |
| `onco:referentiels` | copie dans `oncologie_app` les protocoles de chimio, leurs lignes, les médicaments (pharmacie, DCI) et les examens de radiologie. À relancer après un nouveau paramétrage dans Clinisys. Même effet que le bouton de l'écran `/acces`. Si la copie n'a jamais été faite, les écrans la déclenchent. |
| `onco:ammps {--pause=1} {--pages=}` | importe la liste marocaine des médicaments publiée par l'AMMPS (site du ministère de la Santé). Environ 500 pages lues une à une, 10 à 15 minutes, à lancer une fois par mois environ. Si la lecture échoue, la liste actuelle est conservée. |

Scripts Windows à la racine : `mise-a-jour.bat`, `mise-a-jour-referentiels.bat`, `mise-a-jour-medicaments.bat` (ce dernier exporte d'abord les autorités de certification de Windows dans `storage\app\certificats-windows.pem` pour que PHP accepte le HTTPS du réseau). Ils cherchent PHP dans `..\php-oncologie`, `D:\Outils\php` ou `D:\Apps\php`.

Tâche planifiée Windows créée par l'installateur : « Oncologie - copie protocoles et traitements », chaque dimanche à 2 h.

---

## 9. Organisation du code

| Fichier | Rôle |
|---|---|
| `config/oncologie.php` | bases, natures, couleurs, statuts, auth, classement des pièces jointes |
| `config/database.php` | connexions `sqlsrv` (appli) et `clinisys` (lecture seule) |
| `config/auth.php` | garde `web` sur le fournisseur `accesscontrol` |
| `app/Support/Clinisys.php` | noms d'objets Clinisys, serveur lié, `OPENQUERY`, collation |
| `app/Auth/*` | connexion AccessControl, utilisateur, vérification PBKDF2 |
| `app/Providers/OncologieServiceProvider.php` | enregistre le fournisseur `accesscontrol` |
| `app/Services/Droits.php` | portées patients et statistiques, droits chimio |
| `app/Services/Journal.php` | écriture du journal |
| `app/Services/ClasseurPiecesJointes.php` | classement des pièces jointes |
| `app/Services/Referentiels.php` | copie des référentiels Clinisys |
| `app/Repositories/PlanningRepository.php` | requête du calendrier, chaises et appareils, médecins |
| `app/Repositories/DossierRepository.php` | patient, admissions, devis, programmes, séances |
| `app/Repositories/PiecesJointesRepository.php` | pièces jointes GED / GED_Doc |
| `app/Repositories/OncologieRepository.php` | listes de valeurs, tumeurs et antécédents Clinisys, saisies de l'appli |
| `app/Repositories/ChimioRepository.php` | cures et protocoles Clinisys, surface corporelle, recherche de médicaments |
| `app/Repositories/TraitementsChimio.php` | traitements de chimio de l'appli : création, calcul des doses, recalcul |
| `app/Repositories/StatistiquesRepository.php` | agrégats d'activité |
| `app/Http/Controllers/PlanningController.php` | calendrier, flux JSON, recherche |
| `app/Http/Controllers/DossierController.php` | dossier, documents, saisies, prescriptions, ordonnance, protocoles |
| `app/Http/Controllers/TraitementChimioController.php` | module chimio |
| `app/Http/Controllers/ScannerDosimetrieController.php` | scanner de dosimétrie |
| `app/Http/Controllers/StatistiquesController.php` | statistiques |
| `app/Http/Controllers/AdminController.php` | accès, journal, copie des référentiels |
| `app/Http/Middleware/` | `AdminOnco` (réservé aux admins), `JournaliserConsultation` (trace les pages), `MesurerTemps` (lenteurs) |
| `app/Console/Commands/` | les trois commandes `onco:*` |
| `resources/views/` | `planning`, `patients`, `dossier` (fiche, ordonnance, protocole), `chimio` (liste, cure, fiche A4), `scanner`, `statistiques`, `admin`, `auth`, `layouts` |
| `database/migrations/` | schéma de `oncologie_app` |
| `database/oncologie_app.sql` | schéma SQL jusqu'au 27/10/2026 |
| `deploiement/` | installation IIS |
| `public/web.config` | réécriture d'URL IIS vers `index.php` |

Il n'y a pas encore de tests automatisés (seulement les exemples de Laravel).

---

## 10. Points de vigilance

1. **Ne jamais écrire dans Clinisys.** Toute nouvelle fonctionnalité doit enregistrer dans `oncologie_app`. Garder un compte SQL Clinisys strictement en lecture seule.
2. **Doses de chimiothérapie.** Les formules (Boyd tronquée à 3 décimales, Calvert plafonnée à 125 ml/min, mg/kg) ont été vérifiées contre les cures de Clinisys. Toute modification de `TraitementsChimio` ou `ChimioRepository::surfaceCorporelle` doit être revalidée avec un oncologue sur des cas réels. Le recalcul ne touche pas les jours qui ont déjà le « OK chimio ».
3. **Dossiers « T ».** Toujours les exclure (`NumDoss NOT LIKE '%T%'`) dans les nouvelles requêtes.
4. **Collations.** Toute jointure entre l'ERP et la base RDV, ou tout `UNION`, doit forcer la collation (`Clinisys::collate()` ou `COLLATE DATABASE_DEFAULT`), sinon « Cannot resolve collation conflict ».
5. **Dates SQL.** Passer les dates au format `AAAAMMJJ`.
6. **Serveur lié.** Les requêtes à travers `[erp_link]` sont lentes : garder les caches, et passer les regroupements par `Clinisys::distant()` (OPENQUERY). `OPENQUERY` n'accepte pas de paramètres : n'y injecter que des valeurs validées. La fonction `FN_Nbre_Seance_realiser` ne passe pas par un serveur lié (d'où le comptage direct dans `rdv`).
7. **GED_Doc** est désactivée : il faut d'abord donner au serveur lié l'accès à cette base, puis passer `CLINISYS_GED_DOC_ACTIF=true`.
8. **CDN.** FullCalendar (jsDelivr) et Chart.js (cdnjs) sont chargés depuis Internet. Si les postes perdent l'accès Internet, le calendrier et les statistiques ne s'affichent plus : il faudra copier ces fichiers dans `public/`.
9. **Données de test.** Des traitements de test ont été créés et supprimés en production le 06/11/2026. Faire les essais sur un patient de test ou sur une base de test, et prévenir avant de supprimer.
10. **Secrets.** Ne pas versionner `.env`. La clé `APP_KEY` chiffre les sessions : la changer déconnecte tout le monde.
11. **Journal.** Ne jamais modifier ni purger `onco_journal` sans décision écrite de la direction : c'est la preuve d'accès aux dossiers médicaux.
12. **Mises à jour en zip.** Vérifier que tous les zips ont bien été installés, dans l'ordre. Une migration manquante bloque des écrans.

---

## 11. État d'avancement et pistes restantes

### Fait (en production)

- Connexion par les comptes Clinisys (PBKDF2-SHA1 identifié), droits par utilisateur, écran d'administration, traçabilité complète et paginée.
- Calendrier hebdomadaire (consultation, chimio par centre, radio avec heure réelle), recherche de patient, détail des séances.
- Dossier patient : en-tête, synthèse versionnée, saisie unique, antécédents, notes modifiables par l'auteur, historique des consultations, chronologie.
- Pièces jointes GED par rubrique, classement automatique corrigeable, ouverture tracée.
- Prescriptions avec recherche de médicaments (pharmacie, DCI, AMMPS) et catalogue d'examens, ordonnance imprimable.
- Protocoles de Clinisys consultables et imprimables.
- **Module de chimiothérapie refait** (novembre 2026) : saisie du protocole avec lignes modifiables avant enregistrement, calcul mg/m², mg/kg, AUC (clairance), DT, cures, jours et produits, OK chimio, signature de l'administration, fiche A4, arrêt et suppression contrôlés.
- Scanner de dosimétrie.
- Statistiques d'activité avec droits séparés.
- Installation IIS automatisée, script de mise à jour, copie hebdomadaire des référentiels.

### Pistes restantes

- **GED_Doc** (comptes rendus radiologiques) : obtenir l'accès du serveur lié, puis activer.
- **Curiethérapie** (nature 21, vue `centre.RdvNormCurietherapie`) : non affichée dans le calendrier ni les statistiques.
- **Rattachement des dossiers « T »** au dossier principal (lien probable via `numdossReference`) pour afficher les extras dans le dossier.
- **Analyse du module RDV Clinisys** (fichiers `analyse-rdv-oncologie*.md` du projet) : il reste à confirmer les noms réels de certaines tables derrière les API web (`centre-core` sur le port 9054), la table des statuts `position`, la table des ressources (chaises, appareils VersaHD / CURIE) et le code nature exact de toutes les consultations. L'analyse a aussi relevé les API de Clinisys (`GetListeClientForAdm`, `GetListeClientStatusExamen`…), non utilisées : l'appli lit directement SQL Server.
- **Lien avec le planning Clinisys** : les traitements de chimio de l'appli ne créent pas de RDV dans Clinisys (par principe). Un rapprochement entre les jours prévus de l'appli et les RDV de chimio de Clinisys serait utile pour repérer les écarts.
- **Plafonds de dose** : seule la vincristine a un plafond. Compléter la liste avec les oncologues.
- **Ancien écran de protocole** (`onco_cures`) : à retirer une fois l'historique migré ou jugé inutile.
- **Tests automatisés** : au minimum sur le calcul des doses, la surface corporelle, les droits et le décodage des jours.
- **Ressources front locales** au lieu des CDN.
- **`database/oncologie_app.sql`** à régénérer avec les migrations de novembre (`php artisan migrate --pretend`).
