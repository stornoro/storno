import { execFile } from 'node:child_process';
import { writeFileSync, mkdirSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import type { ProxyRequest, ProxyResponse } from './curl-proxy.js';

/**
 * PowerShell-based mTLS proxy for Windows hardware tokens.
 *
 * Uses .NET CNG API to set the SmartCardPin property on the certificate's
 * private key, allowing silent PIN authentication without a native dialog.
 * This is used when a PIN is provided in the request.
 */

const PS_SCRIPT = `
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http

# Load certificate from store
$cert = Get-ChildItem "Cert:/CurrentUser/My/$Thumbprint" -ErrorAction Stop
if (-not $cert) {
  throw "Certificate not found: $Thumbprint"
}

# Set PIN on private key (CNG SmartCardPin property — null-terminated UTF-8)
$pinSet = $false
try {
  $key = [System.Security.Cryptography.X509Certificates.RSACertificateExtensions]::GetRSAPrivateKey($cert)
  if (-not $key) {
    $key = [System.Security.Cryptography.X509Certificates.ECDsaCertificateExtensions]::GetECDsaPrivateKey($cert)
  }
  if ($key) {
    $cngKey = $null
    if ($key -is [System.Security.Cryptography.RSACng]) { $cngKey = $key.Key }
    elseif ($key -is [System.Security.Cryptography.ECDsaCng]) { $cngKey = $key.Key }

    if ($cngKey) {
      # SmartCardPin requires null-terminated UTF-8
      $pinBytesRaw = [System.Text.Encoding]::UTF8.GetBytes($Pin)
      $pinBytes = New-Object byte[] ($pinBytesRaw.Length + 1)
      [Array]::Copy($pinBytesRaw, $pinBytes, $pinBytesRaw.Length)
      $pinBytes[$pinBytesRaw.Length] = 0

      $pinProp = [System.Security.Cryptography.CngProperty]::new(
        'SmartCardPin',
        $pinBytes,
        [System.Security.Cryptography.CngPropertyOptions]::None
      )
      $cngKey.SetProperty($pinProp)
      $pinSet = $true
    }
  }
} catch {
  # PIN set failed — will proceed anyway (may trigger native dialog or fail)
  Write-Error "PIN set warning: $_" -ErrorAction SilentlyContinue
}

# Build HttpClientHandler with cert and cookie container
$handler = New-Object System.Net.Http.HttpClientHandler
$handler.ClientCertificates.Add($cert)
$handler.AllowAutoRedirect = $true
$handler.MaxAutomaticRedirections = 10
$handler.UseCookies = $true

# Load existing cookies if cookie file exists
$cookieContainer = New-Object System.Net.CookieContainer
if ($CookieFile -and (Test-Path $CookieFile)) {
  try {
    $lines = Get-Content $CookieFile -ErrorAction SilentlyContinue
    foreach ($line in $lines) {
      if ($line -match '^#' -or $line -match '^\\s*$') { continue }
      $parts = $line -split '\\t'
      if ($parts.Count -ge 7) {
        $domain = $parts[0]
        $name = $parts[5]
        $value = $parts[6]
        $path = $parts[2]
        try {
          $cookie = New-Object System.Net.Cookie($name, $value, $path, $domain)
          $cookieContainer.Add($cookie)
        } catch { }
      }
    }
  } catch { }
}
$handler.CookieContainer = $cookieContainer

$client = New-Object System.Net.Http.HttpClient($handler)
$client.Timeout = [TimeSpan]::FromSeconds(120)

# Build request
$request = New-Object System.Net.Http.HttpRequestMessage
$request.Method = [System.Net.Http.HttpMethod]::new($Method)
$request.RequestUri = [Uri]::new($Url)

# Add headers
if ($HeadersJson) {
  $headers = $HeadersJson | ConvertFrom-Json
  foreach ($prop in $headers.PSObject.Properties) {
    try {
      $request.Headers.TryAddWithoutValidation($prop.Name, $prop.Value) | Out-Null
    } catch {
      # Some headers go on content, will be set below
    }
  }
}

# Add body
if ($BodyFile -and (Test-Path $BodyFile)) {
  $bodyContent = [System.IO.File]::ReadAllText($BodyFile, [System.Text.Encoding]::UTF8)
  $request.Content = New-Object System.Net.Http.StringContent($bodyContent, [System.Text.Encoding]::UTF8)
  # Set content-type from headers if present
  if ($HeadersJson) {
    $hdrs = $HeadersJson | ConvertFrom-Json
    $ct = $hdrs.'Content-Type'
    if ($ct) {
      $request.Content.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse($ct)
    }
  }
}

# Execute
$response = $client.SendAsync($request).GetAwaiter().GetResult()
$responseBody = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()

# Save cookies to Netscape cookie file (curl-compatible)
if ($CookieFile) {
  $cookieLines = @("# Netscape HTTP Cookie File")
  $uri = [Uri]::new($Url)
  $cookies = $cookieContainer.GetCookies($uri)
  foreach ($c in $cookies) {
    $httpOnly = if ($c.HttpOnly) { "#HttpOnly_" } else { "" }
    $domain = "$httpOnly$($c.Domain)"
    $flag = if ($c.Domain.StartsWith('.')) { "TRUE" } else { "FALSE" }
    $path = if ($c.Path) { $c.Path } else { "/" }
    $secure = if ($c.Secure) { "TRUE" } else { "FALSE" }
    $expires = if ($c.Expires -gt [DateTime]::Now) { [int]([DateTimeOffset]$c.Expires).ToUnixTimeSeconds() } else { "0" }
    $cookieLines += "$domain\t$flag\t$path\t$secure\t$expires\t$($c.Name)\t$($c.Value)"
  }
  # Also save cookies from redirect targets
  $redirectUri = $response.RequestMessage.RequestUri
  if ($redirectUri -ne $uri) {
    $redirectCookies = $cookieContainer.GetCookies($redirectUri)
    foreach ($c in $redirectCookies) {
      $httpOnly = if ($c.HttpOnly) { "#HttpOnly_" } else { "" }
      $domain = "$httpOnly$($c.Domain)"
      $flag = if ($c.Domain.StartsWith('.')) { "TRUE" } else { "FALSE" }
      $path = if ($c.Path) { $c.Path } else { "/" }
      $secure = if ($c.Secure) { "TRUE" } else { "FALSE" }
      $expires = if ($c.Expires -gt [DateTime]::Now) { [int]([DateTimeOffset]$c.Expires).ToUnixTimeSeconds() } else { "0" }
      $cookieLines += "$domain\t$flag\t$path\t$secure\t$expires\t$($c.Name)\t$($c.Value)"
    }
  }
  $cookieLines | Out-File -FilePath $CookieFile -Encoding UTF8
}

# Build response headers
$responseHeaders = @{}
foreach ($h in $response.Headers) {
  $responseHeaders[$h.Key] = ($h.Value -join ', ')
}
foreach ($h in $response.Content.Headers) {
  $responseHeaders[$h.Key] = ($h.Value -join ', ')
}

# Output as JSON
@{
  statusCode = [int]$response.StatusCode
  headers = $responseHeaders
  body = $responseBody
} | ConvertTo-Json -Depth 5 -Compress
`;

export function powershellProxy(req: ProxyRequest, cookiePath: string): Promise<ProxyResponse> {
  return new Promise((resolve, reject) => {
    const tempDir = join(tmpdir(), 'storno-agent-ps');

    // Write body to temp file to avoid PowerShell argument escaping issues
    let bodyFile = '';
    if (req.body) {
      mkdirSync(tempDir, { recursive: true });
      bodyFile = join(tempDir, `body_${Date.now()}.tmp`);
      writeFileSync(bodyFile, req.body, 'utf-8');
    }

    const headersJson = JSON.stringify(req.headers);

    const wrappedScript = `
$Thumbprint = '${req.certificateId}'
$Pin = '${(req.pin ?? '').replace(/'/g, "''")}'
$Url = '${req.url}'
$Method = '${req.method}'
$BodyFile = '${bodyFile.replace(/\\/g, '\\\\')}'
$HeadersJson = '${headersJson.replace(/'/g, "''")}'
$CookieFile = '${cookiePath.replace(/\\/g, '\\\\')}'
${PS_SCRIPT}
`;

    const child = execFile('powershell.exe', [
      '-NoProfile',
      '-NonInteractive',
      '-ExecutionPolicy', 'Bypass',
      '-Command', wrappedScript,
    ], {
      timeout: 120_000,
      maxBuffer: 50 * 1024 * 1024, // 50MB for large ANAF responses
    }, (error, stdout, stderr) => {
      // Clean up temp body file
      if (bodyFile) {
        try { unlinkSync(bodyFile); } catch { /* ignore */ }
      }

      if (error) {
        reject(new Error(`PowerShell proxy failed: ${stderr || error.message}`));
        return;
      }

      try {
        const result = JSON.parse(stdout.trim());
        resolve({
          statusCode: result.statusCode,
          headers: result.headers ?? {},
          body: result.body ?? '',
        });
      } catch (err) {
        reject(new Error(`Failed to parse PowerShell response: ${(err as Error).message}\nOutput: ${stdout.substring(0, 500)}`));
      }
    });
  });
}
