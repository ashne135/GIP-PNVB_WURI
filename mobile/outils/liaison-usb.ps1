<#
    GARDE LA LIAISON USB ENTRE LE TÉLÉPHONE DE TEST ET LE SERVEUR DE DÉVELOPPEMENT.

    L'application de développement joint le serveur à l'adresse 127.0.0.1:8000 DU
    TÉLÉPHONE, que « adb reverse » relie au port 8000 du PC. Cette redirection
    disparaît chaque fois que la liaison adb se coupe puis se rétablit — câble,
    mise en veille USB du téléphone ou du PC — et l'application affiche alors
    « Le serveur est injoignable ». Ce script la rétablit dès que le téléphone
    revient, et relance la liaison d'un téléphone resté « hors ligne ».

    Développement uniquement : en production, l'application joint le serveur par
    son adresse HTTPS, sans câble.

    Lancement, depuis la racine du projet :
        powershell -ExecutionPolicy Bypass -File mobile\outils\liaison-usb.ps1
    Arrêt : Ctrl+C.
#>
param(
    [int] $Port = 8000,
    [int] $IntervalleSecondes = 3
)

$adb = Join-Path $env:LOCALAPPDATA 'Android\Sdk\platform-tools\adb.exe'

if (-not (Test-Path $adb)) {
    Write-Error "adb introuvable : $adb"
    exit 1
}

Write-Host "Surveillance de la liaison USB (port $Port). Ctrl+C pour arrêter."

# Relancer la liaison d'un téléphone « hors ligne » réinitialise la connexion : fait
# trop souvent, cela ferait disparaître la demande d'autorisation du débogage USB
# avant que l'agent ait pu l'accepter. Une relance toutes les 30 secondes au plus.
$derniereRelance = [datetime]::MinValue

while ($true) {
    $appareils = & $adb devices 2>$null | Select-Object -Skip 1 | Where-Object { $_ -match '\S' }

    foreach ($ligne in $appareils) {
        $colonnes = $ligne -split '\s+'
        $serie = $colonnes[0]
        $etat = $colonnes[1]

        if ($etat -eq 'offline') {
            if (((Get-Date) - $derniereRelance).TotalSeconds -ge 30) {
                & $adb reconnect offline *> $null
                $derniereRelance = Get-Date
            }

            continue
        }

        if ($etat -ne 'device') {
            continue
        }

        $redirections = (& $adb -s $serie reverse --list 2>$null) -join ' '

        if ($redirections -notmatch "tcp:$Port\s+tcp:$Port") {
            & $adb -s $serie reverse "tcp:$Port" "tcp:$Port" *> $null
            Write-Host ('{0}  {1} : liaison du port {2} rétablie' -f (Get-Date -Format 'HH:mm:ss'), $serie, $Port)
        }
    }

    Start-Sleep -Seconds $IntervalleSecondes
}
