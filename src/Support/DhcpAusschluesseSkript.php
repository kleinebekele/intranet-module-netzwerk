<?php

namespace Intranet\Modules\Netzwerk\Support;

/**
 * Vorlage des PowerShell-Skripts „Ausschlüsse als Einzel-IPs" (DHCP-Seite).
 * Platzhalter {{…}} füllt DhcpController::ausschluesseSkript(); Werte kommen
 * dort bereits als PowerShell-Literale in einfachen Anführungszeichen an.
 *
 * Bewusst ASCII-Umlaute im Skripttext: Windows PowerShell 5.1 zeigt sonst je
 * nach Konsole Zeichensalat. Nur die Bezeichnungen aus dem Intranet tragen
 * echte Umlaute (die Datei wird mit BOM ausgeliefert).
 */
class DhcpAusschluesseSkript
{
    public const VORLAGE = <<<'PS1'
<#
.SYNOPSIS
    Ausschluesse von {{kopf}} als Einzel-IPs neu anlegen.

.DESCRIPTION
    Erzeugt am {{erzeugt}} im Intranet (Netzwerk -> DHCP), Stand der DHCP-Daten {{stand}}.

    Ersetzt jeden Ausschluss-BEREICH durch einzelne Ausschluesse (Start = Ende).
    Dieselben Adressen bleiben ausgeschlossen - die freigegebenen Adressen (Pool)
    aendern sich nicht. Einzelne Ausschluesse lassen sich in der DHCP-Konsole
    leichter von Hand pflegen.

    Ablauf:
      1. Vergleicht die Ausschluesse auf dem Server mit dem Stand im Intranet
         und bricht bei Abweichung ab, ohne etwas zu aendern.
      2. Sichert den Bereich (Export-DhcpServer) neben dem Skript.
      3. Je Bereich: entfernen, Einzel-IPs anlegen. Scheitert das, wird der
         Bereich wiederhergestellt und abgebrochen.
      4. Prueft, dass danach genau dieselben Adressen ausgeschlossen sind.

    Ausschluesse haben im Windows-DHCP kein Beschreibungsfeld. Die Bezeichnung
    je IP pflegt das Intranet; das Skript gibt sie am Ende als Liste aus.

    Aufruf (PowerShell als Administrator, auf dem DHCP-Server):
        .\skript.ps1 -Probelauf     zeigt nur, was passieren wuerde
        .\skript.ps1                fuehrt aus
#>
param([switch]$Probelauf)

$ErrorActionPreference = 'Stop'
$scope = {{scope}}

# Ausschluesse laut Intranet (zur Kontrolle, Format von-bis)
$erwartet = @(
    {{erwartet}}
)

# Bezeichnung je ausgeschlossener IP laut Intranet ({{anzahl}} Adressen)
$beschreibung = [ordered]@{
{{beschreibung}}
}

function Zahl([string]$ip) {
    $b = ([System.Net.IPAddress]::Parse($ip)).GetAddressBytes()
    [Array]::Reverse($b)
    [BitConverter]::ToUInt32($b, 0)
}

function Ip([uint32]$zahl) {
    $b = [BitConverter]::GetBytes($zahl)
    [Array]::Reverse($b)
    ([System.Net.IPAddress]::new($b)).ToString()
}

function Ausgeschlossen {
    $menge = New-Object 'System.Collections.Generic.SortedSet[uint32]'
    foreach ($r in @(Get-DhcpServerv4ExclusionRange -ScopeId $scope)) {
        $ende = Zahl "$($r.EndRange)"
        for ($z = Zahl "$($r.StartRange)"; $z -le $ende; $z++) { [void]$menge.Add($z) }
    }
    , $menge
}

# 1) Stimmt der Stand auf dem Server noch?
$ist = @(Get-DhcpServerv4ExclusionRange -ScopeId $scope | ForEach-Object { "$($_.StartRange)-$($_.EndRange)" })
$abweichung = @()
if ($erwartet.Count -gt 0 -and $ist.Count -gt 0) {
    $abweichung = @(Compare-Object -ReferenceObject $erwartet -DifferenceObject $ist)
} elseif ($erwartet.Count -ne $ist.Count) {
    $abweichung = @('Anzahl')
}
if ($abweichung.Count -gt 0) {
    Write-Host 'Die Ausschluesse auf dem Server weichen vom Stand im Intranet ab:' -ForegroundColor Red
    foreach ($a in $abweichung) {
        if ($a -is [string]) { continue }
        $seite = if ($a.SideIndicator -eq '<=') { 'nur im Intranet:   ' } else { 'nur auf dem Server:' }
        Write-Host ('  {0} {1}' -f $seite, $a.InputObject)
    }
    Write-Host 'Nichts geaendert. DHCP-Seite im Intranet neu laden (neue Daten alle 15 Minuten) und das Skript neu erzeugen.' -ForegroundColor Red
    exit 1
}

$vorher = Ausgeschlossen
$bereiche = @(Get-DhcpServerv4ExclusionRange -ScopeId $scope | Where-Object { "$($_.StartRange)" -ne "$($_.EndRange)" })
Write-Host ('{0} Bereich(e) werden zu Einzel-IPs, {1} Adressen bleiben ausgeschlossen.' -f $bereiche.Count, $vorher.Count)
if ($bereiche.Count -eq 0) {
    Write-Host 'Nichts zu tun - alle Ausschluesse sind schon Einzel-IPs.' -ForegroundColor Green
    exit 0
}

# 2) Sicherung
$sicherung = $null
if (-not $Probelauf) {
    $sicherung = Join-Path $PSScriptRoot ('dhcp-sicherung-{0:yyyyMMdd-HHmmss}.xml' -f (Get-Date))
    Export-DhcpServer -File $sicherung -ScopeId $scope -Leases -Force
    Write-Host "Sicherung: $sicherung"
}

# 3) Je Bereich: entfernen, Einzel-IPs anlegen
foreach ($r in $bereiche) {
    $von = "$($r.StartRange)"
    $bis = "$($r.EndRange)"
    $ende = Zahl $bis
    $ips = @(for ($z = Zahl $von; $z -le $ende; $z++) { Ip $z })
    Write-Host ('  {0,-15} - {1,-15} ->  {2} Einzel-IPs' -f $von, $bis, $ips.Count)
    if ($Probelauf) { continue }

    Remove-DhcpServerv4ExclusionRange -ScopeId $scope -StartRange $von -EndRange $bis
    $angelegt = @()
    try {
        foreach ($ip in $ips) {
            Add-DhcpServerv4ExclusionRange -ScopeId $scope -StartRange $ip -EndRange $ip
            $angelegt += $ip
        }
    } catch {
        Write-Host ('FEHLER bei {0} - {1}: {2}' -f $von, $bis, $_.Exception.Message) -ForegroundColor Red
        foreach ($ip in $angelegt) {
            Remove-DhcpServerv4ExclusionRange -ScopeId $scope -StartRange $ip -EndRange $ip -ErrorAction SilentlyContinue
        }
        Add-DhcpServerv4ExclusionRange -ScopeId $scope -StartRange $von -EndRange $bis
        Write-Host ('Bereich {0} - {1} wiederhergestellt. Abbruch. Sicherung: {2}' -f $von, $bis, $sicherung) -ForegroundColor Red
        exit 1
    }
}

# 4) Gegenprobe
if ($Probelauf) {
    Write-Host 'Probelauf - nichts geaendert.' -ForegroundColor Yellow
} else {
    $nachher = Ausgeschlossen
    if ($nachher.SetEquals($vorher)) {
        Write-Host ('Geprueft: dieselben {0} Adressen ausgeschlossen, Pool unveraendert.' -f $nachher.Count) -ForegroundColor Green
    } else {
        Write-Host ('ACHTUNG: Die ausgeschlossenen Adressen weichen ab! Sicherung: {0}' -f $sicherung) -ForegroundColor Red
        exit 1
    }
}

Write-Host ''
Write-Host 'Ausgeschlossene Adressen laut Intranet:'
foreach ($e in $beschreibung.GetEnumerator()) {
    Write-Host ('  {0,-15} {1}' -f $e.Key, $e.Value)
}
PS1;
}
