@echo off
rem ============================================================
rem  Installation de l'application Oncologie sur IIS.
rem  Clic droit > Executer en tant qu'administrateur.
rem  Ce fichier doit etre a cote des dossiers oncologie-app et
rem  php-oncologie (peu importe le dossier du serveur).
rem ============================================================
set ICI=%~dp0
powershell -NoProfile -ExecutionPolicy Bypass -File "%ICI%oncologie-app\deploiement\installer-iis.ps1" -Appli "%ICI%oncologie-app" -Php "%ICI%php-oncologie"
echo.
pause
