-- Tables de l'application oncologie (base oncologie_app). A executer une seule fois, sur la base oncologie_app.
USE oncologie_app;
GO

-- 0001_01_01_000000_create_users_table
create table "users" ("id" bigint not null identity primary key, "name" nvarchar(255) not null, "email" nvarchar(255) not null, "email_verified_at" datetime2(0) null, "password" nvarchar(255) not null, "remember_token" nvarchar(100) null, "created_at" datetime2(0) null, "updated_at" datetime2(0) null);
create unique index "users_email_unique" on "users" ("email");
create table "password_reset_tokens" ("email" nvarchar(255) not null, "token" nvarchar(255) not null, "created_at" datetime2(0) null);
alter table "password_reset_tokens" add constraint "password_reset_tokens_email_primary" primary key ("email");
create table "sessions" ("id" nvarchar(255) not null, "user_id" nvarchar(50) null, "ip_address" nvarchar(45) null, "user_agent" nvarchar(max) null, "payload" nvarchar(max) not null, "last_activity" int not null);
alter table "sessions" add constraint "sessions_id_primary" primary key ("id");
create index "sessions_user_id_index" on "sessions" ("user_id");
create index "sessions_last_activity_index" on "sessions" ("last_activity");

-- 0001_01_01_000001_create_cache_table
create table "cache" ("key" nvarchar(255) not null, "value" nvarchar(max) not null, "expiration" bigint not null);
alter table "cache" add constraint "cache_key_primary" primary key ("key");
create index "cache_expiration_index" on "cache" ("expiration");
create table "cache_locks" ("key" nvarchar(255) not null, "owner" nvarchar(255) not null, "expiration" bigint not null);
alter table "cache_locks" add constraint "cache_locks_key_primary" primary key ("key");
create index "cache_locks_expiration_index" on "cache_locks" ("expiration");

-- 0001_01_01_000002_create_jobs_table
create table "jobs" ("id" bigint not null identity primary key, "queue" nvarchar(255) not null, "payload" nvarchar(max) not null, "attempts" smallint not null, "reserved_at" int null, "available_at" int not null, "created_at" int not null);
create index "jobs_queue_index" on "jobs" ("queue");
create table "job_batches" ("id" nvarchar(255) not null, "name" nvarchar(255) not null, "total_jobs" int not null, "pending_jobs" int not null, "failed_jobs" int not null, "failed_job_ids" nvarchar(max) not null, "options" nvarchar(max) null, "cancelled_at" int null, "created_at" int not null, "finished_at" int null);
alter table "job_batches" add constraint "job_batches_id_primary" primary key ("id");
create table "failed_jobs" ("id" bigint not null identity primary key, "uuid" nvarchar(255) not null, "connection" nvarchar(255) not null, "queue" nvarchar(255) not null, "payload" nvarchar(max) not null, "exception" nvarchar(max) not null, "failed_at" datetime2(0) not null default CURRENT_TIMESTAMP);
create index "failed_jobs_connection_queue_failed_at_index" on "failed_jobs" ("connection", "queue", "failed_at");
create unique index "failed_jobs_uuid_unique" on "failed_jobs" ("uuid");

-- 2026_10_24_000001_create_onco_tables
create table "onco_acces" ("user_name" nvarchar(50) not null, "portee" nvarchar(10) not null default 'propre', "admin" bit not null default '0', "modifie_par" nvarchar(50) null, "modifie_le" datetime2(0) null);
alter table "onco_acces" add constraint "onco_acces_user_name_primary" primary key ("user_name");
create table "onco_acces_medecins" ("user_name" nvarchar(50) not null, "cod_med" nvarchar(10) not null);
alter table "onco_acces_medecins" add constraint "onco_acces_medecins_user_name_cod_med_primary" primary key ("user_name", "cod_med");
create table "onco_journal" ("id" bigint not null identity primary key, "user_name" nvarchar(50) null, "action" nvarchar(40) not null, "identifiant_patient" nvarchar(20) null, "num_doss" nvarchar(20) null, "document" nvarchar(60) null, "details" nvarchar(max) null, "ip" nvarchar(45) null, "navigateur" nvarchar(255) null, "url" nvarchar(500) null, "cree_le" datetime2(0) not null);
create index "onco_journal_user_name_index" on "onco_journal" ("user_name");
create index "onco_journal_action_index" on "onco_journal" ("action");
create index "onco_journal_identifiant_patient_index" on "onco_journal" ("identifiant_patient");
create index "onco_journal_cree_le_index" on "onco_journal" ("cree_le");
create table "onco_pj_categories" ("source" nvarchar(10) not null, "doc_id" nvarchar(40) not null, "code_type" nvarchar(10) not null, "modifie_par" nvarchar(50) not null, "modifie_le" datetime2(0) not null);
alter table "onco_pj_categories" add constraint "onco_pj_categories_source_doc_id_primary" primary key ("source", "doc_id");

-- 2026_10_25_000001_create_onco_saisie_tables
create table "onco_fiches" ("id" bigint not null identity primary key, "identifiant" nvarchar(20) not null, "version" int not null, "code_tumeur" int null, "pathologie" nvarchar(255) null, "diagnostic" nvarchar(500) null, "date_diagnostic" date null, "localisation" nvarchar(150) null, "lateralite" nvarchar(20) null, "histologie" nvarchar(255) null, "grade" nvarchar(30) null, "stade_t" nvarchar(10) null, "stade_n" nvarchar(10) null, "stade_m" nvarchar(10) null, "stade" nvarchar(20) null, "marqueurs" nvarchar(255) null, "statut" nvarchar(30) null, "traitements" nvarchar(255) null, "ecog" nvarchar(5) null, "commentaire" nvarchar(max) null, "saisi_par" nvarchar(50) not null, "saisi_le" datetime2(0) not null);
create unique index "onco_fiches_identifiant_version_unique" on "onco_fiches" ("identifiant", "version");
create index "onco_fiches_identifiant_index" on "onco_fiches" ("identifiant");
create table "onco_antecedents" ("id" bigint not null identity primary key, "identifiant" nvarchar(20) not null, "famille" nvarchar(20) not null, "libelle" nvarchar(255) not null, "periode" nvarchar(50) null, "details" nvarchar(max) null, "saisi_par" nvarchar(50) not null, "saisi_le" datetime2(0) not null, "masque_par" nvarchar(50) null, "masque_le" datetime2(0) null);
create index "onco_antecedents_identifiant_index" on "onco_antecedents" ("identifiant");
create table "onco_notes" ("id" bigint not null identity primary key, "identifiant" nvarchar(20) not null, "type" nvarchar(20) not null, "date_note" date not null, "texte" nvarchar(max) not null, "saisi_par" nvarchar(50) not null, "saisi_le" datetime2(0) not null, "masque_par" nvarchar(50) null, "masque_le" datetime2(0) null);
create index "onco_notes_identifiant_index" on "onco_notes" ("identifiant");

-- 2026_10_25_000002_create_onco_prescriptions_table
create table "onco_prescriptions" ("id" bigint not null identity primary key, "identifiant" nvarchar(20) not null, "num_doss" nvarchar(20) null, "date_prescription" date not null, "type" nvarchar(20) not null, "libelle" nvarchar(255) not null, "dose" nvarchar(60) null, "voie" nvarchar(40) null, "frequence" nvarchar(80) null, "duree" nvarchar(60) null, "nb_cures" smallint null, "statut" nvarchar(20) not null default 'prescrite', "commentaire" nvarchar(max) null, "prescrit_par" nvarchar(50) not null, "saisi_le" datetime2(0) not null, "statut_par" nvarchar(50) null, "statut_le" datetime2(0) null);
create index "onco_prescriptions_identifiant_index" on "onco_prescriptions" ("identifiant");

-- Enregistre les migrations comme faites, pour que « php artisan migrate » ne les rejoue pas.
IF OBJECT_ID('migrations') IS NULL CREATE TABLE migrations (id int identity primary key, migration nvarchar(255) not null, batch int not null);
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '0001_01_01_000000_create_users_table') INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000000_create_users_table', 1);
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '0001_01_01_000001_create_cache_table') INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000001_create_cache_table', 1);
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '0001_01_01_000002_create_jobs_table') INSERT INTO migrations (migration, batch) VALUES ('0001_01_01_000002_create_jobs_table', 1);
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_24_000001_create_onco_tables') INSERT INTO migrations (migration, batch) VALUES ('2026_10_24_000001_create_onco_tables', 1);
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_25_000001_create_onco_saisie_tables') INSERT INTO migrations (migration, batch) VALUES ('2026_10_25_000001_create_onco_saisie_tables', 1);
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_25_000002_create_onco_prescriptions_table') INSERT INTO migrations (migration, batch) VALUES ('2026_10_25_000002_create_onco_prescriptions_table', 1);

-- 2026_10_25_000003_add_modification_to_onco_notes (déjà appliqué sur oncologie_app le 25/10)
IF COL_LENGTH('onco_notes', 'modifie_par') IS NULL ALTER TABLE onco_notes ADD modifie_par nvarchar(50) NULL, modifie_le datetime2(0) NULL;
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_25_000003_add_modification_to_onco_notes') INSERT INTO migrations (migration, batch) VALUES ('2026_10_25_000003_add_modification_to_onco_notes', 2);

-- 2026_10_26_000001_create_onco_cures_tables (déjà appliqué sur oncologie_app le 26/10) : protocoles prescrits dans l'appli
IF OBJECT_ID('onco_cures') IS NULL BEGIN
create table "onco_cures" ("id" bigint not null identity primary key, "identifiant" nvarchar(20) not null, "num_doss" nvarchar(20) null, "cod_prot" int null, "nom_protocole" nvarchar(150) not null, "poids" decimal(6, 2) null, "taille" decimal(6, 2) null, "bmi" decimal(6, 2) null, "surface_corporelle" decimal(5, 2) null, "nb_cures" smallint null, "intervalle" smallint null, "date_debut" date null, "observation" nvarchar(max) null, "statut" nvarchar(20) not null default 'prescrite', "prescrit_par" nvarchar(50) not null, "prescrit_nom" nvarchar(100) null, "saisi_le" datetime2(0) not null, "statut_par" nvarchar(50) null, "statut_le" datetime2(0) null);
create index "onco_cures_identifiant_index" on "onco_cures" ("identifiant");
create table "onco_cure_lignes" ("id" bigint not null identity primary key, "cure_id" bigint not null, "ordre" smallint not null default '0', "jour" nvarchar(20) null, "medicament" nvarchar(150) not null, "dose_m2" nvarchar(40) null, "dose" nvarchar(80) null, "ajustee" bit not null default '0', "dilution" nvarchar(150) null, "duree" nvarchar(40) null, "horaire" nvarchar(40) null);
alter table "onco_cure_lignes" add constraint "onco_cure_lignes_cure_id_foreign" foreign key ("cure_id") references "onco_cures" ("id");
END;
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_26_000001_create_onco_cures_tables') INSERT INTO migrations (migration, batch) VALUES ('2026_10_26_000001_create_onco_cures_tables', 3);

-- 2026_10_26_000002_fix_onco_notes_modifie_le (déjà appliqué le 26/10) : datetime → datetime2(0)
ALTER TABLE onco_notes ALTER COLUMN modifie_le datetime2(0) NULL;
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_26_000002_fix_onco_notes_modifie_le') INSERT INTO migrations (migration, batch) VALUES ('2026_10_26_000002_fix_onco_notes_modifie_le', 4);

-- 2026_10_26_000003_create_onco_referentiels_tables (déjà appliqué le 26/10) : copies des référentiels Clinisys, remplies par « php artisan onco:referentiels »
IF OBJECT_ID('onco_ref_protocoles') IS NULL BEGIN
create table "onco_ref_protocoles" ("cod_prot" int not null primary key, "nom" nvarchar(150) not null, "actif" bit not null, "medecin" nvarchar(20) null);
create table "onco_ref_protocole_lignes" ("id" bigint not null identity primary key, "cod_prot" int not null, "ordre" int not null default '0', "jour" nvarchar(20) null, "dci" nvarchar(150) null, "posologie" nvarchar(150) null, "dose_totale" nvarchar(10) null, "dose_initiale" nvarchar(150) null, "dilution" nvarchar(255) null, "duree" nvarchar(40) null, "horaire" nvarchar(40) null);
create index "onco_ref_protocole_lignes_cod_prot_index" on "onco_ref_protocole_lignes" ("cod_prot");
create table "onco_ref_medicaments" ("id" bigint not null identity primary key, "source" nvarchar(12) not null, "code" nvarchar(30) null, "libelle" nvarchar(255) not null, "dci" nvarchar(150) null);
create index "onco_ref_medicaments_libelle_index" on "onco_ref_medicaments" ("libelle");
create table "onco_ref_synchro" ("referentiel" nvarchar(40) not null primary key, "lignes" int not null, "par" nvarchar(50) null, "synchro_le" datetime2(0) not null);
END;
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_26_000003_create_onco_referentiels_tables') INSERT INTO migrations (migration, batch) VALUES ('2026_10_26_000003_create_onco_referentiels_tables', 5);
-- 2026_10_26_000004_add_statistiques_to_onco_acces (déjà appliqué le 26/10) : accès aux statistiques paramétré à part
IF COL_LENGTH('onco_acces', 'stats_portee') IS NULL ALTER TABLE onco_acces ADD stats_portee nvarchar(10) NULL;
IF OBJECT_ID('onco_acces_stats_medecins') IS NULL create table "onco_acces_stats_medecins" ("user_name" nvarchar(50) not null, "cod_med" nvarchar(10) not null, constraint "onco_acces_stats_medecins_user_name_cod_med_primary" primary key ("user_name", "cod_med"));
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_26_000004_add_statistiques_to_onco_acces') INSERT INTO migrations (migration, batch) VALUES ('2026_10_26_000004_add_statistiques_to_onco_acces', 6);
-- 2026_10_26_000005_add_ammps_to_onco_ref_medicaments (déjà appliqué le 26/10) : liste marocaine des médicaments (AMMPS), remplie par « php artisan onco:ammps »
IF COL_LENGTH('onco_ref_medicaments', 'ppv') IS NULL ALTER TABLE onco_ref_medicaments ADD dosage nvarchar(100) NULL, forme nvarchar(100) NULL, presentation nvarchar(255) NULL, classe nvarchar(150) NULL, laboratoire nvarchar(150) NULL, statut nvarchar(60) NULL, pp_gn nvarchar(20) NULL, ppv decimal(12, 2) NULL, ph decimal(12, 2) NULL;
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_26_000005_add_ammps_to_onco_ref_medicaments') INSERT INTO migrations (migration, batch) VALUES ('2026_10_26_000005_add_ammps_to_onco_ref_medicaments', 7);
-- 2026_10_27_000001_add_modification_to_onco_prescriptions (déjà appliqué le 27/10) : modification / suppression tracées
IF COL_LENGTH('onco_prescriptions', 'masque_le') IS NULL ALTER TABLE onco_prescriptions ADD modifie_par nvarchar(50) NULL, modifie_le datetime2(0) NULL, masque_par nvarchar(50) NULL, masque_le datetime2(0) NULL;
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_27_000001_add_modification_to_onco_prescriptions') INSERT INTO migrations (migration, batch) VALUES ('2026_10_27_000001_add_modification_to_onco_prescriptions', 8);
-- 2026_10_27_000002_add_chimio_to_onco_ref_protocole_lignes (déjà appliqué le 27/10) : produits de chimio (PC/DT) et SC à 3 décimales
IF COL_LENGTH('onco_ref_protocole_lignes', 'chimio') IS NULL ALTER TABLE onco_ref_protocole_lignes ADD code_art nvarchar(30) NULL, chimio bit NOT NULL DEFAULT 0, dt bit NOT NULL DEFAULT 0, dose_chimio nvarchar(60) NULL;
ALTER TABLE onco_cures ALTER COLUMN surface_corporelle decimal(6, 3) NULL;
IF NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_27_000002_add_chimio_to_onco_ref_protocole_lignes') INSERT INTO migrations (migration, batch) VALUES ('2026_10_27_000002_add_chimio_to_onco_ref_protocole_lignes', 9);