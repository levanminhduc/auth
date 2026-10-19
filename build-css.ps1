$Version = "v4.1.18"
$ExeSha256 = "55bc5a2e294520a74fe3523eaa11915ef50047e7228a545aa181ec413cf52612"
$ErrorActionPreference = "Stop"

$Root = $PSScriptRoot
$ToolsDir = Join-Path $Root "tools"
$Exe = Join-Path $ToolsDir "tailwindcss.exe"
$InputCss = Join-Path $Root "resources\css\app.css"
$OutputCss = Join-Path $Root "public\assets\css\app.css"
$Watch = $args -contains "-Watch"

function Test-ExeHash {
    param([string]$Path)
    (Test-Path $Path) -and ((Get-FileHash -Path $Path -Algorithm SHA256).Hash -eq $ExeSha256)
}

if (-not (Test-ExeHash $Exe)) {
    New-Item -ItemType Directory -Force -Path $ToolsDir | Out-Null
    $url = "https://github.com/tailwindlabs/tailwindcss/releases/download/$Version/tailwindcss-windows-x64.exe"
    $partial = "$Exe.download"
    Remove-Item -Force -ErrorAction SilentlyContinue $partial
    try {
        Invoke-WebRequest -Uri $url -OutFile $partial
    } catch {
        Remove-Item -Force -ErrorAction SilentlyContinue $partial
        throw "Tải tools/tailwindcss.exe thất bại ($($_.Exception.Message)). Hãy tải thủ công $url về $Exe, kiểm tra SHA256 phải là $ExeSha256 (Get-FileHash -Algorithm SHA256) rồi chạy lại."
    }
    if (-not (Test-ExeHash $partial)) {
        $actual = (Get-FileHash -Path $partial -Algorithm SHA256).Hash
        Remove-Item -Force -ErrorAction SilentlyContinue $partial
        throw "SHA256 của tailwindcss.exe $Version không khớp (mong đợi $ExeSha256, nhận $actual). Không dùng file này: hãy tải thủ công $url về $Exe, kiểm tra SHA256 phải là $ExeSha256 rồi chạy lại."
    }
    Move-Item -Force -Path $partial -Destination $Exe
}

& $Exe --help | Select-Object -First 1

$buildArgs = @("-i", $InputCss, "-o", $OutputCss, "--minify")
if ($Watch) { $buildArgs += "--watch" }
& $Exe @buildArgs
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
if (Test-Path $OutputCss) {
    Write-Host ("output {0} bytes" -f (Get-Item $OutputCss).Length)
}
