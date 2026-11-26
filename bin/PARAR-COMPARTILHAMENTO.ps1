$ErrorActionPreference='Stop'
$stateFile=Join-Path $PSScriptRoot '.gamb-runtime\compartilhamento\session.json'
if(Test-Path -LiteralPath $stateFile){$state=Get-Content -LiteralPath $stateFile -Raw|ConvertFrom-Json;$state.status='stopped';$state|ConvertTo-Json -Depth 4|Set-Content -LiteralPath $stateFile -Encoding UTF8}
$legacy=Join-Path $PSScriptRoot 'avaliacao-temporaria\.enabled'
if(Test-Path -LiteralPath $legacy){Remove-Item -LiteralPath $legacy -Force}
Write-Output 'Origens temporarias revogadas. O estatico e o admin locais continuam funcionando. Apache, firewall e outros projetos nao foram alterados.'
