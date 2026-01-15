@echo off
REM Make Windows Hotspot (ICS) resolve a custom domain by adding it to the local hosts file.
REM Phones connected to the hotspot typically use 192.168.137.1 as DNS, and ICS will answer using this mapping.
REM Run as Administrator.

set HOSTS_FILE=%SystemRoot%\System32\drivers\etc\hosts
set ENTRY_IP=192.168.137.1

REM Prefer .test/.lan on phones. .local is special (mDNS) and is NOT reliable.
set NAMES=academia.test www.academia.test

net session >nul 2>&1
if %errorlevel% neq 0 (
  echo.
  echo [!] Ejecuta este archivo como Administrador.
  echo     (Click derecho ^> Ejecutar como administrador)
  echo.
  pause
  exit /b 1
)

echo Actualizando %HOSTS_FILE% ...
for %%H in (%NAMES%) do (
  findstr /I /R /C:"^[ ]*%ENTRY_IP%[ ]\+%%H\([ ]\|$\)" "%HOSTS_FILE%" >nul 2>&1
  if errorlevel 1 (
    echo %ENTRY_IP% %%H>>"%HOSTS_FILE%"
    echo [OK] Agregado: %%H -> %ENTRY_IP%
  ) else (
    echo [OK] Ya existe: %%H
  )
)

echo.
echo Limpiando cache DNS...
ipconfig /flushdns >nul 2>&1

echo.
echo Listo. Ahora en el telefono usa:
echo   http://academia.test/
echo.
pause
