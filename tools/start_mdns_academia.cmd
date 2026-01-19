@echo off
setlocal
REM Start mDNS responder for academia.local (for Android/iPhone)

set SCRIPT=%~dp0mdns_academia.ps1
set LOG_FILE=%~dp0mdns_academia.log

echo.
set /p HOST_IP=Escribe tu IPv4 (ej: 192.168.2.8): 
if "%HOST_IP%"=="" goto :ERR

REM Firewall rules (requires admin)
net session >nul 2>&1
if %errorlevel% neq 0 goto :NOADMIN
netsh advfirewall firewall add rule name="Academia mDNS (UDP 5353)" dir=in action=allow protocol=UDP localport=5353 >nul 2>&1
netsh advfirewall firewall add rule name="Academia Apache (TCP 80)" dir=in action=allow protocol=TCP localport=80 >nul 2>&1
echo [OK] Firewall listo (mDNS 5353 UDP, HTTP 80).
goto :START

:NOADMIN
echo.
echo [!] Ejecuta este archivo como Administrador para abrir el firewall.
echo.

:START

echo.
echo Iniciando mDNS para academia.local ...
echo IP detectada: %HOST_IP%
echo Log: %LOG_FILE%
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -InterfaceIP %HOST_IP% -Hostname academia.local -AnswerIP %HOST_IP% -TtlSeconds 120 -LogQueries 1>>"%LOG_FILE%" 2>>&1

echo.
echo [!] mDNS finalizado o falló. Revisa mensajes arriba.
echo Ver log: %LOG_FILE%
pause

endlocal
exit /b 0

:ERR
echo.
echo [!] IP invalida. Saliendo.
pause
endlocal
exit /b 1
