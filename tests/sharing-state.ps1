$ErrorActionPreference='Stop'
. (Join-Path $PSScriptRoot '..\bin\sharing-state.ps1')
$sandbox=Join-Path ([IO.Path]::GetTempPath()) ('gamb-state-test-'+[Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($sandbox)|Out-Null
$statePath=Join-Path $sandbox 'session.json';$marker=Join-Path $sandbox '.locked'
try {
    Write-GambSharingState -Path $statePath -State @{status='stopped'}
    $job=Start-Job -ArgumentList $statePath,$marker -ScriptBlock {
        param($file,$signal)
        $stream=[IO.File]::Open($file,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
        try {[IO.File]::WriteAllText($signal,'locked');Start-Sleep -Milliseconds 600}finally{$stream.Dispose()}
    }
    $deadline=[DateTime]::UtcNow.AddSeconds(15)
    while(-not [IO.File]::Exists($marker)){if([DateTime]::UtcNow -gt $deadline){throw 'Reader did not start'};Start-Sleep -Milliseconds 30}
    Write-GambSharingState -Path $statePath -State @{status='running';origins=@('http://172.30.125.118/site/')}
    $value=[IO.File]::ReadAllText($statePath)|ConvertFrom-Json
    if($value.status -ne 'running' -or $value.origins.Count -ne 1){throw 'Estado final inválido'}
    if((Get-ChildItem -LiteralPath $sandbox -Filter '*.tmp' -Force).Count){throw 'Arquivo temporário remanescente'}
    Wait-Job $job -Timeout 5|Out-Null;Receive-Job $job|Out-Null;Remove-Job $job
    Write-Output 'Gravacao atomica validada com leitor concorrente no Windows.'
} finally {
    if($job -and $job.State -eq 'Running'){Stop-Job $job;Remove-Job $job}
    foreach($file in @($statePath,$marker)){if([IO.File]::Exists($file)){[IO.File]::Delete($file)}}
    [IO.Directory]::Delete($sandbox)
}
