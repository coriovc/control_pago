@echo off
chcp 65001 >nul
title Servidor Local - LiquidGlass Agency OS
echo =========================================================================
echo    SISTEMA DE CONTROL DE PAGOS, ENTREGABLES E IGUALAS — LIQUIDGLASS
echo =========================================================================
echo.

set PHP_EXE=

where php >nul 2>&1
if %ERRORLEVEL% equ 0 (
    set PHP_EXE=php
    goto :START_SERVER
)

if exist "C:\xampp\php\php.exe" (
    set "PHP_EXE=C:\xampp\php\php.exe"
    goto :START_SERVER
)

for /d %%D in ("C:\laragon\bin\php\php-*") do (
    if exist "%%D\php.exe" (
        set "PHP_EXE=%%D\php.exe"
        goto :START_SERVER
    )
)

for /d %%D in ("C:\wamp64\bin\php\php*") do (
    if exist "%%D\php.exe" (
        set "PHP_EXE=%%D\php.exe"
        goto :START_SERVER
    )
)

if exist "C:\Program Files\PHP\php.exe" (
    set "PHP_EXE=C:\Program Files\PHP\php.exe"
    goto :START_SERVER
)

echo [!] No se encontró php.exe en las rutas habituales de XAMPP / Laragon ni en PATH.
echo.
echo Para conectar con la Base de Datos MySQL:
echo 1. Si tienes XAMPP instalado, asegúrate de activar Apache y MySQL en el panel.
echo 2. Puedes colocar esta carpeta dentro de C:\xampp\htdocs\control_pago\
echo 3. Y abrir en tu navegador: http://localhost/control_pago/
echo.
echo Abriendo página en modo local...
start "" "%~dp0index.html"
pause
exit /b

:START_SERVER
echo [OK] PHP detectado en: %PHP_EXE%
echo [OK] Iniciando servidor en http://localhost:8000 ...
echo [OK] Abriendo navegador...
start http://localhost:8000
echo.
echo Presiona Ctrl+C en esta ventana para detener el servidor.
echo -------------------------------------------------------------------------
"%PHP_EXE%" -S localhost:8000
pause
