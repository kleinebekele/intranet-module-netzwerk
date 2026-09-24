<#
.SYNOPSIS
    Schickt Auslastung und Belegungen des DHCP-Servers an das Intranet
    (Netzwerk -> DHCP).

.DESCRIPTION
    Laeuft auf dem Windows-DHCP-Server. Je Bereich (Scope) gehen mit:
    Zaehlwerte, Grenzen, Ausschluesse, Reservierungen und alle aktiven Leases
    (IP, MAC, Geraetename, Ablauf). Ziel ist der Webhook-Eingang des Intranets;
    der Task Netzwerk/Dhcp uebernimmt die Daten alle 5 Minuten.

    Einmalig einrichten (PowerShell als Administrator):
        .\dhcp-statistik.ps1 -Url https://<intranet>/webhooks/ekkon/<schluessel> -Einrichten
    legt die geplante Aufgabe "Intranet DHCP-Statistik" an (alle 15 Minuten, als SYSTEM)
    und schickt sofort eine erste Messung.

    Fehler landen in dhcp-statistik.log neben dem Skript.

.PARAMETER Url
    Webhook-URL aus Intranet -> Ekkon -> Webhook-Eingang (Quelle "DHCP").
#>
param(
    [Parameter(Mandatory = $true)][string]$Url,
    [switch]$Einrichten
)

$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'dhcp-statistik.log'

function Schreibe-Log([string]$text) {
    $zeile = '{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $text
    Add-Content -Path $log -Value $zeile -Encoding UTF8
    # Nur die letzten 500 Zeilen behalten
    $alle = Get-Content -Path $log -Encoding UTF8
    if ($alle.Count -gt 500) { $alle | Select-Object -Last 500 | Set-Content -Path $log -Encoding UTF8 }
}

if ($Einrichten) {
    $aktion = New-ScheduledTaskAction -Execute 'powershell.exe' `
        -Argument ('-NoProfile -ExecutionPolicy Bypass -File "{0}" -Url "{1}"' -f $PSCommandPath, $Url)
    $ausloeser = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 15)
    $konto = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $einst = New-ScheduledTaskSettingsSet -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -StartWhenAvailable -MultipleInstances IgnoreNew
    Register-ScheduledTask -TaskName 'Intranet DHCP-Statistik' -Action $aktion -Trigger $ausloeser -Principal $konto -Settings $einst -Force | Out-Null
    Write-Host 'Geplante Aufgabe "Intranet DHCP-Statistik" eingerichtet (alle 15 Minuten).'
}

try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

    $bereiche = @()
    foreach ($s in Get-DhcpServerv4Scope) {
        $id = $s.ScopeId
        $st = Get-DhcpServerv4ScopeStatistics -ScopeId $id

        $ausschluesse = @(Get-DhcpServerv4ExclusionRange -ScopeId $id -ErrorAction SilentlyContinue | ForEach-Object {
            @{ von = "$($_.StartRange)"; bis = "$($_.EndRange)" }
        })

        $reservierungen = @(Get-DhcpServerv4Reservation -ScopeId $id -ErrorAction SilentlyContinue | ForEach-Object {
            @{ ip = "$($_.IPAddress)"; mac = "$($_.ClientId)"; name = "$($_.Name)"; beschreibung = "$($_.Description)" }
        })

        # Nur, was gerade ein Geraet hat (Active, ActiveReservation) – nicht verbundene
        # Reservierungen stehen schon in der Liste oben.
        $leases = @(Get-DhcpServerv4Lease -ScopeId $id -ErrorAction SilentlyContinue |
            Where-Object { "$($_.AddressState)" -like 'Active*' } | ForEach-Object {
                @{
                    ip     = "$($_.IPAddress)"
                    mac    = "$($_.ClientId)"
                    name   = "$($_.HostName)"
                    status = "$($_.AddressState)"
                    ablauf = $(if ($_.LeaseExpiryTime) { $_.LeaseExpiryTime.ToString('o') } else { $null })
                }
            })

        $bereiche += @{
            scope          = "$id"
            name           = "$($s.Name)"
            maske          = "$($s.SubnetMask)"
            von            = "$($s.StartRange)"
            bis            = "$($s.EndRange)"
            lease_minuten  = [int]$s.LeaseDuration.TotalMinutes
            frei           = [int]$st.Free
            belegt         = [int]$st.InUse
            reserviert     = [int]$st.Reserved
            auslastung     = [math]::Round([double]$st.PercentageInUse, 1)
            ausschluesse   = $ausschluesse
            reservierungen = $reservierungen
            leases         = $leases
        }
    }

    $nutzlast = @{
        server        = [System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).HostName
        gemessen_am   = (Get-Date).ToString('o')
        dhcp_bereiche = $bereiche
    }

    $json = ConvertTo-Json -InputObject $nutzlast -Depth 6 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    Invoke-RestMethod -Method Post -Uri $Url -ContentType 'application/json; charset=utf-8' -Body $bytes -TimeoutSec 60 | Out-Null

    if ($Einrichten) { Write-Host ('Erste Messung gesendet: {0} Bereich(e).' -f $bereiche.Count) }
}
catch {
    Schreibe-Log ('FEHLER: ' + $_.Exception.Message)
    if ($Einrichten) { throw }
    exit 1
}
