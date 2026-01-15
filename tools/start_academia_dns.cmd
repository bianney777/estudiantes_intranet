@echo off
REM Start the mini DNS proxy for the Windows hotspot (ICS)
REM Requires: PowerShell

REM NOTE:
REM Windows Hotspot (ICS) already runs a DNS proxy on 192.168.137.1:53.
REM On many machines you cannot reliably replace it.
REM The most reliable approach for phones is:
REM   1) Run tools\setup_hotspot_domain.cmd as Administrator (adds academia.test -> 192.168.137.1)
REM   2) On the phone open: http://academia.test/
REM If you specifically want academia.local on phones, try mDNS instead:
REM   tools\start_mdns_academia.cmd  (then open http://academia.local/)

set LISTEN_IP=192.168.137.1
set DNS_PORT=53
set HOSTS=academia.test, www.academia.test, academia.local, www.academia.local
set ANSWER_IP=192.168.137.1

set LOG_QUERIES=
if /I "%~1"=="--log" set LOG_QUERIES=-LogQueries

set SCRIPT=%~dp0mini_dns.ps1

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

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -ListenIP %LISTEN_IP% -Port %DNS_PORT% -Hostname "%HOSTS%" -AnswerIP %ANSWER_IP% -TtlSeconds 60
