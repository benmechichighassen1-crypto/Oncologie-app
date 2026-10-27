# Consultation oncologie (Laravel 11)

Application de consultation en **lecture seule** sur Clinisys :
- **Calendrier de la semaine par médecin** : consultations (centre 30), chimiothérapie (centres 20 et 23, avec un filtre par centre) et radiothérapie. Une couleur par type, une bordure par statut. Un clic ouvre le dossier.
- **Dossier patient** : identité, organisme, mutuelle, médecin traitant et médecin correspondant, admissions par date, programmes de cure (n/N séances) et rendez-vous.
- **Pièces jointes par catégorie** : GED (pièces jointes) et GED_DOC (comptes rendus radiologiques). Le classement est automatique et le médecin peut le corriger. Un fichier n'est lu qu'à l'ouverture.
- **Connexion** avec le login et le mot de passe Clinisys (AccessControl). Par défaut, chaque médecin voit ses patients. L'administrateur ouvre d'autres médecins, ou tous.
- **Traçabilité** : connexions, refus, pages, dossiers et documents ouverts, corrections de catégorie, changements de droits.

L'application n'écrit jamais dans Clinisys. Ses propres données (droits, journal, corrections, sessions) vont dans une base dédiée `oncologie_app`.

## Installation

Prérequis : PHP 8.2 ou plus avec les extensions `pdo_sqlsrv`, `sqlsrv`, `mbstring` et `openssl`, Composer, et le ODBC Driver 18 for SQL Server.

```bash
composer create-project laravel/laravel oncologie "11.*"
# Copier par-dessus le contenu de ce dossier (app, bootstrap, config, database, resources, routes, .env.example)
cd oncologie
cp .env.example .env        # puis remplir les comptes SQL
php artisan key:generate
php artisan migrate         # crée onco_* + sessions + cache dans oncologie_app
php artisan onco:tester-mot-de-passe <un_login_de_test>   # identifie le hachage Clinisys
# Reporter la valeur affichée dans CLINISYS_HASH (.env), puis :
php artisan config:cache
```

Base de l'appli (à créer une fois sur le serveur SQL) :
```sql
CREATE DATABASE oncologie_app;
-- compte de l'appli : db_datareader + db_datawriter + db_ddladmin sur oncologie_app uniquement
```

## Points à vérifier au premier lancement
1. **Mot de passe** : AccessControl stocke `hasched_password` + `salt` + `hash_iterations` (PBKDF2, en base64). La commande `onco:tester-mot-de-passe` identifie la variante (SHA-512, SHA-256 ou SHA-1) avec un compte dont tu connais le mot de passe. Si aucune ne correspond, envoie-moi le message affiché : il donne la longueur du hash et le nombre d'itérations, sans rien révéler.
2. **Libellés des chaises et des appareils** : ils sont lus dans la vue `centre.Salle` (`codeSalle`, `Salle`). Si la vue a d'autres colonnes, le calendrier affiche le code à la place, sans erreur.
3. **Serveur lié** : les appels à la fonction `FN_Nbre_Seance_realiser` ne passent pas par un serveur lié. Connecte l'appli directement au serveur Clinisys (`CLINISYS_HOST`), comme pour les tests.
4. **Collation** : si le calendrier renvoie « Cannot resolve collation conflict », envoie-moi le message.

## Fichiers
| Fichier | Rôle |
|---|---|
| `config/oncologie.php` | Bases, natures de centre, couleurs, statuts, synonymes des pièces jointes |
| `app/Auth/*` | Connexion AccessControl et vérification du mot de passe |
| `app/Services/Droits.php` | Portée propre / liste / tous |
| `app/Repositories/PlanningRepository.php` | Requête du calendrier |
| `app/Repositories/DossierRepository.php` | Admissions, programmes et séances |
| `app/Repositories/PiecesJointesRepository.php` + `app/Services/ClasseurPiecesJointes.php` | Pièces jointes GED / GED_DOC et leur classement |
| `app/Http/Controllers/AdminController.php` | Paramétrage des accès et journal |
| `database/migrations/*` | Tables onco_acces, onco_acces_medecins, onco_journal, onco_pj_categories |
