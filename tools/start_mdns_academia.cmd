@echo off
REM Start mDNS responder for academia.local (for Android/iPhone)
REM If possible, run as Administrator to open firewall for UDP 5353.

set SCRIPT=%~dp0mdns_academia.ps1

REM Auto-detect active IPv4 (default route interface)
set HOST_IP=
for /f "usebackq delims=" %%I in (`powershell -NoProfile -Command "$r=Get-NetRoute -DestinationPrefix '0.0.0.0/0' ^| Sort-Object RouteMetric ^| Select-Object -First 1; if($null -eq $r){exit 1}; $ip=(Get-NetIPAddress -InterfaceIndex $r.InterfaceIndex -AddressFamily IPv4 ^| Where-Object { $_.IPAddress -notlike '169.254*' -and $_.IPAddress -ne '127.0.0.1' } ^| Select-Object -First 1).IPAddress; if(-not $ip){exit 1}; Write-Output $ip"`) do set HOST_IP=%%I

if "%HOST_IP%"=="" (
	echo.
	echo [!] No se pudo detectar tu IP local.
	echo     Tip: ejecuta y busca tu IPv4: ipconfig
	echo.
	pause
	exit /b 1
)

net session >nul 2>&1
if %errorlevel% neq 0 (
	echo.
	echo [!] Tip: Ejecuta como Administrador para abrir firewall (UDP 5353).
	echo.
) else (
	netsh advfirewall firewall add rule name="Academia mDNS (UDP 5353)" dir=in action=allow protocol=UDP localport=5353 >nul 2>&1
	netsh advfirewall firewall add rule name="Academia Apache (TCP 80)" dir=in action=allow protocol=TCP localport=80 >nul 2>&1
	echo [OK] Firewall listo (mDNS 5353 UDP, HTTP 80).
)

echo.
echo Iniciando mDNS para academia.local ...
echo IP detectada: %HOST_IP%
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -InterfaceIP %HOST_IP% -Hostname academia.local -AnswerIP %HOST_IP% -TtlSeconds 120 -LogQueries
