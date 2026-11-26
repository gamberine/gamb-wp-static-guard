$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot 'wp-content\plugins\gamb-wp-static-guard\bin\sharing-state.ps1')
$stateFile=Join-Path $PSScriptRoot '.gamb-runtime\compartilhamento\session.json'
if(Test-Path -LiteralPath $stateFile){$state=Get-Content -LiteralPath $stateFile -Raw|ConvertFrom-Json;$state.status='stopped';Write-GambSharingState -Path $stateFile -State $state}
$legacy=Join-Path $PSScriptRoot 'avaliacao-temporaria\.enabled'
if(Test-Path -LiteralPath $legacy){Remove-Item -LiteralPath $legacy -Force}
Write-Output 'Origens temporarias revogadas. O estatico e o admin locais continuam funcionando. Apache, firewall e outros projetos nao foram alterados.'
