# Installs the phpgraph executable on Windows, which needs no PHP: the binary checked against its SHA-256, in
# %LOCALAPPDATA%\Programs\phpgraph, added to the user's PATH.
#
#   irm https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.ps1 | iex                    latest release
#   $env:PHPGRAPH_VERSION = "0.2.0"; irm https://raw.githubusercontent.com/hbenabdallah/phpgraph/main/install.ps1 | iex
#   $env:PHPGRAPH_VERSION = "dev-main"; irm ... | iex                                                       the main branch
#
# PHPGRAPH_INSTALL_DIR installs elsewhere. Running the script again updates phpgraph.

$ErrorActionPreference = 'Stop'

$repository = 'hbenabdallah/phpgraph'
$asset = 'phpgraph-windows-x86_64.exe'
$version = if ($env:PHPGRAPH_VERSION) { $env:PHPGRAPH_VERSION } else { 'latest' }
$directory = if ($env:PHPGRAPH_INSTALL_DIR) { $env:PHPGRAPH_INSTALL_DIR } else { Join-Path $env:LOCALAPPDATA 'Programs\phpgraph' }

if (-not [Environment]::Is64BitOperatingSystem) {
    throw 'No phpgraph binary for 32-bit Windows: use the PHAR or the Docker image.'
}

$url = switch -Regex ($version) {
    '^latest$' { "https://github.com/$repository/releases/latest/download/$asset" }
    # Rebuilt on every push to main and published as the pre-release "edge".
    '^(dev-main|main|edge)$' { "https://github.com/$repository/releases/download/edge/$asset" }
    '^v' { "https://github.com/$repository/releases/download/$version/$asset" }
    default { "https://github.com/$repository/releases/download/v$version/$asset" }
}

$temporary = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid())
New-Item -ItemType Directory -Path $temporary | Out-Null
try {
    Write-Host "Downloading $url"
    $file = Join-Path $temporary $asset
    try {
        Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $file
        Invoke-WebRequest -UseBasicParsing -Uri "$url.sha256" -OutFile "$file.sha256"
    } catch {
        throw "No phpgraph binary at ${url}: is $version a release of https://github.com/$repository/releases?"
    }

    $expected = ((Get-Content "$file.sha256" -Raw).Trim() -split '\s+')[0]
    $actual = (Get-FileHash -Algorithm SHA256 $file).Hash
    if ($actual -ne $expected) {
        throw "Checksum mismatch for ${asset}: expected $expected, got $actual."
    }

    New-Item -ItemType Directory -Force -Path $directory | Out-Null
    $target = Join-Path $directory 'phpgraph.exe'
    Move-Item -Force $file $target
} finally {
    Remove-Item -Recurse -Force $temporary -ErrorAction SilentlyContinue
}

$path = [Environment]::GetEnvironmentVariable('Path', 'User')
if (($path -split ';') -notcontains $directory) {
    [Environment]::SetEnvironmentVariable('Path', "$directory;$path", 'User')
    Write-Host "Added $directory to your PATH: open a new terminal to use phpgraph."
}

Write-Host "Installed $(& $target --version) in $target"
