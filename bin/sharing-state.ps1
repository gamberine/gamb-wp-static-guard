function Write-GambSharingState {
    param([string]$Path,$State)
    $temporary=Join-Path (Split-Path $Path -Parent) ('.session-'+[Guid]::NewGuid().ToString('N')+'.tmp')
    try {
        [IO.File]::WriteAllText($temporary,($State|ConvertTo-Json -Depth 4),[Text.UTF8Encoding]::new($false))
        for($attempt=0;$attempt -lt 40;$attempt++) {
            try {
                if([IO.File]::Exists($Path)){[IO.File]::Replace($temporary,$Path,[NullString]::Value)}else{[IO.File]::Move($temporary,$Path)}
                return
            } catch [IO.IOException] {
                if($attempt -eq 39){throw}
                Start-Sleep -Milliseconds 50
            }
        }
    } finally {if([IO.File]::Exists($temporary)){[IO.File]::Delete($temporary)}}
}
