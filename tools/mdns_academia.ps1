param(
  [string]$InterfaceIP = "192.168.137.1",
  [string]$Hostname = "academia.local",
  [string]$AnswerIP = "192.168.137.1",
  [int]$TtlSeconds = 120,
  [switch]$LogQueries
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$MDNS_MULTICAST = [System.Net.IPAddress]::Parse('224.0.0.251')
$MDNS_PORT = 5353

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
    if (($len -band 0xC0) -ne 0) {
      throw "Compression not supported in query name"
    }
    if ($i + $len -gt $buf.Length) { throw "Invalid QNAME length" }
    $labels.Add([System.Text.Encoding]::ASCII.GetString($buf, $i, $len))
    $i += $len
  }
  return [pscustomobject]@{ Name = ($labels -join '.'); NextOffset = $i }
}

function Try-BuildMdnsResponse([byte[]]$query, [string]$hostname, [byte[]]$answerBytes, [int]$ttlSeconds) {
  if ($query.Length -lt 12) { return $null }

  $id = Read-UInt16BE $query 0
  $qdcount = Read-UInt16BE $query 4
  if ($qdcount -lt 1) { return $null }

  $q = Decode-Qname $query 12
  $qname = $q.Name.TrimEnd('.').ToLowerInvariant()
  $qoff = $q.NextOffset
  if ($qoff + 4 -gt $query.Length) { return $null }

  $qtype = Read-UInt16BE $query $qoff
  $qclass = Read-UInt16BE $query ($qoff + 2)

  $want = $hostname.TrimEnd('.').ToLowerInvariant()
  # mDNS can set the QU bit (0x8000) in class; mask it out
  $classMasked = ($qclass -band 0x7FFF)
  $isIN = ($classMasked -eq 1)

  if (-not ($isIN -and ($qname -eq $want))) { return $null }

  $questionEnd = $qoff + 4
  $questionLen = $questionEnd - 12

  # Android/iPhone often query HTTPS/SVCB (QTYPE 65/64) first. Some clients don't fallback quickly.
  # For a local dev hotspot, it's more reliable to always answer with an A record when the name matches,
  # even if the requested QTYPE isn't A.

  # Answer section: NAME ptr (0xC00C), TYPE A, CLASS IN + cache-flush (0x8001), TTL, RDLEN 4, RDATA
  $answerLen = 2 + 2 + 2 + 4 + 2 + 4
  $respLen = 12 + $questionLen + $answerLen
  $resp = New-Object byte[] $respLen

  Write-UInt16BE $resp 0 $id
  # Flags: response + authoritative (mDNS) => 0x8400
  Write-UInt16BE $resp 2 0x8400
  Write-UInt16BE $resp 4 1
  Write-UInt16BE $resp 6 1
  Write-UInt16BE $resp 8 0
  Write-UInt16BE $resp 10 0

  [Array]::Copy($query, 12, $resp, 12, $questionLen)

  $aoff = 12 + $questionLen
  $resp[$aoff] = 0xC0
  $resp[$aoff + 1] = 0x0C
  Write-UInt16BE $resp ($aoff + 2) 1
  # CLASS IN + cache flush bit
  Write-UInt16BE $resp ($aoff + 4) 0x8001

  $ttl = [uint32]$ttlSeconds
  $resp[$aoff + 6] = [byte](($ttl -shr 24) -band 0xFF)
  $resp[$aoff + 7] = [byte](($ttl -shr 16) -band 0xFF)
  $resp[$aoff + 8] = [byte](($ttl -shr 8) -band 0xFF)
  $resp[$aoff + 9] = [byte]($ttl -band 0xFF)

  Write-UInt16BE $resp ($aoff + 10) 4
  [Array]::Copy($answerBytes, 0, $resp, $aoff + 12, 4)
  return $resp
}

$answerBytes = Convert-IpToBytes $AnswerIP
$ifaceAddr = [System.Net.IPAddress]::Parse($InterfaceIP)

# Bind UDP 5353 with reuse (mDNS is multicast and commonly shared)
$udp = New-Object System.Net.Sockets.UdpClient
$udp.ExclusiveAddressUse = $false
$udp.Client.SetSocketOption([System.Net.Sockets.SocketOptionLevel]::Socket, [System.Net.Sockets.SocketOptionName]::ReuseAddress, $true)
$udp.Client.Bind((New-Object System.Net.IPEndPoint ([System.Net.IPAddress]::Any, $MDNS_PORT)))

try {
  $udp.JoinMulticastGroup($MDNS_MULTICAST, $ifaceAddr)
} catch {
  Write-Host "WARNING: Could not join multicast group on $InterfaceIP. mDNS may not work." -ForegroundColor Yellow
}

Write-Host "mDNS responder running: $Hostname -> $AnswerIP" -ForegroundColor Green
Write-Host "Interface: $InterfaceIP, Multicast: 224.0.0.251:$MDNS_PORT, TTL=$TtlSeconds" -ForegroundColor Green
Write-Host "Try on phone: http://$Hostname/" -ForegroundColor DarkGray
Write-Host "Press Ctrl+C to stop." -ForegroundColor DarkGray

while ($true) {
  $remote = New-Object System.Net.IPEndPoint ([System.Net.IPAddress]::Any, 0)
  $bytes = $udp.Receive([ref]$remote)
  try {
    if ($LogQueries) {
      try {
        $q = Decode-Qname $bytes 12
        $qoff = $q.NextOffset
        $qtype = if ($qoff + 2 -le $bytes.Length) { Read-UInt16BE $bytes $qoff } else { -1 }
        Write-Host ("{0} -> Q {1} (TYPE={2})" -f $remote.Address, $q.Name, $qtype) -ForegroundColor DarkGray
      } catch {
        Write-Host ("{0} -> Q <unparsed> ({1} bytes)" -f $remote.Address, $bytes.Length) -ForegroundColor DarkGray
      }
    }

    $resp = Try-BuildMdnsResponse -query $bytes -hostname $Hostname -answerBytes $answerBytes -ttlSeconds $TtlSeconds
    if ($null -ne $resp) {
      # If QU is requested, client expects unicast reply; unicast is ok for most clients.
      [void]$udp.Send($resp, $resp.Length, $remote)
    }
  } catch {
    # ignore malformed
  }
}
