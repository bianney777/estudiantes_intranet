@echo off
REM Start mDNS responder for academia.local (for Android/iPhone)
REM If possible, run as Administrator to open firewall for UDP 5353.

set SCRIPT=%~dp0mdns_academia.ps1

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
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -InterfaceIP 192.168.137.1 -Hostname academia.local -AnswerIP 192.168.137.1 -TtlSeconds 120 -LogQueries
