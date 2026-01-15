param(
  [string]$ListenIP = "0.0.0.0",
  [int]$Port = 53,
  [string]$Hostname = "academia.local",
  [string]$AnswerIP = "10.235.31.79",
  [int]$TtlSeconds = 60,
  [string]$UpstreamDns = "",
  [int]$UpstreamTimeoutMs = 1500,
  [switch]$TryOpenFirewall,
  [switch]$LogQueries
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Convert-IpToBytes([string]$ip) {
  $addr = [System.Net.IPAddress]::Parse($ip)
  $bytes = $addr.GetAddressBytes()
  if ($bytes.Length -ne 4) { throw "Only IPv4 supported. Got: $ip" }
  return $bytes
}

function Read-UInt16BE([byte[]]$buf, [int]$offset) {
  return ($buf[$offset] -shl 8) -bor $buf[$offset + 1]
}

function Write-UInt16BE([byte[]]$buf, [int]$offset, [int]$value) {
  $buf[$offset] = [byte](($value -shr 8) -band 0xFF)
  $buf[$offset + 1] = [byte]($value -band 0xFF)
}

function Decode-Qname([byte[]]$buf, [int]$offset) {
  $labels = New-Object System.Collections.Generic.List[string]
  $i = $offset
  while ($true) {
    if ($i -ge $buf.Length) { throw "Invalid QNAME" }
    $len = [int]$buf[$i]
    $i++
    if ($len -eq 0) { break }
    if (($len -band 0xC0) -ne 0) { throw "Compression not supported in queries" }
    if ($i + $len -gt $buf.Length) { throw "Invalid QNAME length" }
    $labels.Add([System.Text.Encoding]::ASCII.GetString($buf, $i, $len))
    $i += $len
  }
  return [pscustomobject]@{
    Name = ($labels -join '.')
    NextOffset = $i
  }
}

function Build-ServFail([byte[]]$query) {
  if ($query.Length -lt 12) { return $null }
  $id = Read-UInt16BE $query 0
  $q = Decode-Qname $query 12
  $qoff = $q.NextOffset
  if ($qoff + 4 -gt $query.Length) { return $null }
  $questionEnd = $qoff + 4
  $questionLen = $questionEnd - 12
  $respLen = 12 + $questionLen
  $resp = New-Object byte[] $respLen
  Write-UInt16BE $resp 0 $id
  Write-UInt16BE $resp 2 0x8182  # SERVFAIL
  Write-UInt16BE $resp 4 1
  Write-UInt16BE $resp 6 0
  Write-UInt16BE $resp 8 0
  Write-UInt16BE $resp 10 0
  [Array]::Copy($query, 12, $resp, 12, $questionLen)
  return $resp
}

function Try-BuildOverrideResponse([byte[]]$query, [string[]]$hostnames, [byte[]]$answerBytes, [int]$ttlSeconds) {
  if ($query.Length -lt 12) { return $null }

  $id = Read-UInt16BE $query 0
  # flags in query at offset 2 (ignored)
  $qdcount = Read-UInt16BE $query 4
  if ($qdcount -lt 1) { return $null }

  $q = Decode-Qname $query 12
  $qname = $q.Name.ToLowerInvariant()
  $qoff = $q.NextOffset
  if ($qoff + 4 -gt $query.Length) { return $null }

  $qtype = Read-UInt16BE $query $qoff
  $qclass = Read-UInt16BE $query ($qoff + 2)

  $wants = @()
  foreach ($h in $hostnames) {
    $hh = ("$h").Trim()
    if ($hh -ne '') {
      $wants += $hh.TrimEnd('.').ToLowerInvariant()
    }
  }
  $isA = ($qtype -eq 1)
  $isIN = ($qclass -eq 1)

  $isMatch = ($isA -and $isIN -and ($wants -contains $qname))

  # Copy question section exactly (from byte 12 to end of question)
  $questionEnd = $qoff + 4
  $questionLen = $questionEnd - 12

  if (-not $isMatch) { return $null }

  # Answer section: NAME pointer to 0x0c, TYPE A, CLASS IN, TTL, RDLEN 4, RDATA
  $answerLen = 2 + 2 + 2 + 4 + 2 + 4
  $respLen = 12 + $questionLen + $answerLen
  $resp = New-Object byte[] $respLen

  Write-UInt16BE $resp 0 $id
  Write-UInt16BE $resp 2 0x8180  # standard response, no error
  Write-UInt16BE $resp 4 1       # QDCOUNT
  Write-UInt16BE $resp 6 1       # ANCOUNT
  Write-UInt16BE $resp 8 0
  Write-UInt16BE $resp 10 0

  [Array]::Copy($query, 12, $resp, 12, $questionLen)

  $aoff = 12 + $questionLen
  # NAME: pointer to 0x0c
  $resp[$aoff] = 0xC0
  $resp[$aoff + 1] = 0x0C
  Write-UInt16BE $resp ($aoff + 2) 1   # TYPE A
  Write-UInt16BE $resp ($aoff + 4) 1   # CLASS IN
  # TTL
  $ttl = [uint32]$ttlSeconds
  $resp[$aoff + 6] = [byte](($ttl -shr 24) -band 0xFF)
  $resp[$aoff + 7] = [byte](($ttl -shr 16) -band 0xFF)
  $resp[$aoff + 8] = [byte](($ttl -shr 8) -band 0xFF)
  $resp[$aoff + 9] = [byte]($ttl -band 0xFF)
  Write-UInt16BE $resp ($aoff + 10) 4  # RDLENGTH
  [Array]::Copy($answerBytes, 0, $resp, $aoff + 12, 4)

  return $resp
}

function Get-UpstreamServer([string]$explicit) {
  if ($explicit -and $explicit.Trim() -ne '') { return $explicit.Trim() }
  try {
    $servers = Get-DnsClientServerAddress -AddressFamily IPv4 | ForEach-Object { $_.ServerAddresses } | Where-Object { $_ -and $_ -ne '192.168.137.1' } | Select-Object -First 1
    if ($servers) { return [string]$servers }
  } catch {
    # ignore
  }
  return '8.8.8.8'
}

function Forward-Dns([byte[]]$query, [string]$upstream, [int]$timeoutMs) {
  $client = New-Object System.Net.Sockets.UdpClient
  $client.Client.ReceiveTimeout = $timeoutMs
  $client.Connect($upstream, 53)
  [void]$client.Send($query, $query.Length)
  $remote = New-Object System.Net.IPEndPoint ([System.Net.IPAddress]::Any, 0)
  $resp = $client.Receive([ref]$remote)
  $client.Dispose()
  return $resp
}

function Try-GetQueryInfo([byte[]]$query) {
  try {
    if ($query.Length -lt 12) { return $null }
    $q = Decode-Qname $query 12
    $qoff = $q.NextOffset
    if ($qoff + 4 -gt $query.Length) { return $null }
    $qtype = Read-UInt16BE $query $qoff
    $qclass = Read-UInt16BE $query ($qoff + 2)
    return [pscustomobject]@{
      Name = $q.Name
      Type = $qtype
      Class = $qclass
    }
  } catch {
    return $null
  }
}

if ($TryOpenFirewall) {
  try {
    # Requires Admin. If it fails we just continue.
    New-NetFirewallRule -DisplayName "MiniDNS ($Hostname)" -Direction Inbound -Action Allow -Protocol UDP -LocalPort $Port -Profile Private -ErrorAction Stop | Out-Null
    New-NetFirewallRule -DisplayName "MiniDNS TCP ($Hostname)" -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -Profile Private -ErrorAction Stop | Out-Null
    Write-Host "Firewall rules added for port $Port." -ForegroundColor Green
  } catch {
    Write-Host "Could not add firewall rules (run PowerShell as Administrator if needed)." -ForegroundColor Yellow
  }
}

$answerBytes = Convert-IpToBytes $AnswerIP
$hostnames = @($Hostname.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
$upstream = Get-UpstreamServer $UpstreamDns
$endpoint = New-Object System.Net.IPEndPoint ([System.Net.IPAddress]::Parse($ListenIP), $Port)
$udp = New-Object System.Net.Sockets.UdpClient
$udp.Client.SetSocketOption([System.Net.Sockets.SocketOptionLevel]::Socket, [System.Net.Sockets.SocketOptionName]::ReuseAddress, $true)
$udp.Client.Bind($endpoint)

Write-Host ("Mini DNS running on {0}:{1}" -f $ListenIP, $Port) -ForegroundColor Green
Write-Host ("Override: A {0} -> {1} (TTL={2})" -f ($hostnames -join ', '), $AnswerIP, $TtlSeconds) -ForegroundColor Green
Write-Host ("Upstream DNS: {0}:53 (timeout={1}ms)" -f $upstream, $UpstreamTimeoutMs) -ForegroundColor Green
Write-Host "Press Ctrl+C to stop." -ForegroundColor DarkGray

while ($true) {
  $remote = New-Object System.Net.IPEndPoint ([System.Net.IPAddress]::Any, 0)
  $bytes = $udp.Receive([ref]$remote)
  try {
    if ($LogQueries) {
      $info = Try-GetQueryInfo -query $bytes
      if ($info) {
        Write-Host ("{0} -> Q {1} (TYPE={2}, CLASS={3})" -f $remote.Address, $info.Name, $info.Type, $info.Class) -ForegroundColor DarkGray
      } else {
        Write-Host ("{0} -> Q <unparsed> ({1} bytes)" -f $remote.Address, $bytes.Length) -ForegroundColor DarkGray
      }
    }
    $resp = Try-BuildOverrideResponse -query $bytes -hostnames $hostnames -answerBytes $answerBytes -ttlSeconds $TtlSeconds
    if ($null -eq $resp) {
      try {
        $resp = Forward-Dns -query $bytes -upstream $upstream -timeoutMs $UpstreamTimeoutMs
      } catch {
        $resp = Build-ServFail -query $bytes
      }
    }
    if ($null -ne $resp) { [void]$udp.Send($resp, $resp.Length, $remote) }
  } catch {
    # Ignore malformed packets
  }
}
