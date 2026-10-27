@echo off
rem ============================================================
rem  Mise a jour de l'application Oncologie
rem  A lancer (double-clic) apres chaque livraison de code :
rem   1. ajoute les nouvelles tables / colonnes dans oncologie_app
rem   2. recopie protocoles et medicaments depuis Clinisys
rem   3. reconstruit les caches de l'application (plus rapide)
rem  Rien n'est jamais ecrit dans Clinisys.
rem ============================================================
cd /d "%~dp0"
set PHP=php
if exist "D:\Outils\php\php.exe" set PHP=D:\Outils\php\php.exe
if exist "D:\Apps\php\php.exe" set PHP=D:\Apps\php\php.exe
rem Serveur IIS : PHP de l'appli a cote du dossier oncologie-app
if exist "%~dp0..\php-oncologie\php.exe" set PHP=%~dp0..\php-oncologie\php.exe

echo.
echo [1/3] Base oncologie_app : nouvelles tables et colonnes...
"%PHP%" artisan migrate --force || goto erreur

echo.
echo [2/3] Copie des protocoles et medicaments depuis Clinisys...
"%PHP%" artisan onco:referentiels || goto erreur

echo.
echo [3/3] Caches de l'application...
"%PHP%" artisan optimize:clear >nul
"%PHP%" artisan optimize || goto erreur

echo.
echo Mise a jour terminee. Rechargez les pages dans le navigateur.
pause
exit /b 0

:erreur
echo.
echo ERREUR pendant la mise a jour : envoyez une capture de cette fenetre.
pause
exit /b 1
