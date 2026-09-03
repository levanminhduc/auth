$Version = "v4.1.18"
$ErrorActionPreference = "Stop"

$Root = $PSScriptRoot
$ToolsDir = Join-Path $Root "tools"
$Exe = Join-Path $ToolsDir "tailwindcss.exe"
$InputCss = Join-Path $Root "resources\css\app.css"
$OutputCss = Join-Path $Root "public\assets\css\app.css"
$Watch = $args -contains "-Watch"
$NpxVersion = $Version.TrimStart("v")

function Invoke-TailwindBuild {
    param(
        [string[]]$Command
    )
    $p = Start-Process -FilePath $Command[0] -ArgumentList $Command[1..($Command.Length - 1)] -Wait -PassThru -NoNewWindow
    if ($p.ExitCode -ne 0) {
        exit $p.ExitCode
    }
}

if (-not (Test-Path $Exe)) {
    New-Item -ItemType Directory -Force -Path $ToolsDir | Out-Null
    $url = "https://github.com/tailwindlabs/tailwindcss/releases/download/$Version/tailwindcss-windows-x64.exe"
    try {
        Invoke-WebRequest -Uri $url -OutFile $Exe
    } catch {
        Write-Host "Tải tools/tailwindcss.exe thất bại, fallback npx @tailwindcss/cli@$NpxVersion"
        & npx --yes "@tailwindcss/cli@$NpxVersion" -i $InputCss -o $OutputCss --minify $(if ($Watch) { "--watch" })
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        if (Test-Path $OutputCss) {
            Write-Host ("output {0} bytes" -f (Get-Item $OutputCss).Length)
        }
        exit 0
    }
}

& $Exe --help | Select-Object -First 1

$buildArgs = @("-i", $InputCss, "-o", $OutputCss, "--minify")
if ($Watch) { $buildArgs += "--watch" }
& $Exe @buildArgs
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
if (Test-Path $OutputCss) {
    Write-Host ("output {0} bytes" -f (Get-Item $OutputCss).Length)
}
