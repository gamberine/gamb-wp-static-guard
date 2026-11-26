param([string]$Address,[string]$PublicUrl,[string[]]$TrustedProxy=@(),[string]$PhpPath)
$ErrorActionPreference = 'Stop'
# Copie este arquivo para a raiz do WordPress. Não muda home/siteurl, Apache ou outros projetos.
$projectRoot = $PSScriptRoot
$helper = Join-Path $projectRoot 'wp-content\plugins\gamb-wp-static-guard\bin\sharing.php'
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'wp-load.php'))) { throw 'Execute a copia deste script na raiz do WordPress.' }
if (-not $PhpPath) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $PhpPath = $phpCommand.Source }
    else {
        $laragonRoot = Split-Path (Split-Path $projectRoot -Parent) -Parent
        $PhpPath = Get-ChildItem -LiteralPath (Join-Path $laragonRoot 'bin\php') -Directory | Sort-Object Name -Descending | ForEach-Object { Join-Path $_.FullName 'php.exe' } | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    }
}
if (-not $PhpPath) { throw 'Informe -PhpPath com o executavel PHP do Laragon.' }
$infoText = & $PhpPath $helper "--wp-root=$projectRoot"
if ($LASTEXITCODE -ne 0) { throw 'Nao foi possivel consultar a instalacao.' }
$info = $infoText | ConvertFrom-Json
if (-not $info.ready -or $info.mode -ne 'static') { throw ('Publique e selecione o estatico na URL principal antes de compartilhar. ' + $info.message) }
if ($info.url_type -ne 'relative') { throw 'Selecione enderecos relativos ao dominio e republique antes de compartilhar a mesma entrega em varias origens.' }
if (-not $Address) {
    $connection = Get-NetIPConfiguration | Where-Object { $_.IPv4Address -and $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1
    if (-not $connection) { throw 'Nenhum IPv4 ativo com gateway foi encontrado.' }
    $Address = $connection.IPv4Address.IPAddress | Select-Object -First 1
}
$parsedAddress = $null
if (-not [Net.IPAddress]::TryParse($Address,[ref]$parsedAddress) -or $parsedAddress.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork -or $Address -notmatch '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)') { throw 'Informe um IPv4 privado da rede interna.' }
$Address=$parsedAddress.ToString()
if (-not (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -eq $Address })) { throw 'O IP nao pertence a este computador.' }
$localUri = [Uri]$info.canonical
$lanUrl = 'http://' + $Address + $localUri.AbsolutePath
$origins = @($lanUrl)
if ($PublicUrl) {
    & $PhpPath $helper "--wp-root=$projectRoot" "--check-url=$PublicUrl" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'URL publica rejeitada.' }
    if (([Uri]$PublicUrl).Scheme -ne 'https') { throw 'Compartilhamento online exige HTTPS.' }
    $origins += $PublicUrl.TrimEnd('/') + '/'
}
foreach ($proxy in $TrustedProxy) { $parsedProxy=$null; if (-not [Net.IPAddress]::TryParse($proxy,[ref]$parsedProxy)) { throw 'TrustedProxy deve conter somente IPs explicitos do proxy reverso.' } }
$stateDir=Join-Path $projectRoot '.gamb-runtime\compartilhamento'
$stateFile=Join-Path $stateDir 'session.json'
New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
Set-Content -LiteralPath (Join-Path $projectRoot '.gamb-runtime\.htaccess') -Value 'Require all denied' -Encoding ASCII
$state=[ordered]@{status='running';mode='lan-apache';address=$Address;port=80;started_at=(Get-Date).ToString('o');origins=$origins;trusted_proxies=$TrustedProxy;static_url=$lanUrl;admin_url=$lanUrl+'wp-admin/'}
$state | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $stateFile -Encoding UTF8
try {
    foreach($origin in @($info.canonical,$lanUrl)) {
        $front=Invoke-WebRequest -Uri $origin -UseBasicParsing -TimeoutSec 30
        if ($front.StatusCode -ne 200 -or $front.BaseResponse.ResponseUri.Host -ne ([Uri]$origin).Host) { throw 'A URL principal nao preservou sua origem.' }
        $admin=Invoke-WebRequest -Uri ($origin+'wp-admin/') -UseBasicParsing -TimeoutSec 30
        if ($admin.StatusCode -ne 200 -or $admin.BaseResponse.ResponseUri.Host -ne ([Uri]$origin).Host -or $admin.BaseResponse.ResponseUri.AbsolutePath -notmatch '/wp-login\.php$') { throw 'O login administrativo nao preservou sua origem.' }
    }
} catch { $state.status='stopped';$state | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $stateFile -Encoding UTF8;throw }
Write-Output ('Estatico local preservado: '+$info.canonical)
Write-Output ('Estatico na rede: '+$lanUrl)
Write-Output ('Admin na rede: '+$state.admin_url)
if($PublicUrl) { Write-Output ('Origem online autorizada: '+$PublicUrl+' (DNS, TLS e proxy precisam estar configurados no host).') }
Write-Output 'O acesso WP original exige ativacao no plugin, confirmacao e sessao de administrador. Cada origem tem seu proprio login.'
