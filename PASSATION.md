# Passation du projet Oncologie

Document de reprise, rédigé le 10/11/2026 avant le passage vers un nouveau compte Claude (mail pro).
La description technique complète est dans le **README.md** de ce dossier (et du dépôt GitHub). Ce fichier-ci résume où on en est et comment reprendre.

---

## 1. Le projet en deux phrases

Application web Laravel interne de la Clinique Internationale Ibn Sina (Tanger) pour l'oncologie : calendrier des patients, dossier patient, saisies médicales, traitements de chimiothérapie, scanner de dosimétrie, statistiques et traçabilité.
Elle **lit Clinisys en lecture seule** et enregistre tout ce qui lui est propre dans la base SQL Server **`oncologie_app`**.

## 2. Où se trouve quoi

| Élément | Emplacement |
|---|---|
| Code de référence (dernière version) | `D:\Projet\oncologie-app` (ce dossier) |
| Dépôt GitHub | `benmechichighassen1-crypto/oncologie-app`, branche `main` + PR n° 1 (README et code à jour au 06/11) |
| Ancienne version (octobre, ne plus utiliser) | `D:\Projet\oncologie` |
| Zips de mise à jour livrés | `D:\Projet\livraison\maj-oncologie-AAAAMMJJ-n.zip` (+ `preparer-maj.ps1`) |
| Paquet d'installation IIS | `D:\Projet\livraison\oncologie-iis-20261026.zip` |
| Serveur de production | `192.168.1.227` (IIS + PHP portable `php-oncologie`, SQL Server port 1416, serveur lié `[erp_link]` vers Clinisys) |
| Analyse du module RDV Clinisys | fichiers `analyse-rdv-oncologie*.md` du projet Claude (le plus récent : `...T231848-65de.md`) |
| Exemple de fiche du logiciel de cabinet | `fiche-exemple.pdf` (projet Claude) |
| Configuration | `D:\Projet\oncologie-app\.env` (jamais versionné, contient les mots de passe) |

## 3. Décisions prises (à respecter)

1. **Jamais d'écriture dans Clinisys.** Le compte SQL Clinisys est en lecture seule ; tout est enregistré dans `oncologie_app`.
2. **Connexion avec les comptes Clinisys** (table `AccessControl`), mot de passe vérifié par l'appli en **PBKDF2-SHA1** (`CLINISYS_HASH=pbkdf2-sha1`). Pas de comptes propres à l'appli.
3. **Droits** : par défaut un médecin voit ses patients, un administrateur voit tout. L'écran `/acces` règle la portée (propre, liste, tous), les statistiques et les droits chimio. Administrateurs : `ONCO_ADMINS` dans `.env` (aujourd'hui `ghassen`) + case admin.
4. **Droits chimio** : saisie et « OK chimio » pour tout compte médecin ; **signature de l'administration uniquement sur droit donné** (infirmiers).
5. **Dossiers « T »** (sous-dossiers administratifs des extras) exclus partout.
6. **Rien n'est effacé** dans les données médicales : retrait = masquage, ancien contenu recopié dans le journal. Une note ou une prescription ne se modifie que par son auteur.
7. **Chimio** : surface corporelle de Boyd tronquée à 3 décimales (comme Clinisys) ; AUC par Calvert avec clairance plafonnée à 125 ml/min ; écart à la dose calculée affiché mais jamais imprimé ; fiche A4 refusée tant qu'une dose manque ; administration seulement après le « OK chimio ».
8. **Référentiels copiés** dans `oncologie_app` (protocoles, médicaments, examens) pour la vitesse, recopiés chaque dimanche à 2 h et par le bouton de `/acces`.
9. **GED_Doc désactivée** tant que le serveur lié n'a pas accès à cette base.
10. **Livraison par zips** + `mise-a-jour.bat` (migrations, copie des référentiels, caches).

## 4. Historique des fils de travail

| Période | Fil | Résultat |
|---|---|---|
| 23 au 25/10 | Analyse du module RDV oncologie | Relevé des pages, API et tables Clinisys (RDV, chimio, radio, GED). Requêtes du calendrier mises au point. |
| 24 au 26/10 | Première version de l'appli | Calendrier, dossier, pièces jointes, connexion AccessControl, droits, journal. Installation IIS. |
| 25/10 au 02/11 | Finaliser notes et protocoles chimio | Saisie unique, notes modifiables, prescriptions, ordonnance, protocoles Clinisys imprimables, référentiels, AMMPS, statistiques. |
| 27/10 | Comprendre la base oncologie_app / Mettre le code sur GitHub | Script `oncologie_app.sql`, premier dépôt GitHub. |
| 02 au 06/11 | Refaire la saisie du protocole de chimio | Calendrier amélioré, pagination du journal, nouveau module chimio (traitement > cures > jours > produits), scanner de dosimétrie, examens, suppression contrôlée, clairance / AUC. Suppression des 8 traitements de test du 05/11 (Dr Mejdoubi). |
| 10/11 | README détaillé et passation | README complet, dépôt GitHub remis à jour (PR n° 1), ce fichier. |

## 5. État au 10/11/2026

- En production et utilisé : tout ce qui est listé dans la section 11 du README.
- Dernier zip livré : `maj-oncologie-20261106-4-pagination.zip` (correction de pagination du journal, à vérifier qu'il a bien été installé).
- Un traitement **CARBOPLATINE PACLITAXEL** saisi le 06/11 à 9 h 21 sous le compte de Ghassan : il avait été demandé si c'était aussi un test, sans réponse. À vérifier.
- Le dépôt GitHub `main` est encore à l'état du 27/10 tant que la **PR n° 1** n'est pas fusionnée.

## 6. Ce qui reste à faire

Par ordre d'utilité :

1. **Fusionner la PR n° 1** sur GitHub pour que `main` soit la version de production.
2. Vérifier que tous les zips de novembre sont installés sur le serveur (comparer avec `D:\Projet\oncologie-app`).
3. Trancher sur le traitement CARBOPLATINE PACLITAXEL du 06/11 (test ou réel).
4. Activer **GED_Doc** (comptes rendus radiologiques) : droits du serveur lié, puis `CLINISYS_GED_DOC_ACTIF=true`.
5. Compléter les **plafonds de dose** avec les oncologues (seule la vincristine 2 mg existe).
6. Ajouter la **curiethérapie** (nature 21) au calendrier et aux statistiques.
7. Rapprocher les jours de chimio prévus dans l'appli et les RDV de chimio de Clinisys.
8. Rattacher les dossiers « T » au dossier principal (`numdossReference`) pour voir les extras.
9. Finir les points ouverts de l'analyse RDV (statuts `position`, table des ressources, codes nature des consultations).
10. Copier FullCalendar et Chart.js dans `public/` (aujourd'hui chargés depuis Internet).
11. Tests automatisés sur le calcul des doses et les droits.
12. Régénérer `database/oncologie_app.sql` avec les migrations de novembre ; retirer l'ancien écran de protocole (`onco_cures`) quand il n'est plus utile.

## 7. Comment reprendre avec le nouveau compte

1. **GitHub** : connecter le nouveau compte Claude à GitHub (claude.ai/connect-github) et donner l'accès au dépôt `oncologie-app`. Si le dépôt change de propriétaire, garder le même nom.
2. **Projet Claude** : créer un projet, y ajouter le dépôt, et y déposer ce fichier, le `README.md` et, si utile, les fichiers d'analyse RDV et `fiche-exemple.pdf` (l'ancien projet n'est pas transféré automatiquement).
3. **Accès au PC** : autoriser le dossier `D:\Projet` pour que Claude lise le code et prépare les zips dans `D:\Projet\livraison`.
4. Premier message conseillé : « Lis PASSATION.md et README.md à la racine de D:\Projet\oncologie-app, puis continue le projet. »
5. Pour chaque évolution : travailler dans `D:\Projet\oncologie-app`, tester en local (`php artisan serve`), livrer un zip, l'installer sur le serveur et lancer `mise-a-jour.bat`, puis pousser sur GitHub pour garder le dépôt à jour.

## 8. Contacts et comptes

- Porteur du projet et administrateur de l'appli : Ghassan (login Clinisys `ghassen`).
- Médecin référent des essais chimio : Dr Mejdoubi.
- Les mots de passe (SQL, Clinisys) sont uniquement dans le `.env` du serveur et du PC : ne pas les copier dans Claude ni sur GitHub.
