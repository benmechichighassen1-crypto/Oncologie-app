@echo off
rem ============================================================
rem  Recopie les protocoles de chimiotherapie et la liste des
rem  medicaments (DCI, pharmacie) depuis Clinisys vers oncologie_app.
rem  A lancer apres un nouveau parametrage dans Clinisys, ou a
rem  programmer chaque nuit dans le Planificateur de taches Windows.
rem  Meme effet que le bouton "Mettre a jour depuis Clinisys" (ecran Acces).
rem ============================================================
cd /d "%~dp0"
set PHP=php
if exist "D:\Outils\php\php.exe" set PHP=D:\Outils\php\php.exe
if exist "D:\Apps\php\php.exe" set PHP=D:\Apps\php\php.exe
rem Serveur IIS : PHP de l'appli a cote du dossier oncologie-app
if exist "%~dp0..\php-oncologie\php.exe" set PHP=%~dp0..\php-oncologie\php.exe
"%PHP%" artisan onco:referentiels
