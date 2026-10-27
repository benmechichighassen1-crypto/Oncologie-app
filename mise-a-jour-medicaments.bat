@echo off
rem ============================================================
rem  Recharge la Liste marocaine des medicaments publiee par
rem  l'AMMPS (ministere de la Sante) dans oncologie_app.
rem  Le site n'offre pas de fichier : ~500 pages sont relues une a
rem  une (10 a 15 minutes). A lancer une fois par mois environ.
rem  Si la lecture echoue, la liste actuelle est conservee.
rem ============================================================
cd /d "%~dp0"
set PHP=php
if exist "D:\Outils\php\php.exe" set PHP=D:\Outils\php\php.exe
if exist "D:\Apps\php\php.exe" set PHP=D:\Apps\php\php.exe
rem Serveur IIS : PHP de l'appli a cote du dossier oncologie-app
if exist "%~dp0..\php-oncologie\php.exe" set PHP=%~dp0..\php-oncologie\php.exe

rem Autorites de certification de Windows, pour que PHP accepte le HTTPS du reseau.
powershell -NoProfile -Command "$sb=New-Object Text.StringBuilder; $c=@(Get-ChildItem Cert:\LocalMachine\Root)+@(Get-ChildItem Cert:\CurrentUser\Root)+@(Get-ChildItem Cert:\LocalMachine\CA) | Sort-Object Thumbprint -Unique; foreach($x in $c){[void]$sb.AppendLine('-----BEGIN CERTIFICATE-----');[void]$sb.AppendLine([Convert]::ToBase64String($x.RawData,'InsertLineBreaks'));[void]$sb.AppendLine('-----END CERTIFICATE-----')}; [IO.File]::WriteAllText('storage\app\certificats-windows.pem',$sb.ToString())"

"%PHP%" artisan onco:ammps
pause
