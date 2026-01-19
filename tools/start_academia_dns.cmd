@echo off
REM Start the mini DNS proxy for the Windows hotspot (ICS)
REM Requires: PowerShell

REM NOTE:
REM This script runs a local DNS responder for LAN (non-hotspot) use.
REM It auto-detects the PC IPv4 and answers academia.local/academia.test.

set DNS_PORT=53
set HOSTS=academia.test, www.academia.test, academia.local, www.academia.local
set LISTEN_IP=
set ANSWER_IP=

set LOG_QUERIES=
if /I "%~1"=="--log" set LOG_QUERIES=-LogQueries

set SCRIPT=%~dp0mini_dns.ps1

REM Auto-detect IPv4 (first non-APIPA, non-loopback)
for /f "usebackq delims=" %%I in (`powershell -NoProfile -Command "Get-NetIPAddress -AddressFamily IPv4 ^| Where-Object { $_.IPAddress -notlike '169.254*' -and $_.IPAddress -ne '127.0.0.1' } ^| Select-Object -First 1 -ExpandProperty IPAddress"`) do set LISTEN_IP=%%I
set ANSWER_IP=%LISTEN_IP%

if "%LISTEN_IP%"=="" (
	echo.
	echo [!] No se pudo detectar tu IP local.
	echo     Tip: ejecuta ipconfig y usa la IPv4 del Wi-Fi.
	echo.
	pause
	exit /b 1
)

REM If this is not run as Admin, Windows Firewall will often block inbound DNS.
net session >nul 2>&1
if %errorlevel% neq 0 (
	echo.
	echo [!] Ejecuta este CMD como Administrador para abrir el firewall (DNS/HTTP).
	echo     - DNS: UDP/TCP %DNS_PORT%
	echo     - HTTP: TCP 80
	echo.
) else (
	REM Add/refresh firewall rules (ignore errors if rules already exist)
	netsh advfirewall firewall add rule name="Academia MiniDNS (UDP 53)" dir=in action=allow protocol=UDP localport=%DNS_PORT% >nul 2>&1
	netsh advfirewall firewall add rule name="Academia MiniDNS (TCP 53)" dir=in action=allow protocol=TCP localport=%DNS_PORT% >nul 2>&1
	netsh advfirewall firewall add rule name="Academia Apache (TCP 80)" dir=in action=allow protocol=TCP localport=80 >nul 2>&1

	echo [OK] Firewall listo (DNS %DNS_PORT% UDP/TCP, HTTP 80).
)

echo.
echo Iniciando DNS en %LISTEN_IP%:%DNS_PORT% para: %HOSTS%
echo Respuesta A -> %ANSWER_IP%
echo.

set LOG_FILE=%~dp0academia_dns.log
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -ListenIP %LISTEN_IP% -Port %DNS_PORT% -Hostname "%HOSTS%" -AnswerIP %ANSWER_IP% -TtlSeconds 60 1>>"%LOG_FILE%" 2>>&1

echo.
echo [!] DNS finalizado o falló. Ver log: %LOG_FILE%
pause
