import { execFile } from 'node:child_process';
import { writeFileSync, mkdirSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import type { ProxyRequest, ProxyResponse } from './curl-proxy.js';

/**
 * PowerShell-based mTLS proxy for Windows hardware tokens.
 *
 * Sets the CNG SmartCardPin property on the certificate's private key,
 * then makes the HTTP request using Invoke-WebRequest — all in the SAME
 * PowerShell process so the smart card middleware caches the PIN.
 *
 * Uses -SessionVariable to capture ALL cookies across the entire F5
 * redirect chain, then writes them to a Netscape cookie jar file so
 * subsequent requests can use curl with cookies only (no cert needed).
 */

const PS_SCRIPT = `
$ErrorActionPreference = 'Stop'

# Force TLS 1.2 (required by ANAF)
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12

# Load certificate from store
$cert = Get-ChildItem "Cert:/CurrentUser/My/$Thumbprint" -ErrorAction Stop
if (-not $cert) {
  throw "Certificate not found: $Thumbprint"
}

# Set PIN on private key (CNG SmartCardPin property — UTF-16LE null-terminated)
$pinSet = $false
if ($Pin) {
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
        $pinBytes = [System.Text.Encoding]::Unicode.GetBytes($Pin + [char]0)
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
    throw "Failed to set PIN on certificate: $_"
  }

  # Verify PIN with a test sign — fail fast before making real requests
  if ($pinSet) {
    try {
      $testData = [System.Text.Encoding]::UTF8.GetBytes("pin-verify")
      if ($key -is [System.Security.Cryptography.RSACng]) {
        $key.SignData($testData, [System.Security.Cryptography.HashAlgorithmName]::SHA256, [System.Security.Cryptography.RSASignaturePadding]::Pkcs1) | Out-Null
      } else {
        $key.SignData($testData, [System.Security.Cryptography.HashAlgorithmName]::SHA256) | Out-Null
      }
    } catch {
      throw "PIN verification failed - wrong PIN or certificate locked. Do NOT retry to avoid blocking the certificate."
    }
  }
}

# Read body from temp file
$bodyContent = ''
if ($BodyFile -and (Test-Path $BodyFile)) {
  $bodyContent = [System.IO.File]::ReadAllText($BodyFile, [System.Text.Encoding]::UTF8)
}

# Parse request headers
$headerDict = @{}
if ($HeadersJson) {
  $parsed = $HeadersJson | ConvertFrom-Json
  foreach ($prop in $parsed.PSObject.Properties) {
    $headerDict[$prop.Name] = $prop.Value
  }
}

# Determine content type
$contentType = 'application/xml'
if ($headerDict.ContainsKey('Content-Type')) {
  $contentType = $headerDict['Content-Type']
  $headerDict.Remove('Content-Type')
}

# Build Invoke-WebRequest params — use -SessionVariable to track ALL cookies across redirects
$iwrParams = @{
  Uri = $Url
  Method = $Method
  Certificate = $cert
  Headers = $headerDict
  MaximumRedirection = 10
  UseBasicParsing = $true
  ErrorAction = 'Stop'
}

if ($bodyContent) {
  $iwrParams['Body'] = $bodyContent
  $iwrParams['ContentType'] = $contentType
}

# Execute request with retry
$response = $null
$webSession = $null
$lastError = $null
for ($attempt = 0; $attempt -lt 2; $attempt++) {
  try {
    if ($attempt -gt 0) { Start-Sleep -Seconds 1 }
    $response = Invoke-WebRequest @iwrParams -SessionVariable 'webSession'
    $lastError = $null
    break
  } catch [System.Net.WebException] {
    $we = $_.Exception
    if ($we.Response) {
      # Got an HTTP error response — extract what we can
      $errResponse = $we.Response
      $sr = New-Object System.IO.StreamReader($errResponse.GetResponseStream())
      $errBody = $sr.ReadToEnd()
      $sr.Close()
      $errStatus = [int]$errResponse.StatusCode

      # Still save cookies from error responses
      $response = $null
      $lastError = $null

      # Save cookies to file even from error responses
      if ($CookieFile -and $webSession -and $webSession.Cookies) {
        $cookieLines = @("# Netscape HTTP Cookie File")
        $allUris = @([Uri]::new($Url))
        if ($errResponse.ResponseUri -and $errResponse.ResponseUri -ne [Uri]::new($Url)) {
          $allUris += $errResponse.ResponseUri
        }
        foreach ($uri in $allUris) {
          $cookies = $webSession.Cookies.GetCookies($uri)
          foreach ($c in $cookies) {
            $domain = $c.Domain
            $flag = if ($domain.StartsWith('.')) { 'TRUE' } else { 'FALSE' }
            $path = if ($c.Path) { $c.Path } else { '/' }
            $secure = if ($c.Secure) { 'TRUE' } else { 'FALSE' }
            $expires = if ($c.Expires -gt [DateTime]::Now) { [int]([DateTimeOffset]$c.Expires).ToUnixTimeSeconds() } else { '0' }
            $cookieLines += "$domain\t$flag\t$path\t$secure\t$expires\t$($c.Name)\t$($c.Value)"
          }
        }
        if ($cookieLines.Count -gt 1) {
          [System.IO.File]::WriteAllLines($CookieFile, $cookieLines)
        }
      }

      # Return the error response as result
      @{
        statusCode = $errStatus
        headers = @{}
        body = $errBody
      } | ConvertTo-Json -Depth 5 -Compress
      exit 0
    }
    $lastError = $_
  } catch {
    $lastError = $_
  }
}
if ($lastError) {
  $inner = $lastError.Exception.InnerException
  $detail = if ($inner) { "$($lastError.Exception.Message) -> $($inner.GetType().Name): $($inner.Message)" } else { $lastError.Exception.Message }
  throw "ANAF request failed: $detail"
}

# Save ALL cookies from the session (captures redirect chain cookies)
if ($CookieFile -and $webSession -and $webSession.Cookies) {
  $cookieLines = @("# Netscape HTTP Cookie File")

  # Collect cookies from both the original URL and the final redirect URL
  $allUris = @([Uri]::new($Url))
  if ($response.BaseResponse -and $response.BaseResponse.ResponseUri) {
    $finalUri = $response.BaseResponse.ResponseUri
    if ($finalUri -ne [Uri]::new($Url)) {
      $allUris += $finalUri
    }
  }

  # Also check common ANAF subdomains that F5 might redirect through
  $baseHost = ([Uri]::new($Url)).Host
  $seenDomains = @{}

  foreach ($uri in $allUris) {
    $cookies = $webSession.Cookies.GetCookies($uri)
    foreach ($c in $cookies) {
      $cookieKey = "$($c.Domain)|$($c.Name)"
      if ($seenDomains.ContainsKey($cookieKey)) { continue }
      $seenDomains[$cookieKey] = $true

      $domain = $c.Domain
      $flag = if ($domain.StartsWith('.')) { 'TRUE' } else { 'FALSE' }
      $path = if ($c.Path) { $c.Path } else { '/' }
      $secure = if ($c.Secure) { 'TRUE' } else { 'FALSE' }
      $expires = if ($c.Expires -gt [DateTime]::Now) { [int]([DateTimeOffset]$c.Expires).ToUnixTimeSeconds() } else { '0' }
      $cookieLines += "$domain\t$flag\t$path\t$secure\t$expires\t$($c.Name)\t$($c.Value)"
    }
  }

  if ($cookieLines.Count -gt 1) {
    [System.IO.File]::WriteAllLines($CookieFile, $cookieLines)
  }
}

# Build output
$statusCode = [int]$response.StatusCode
$responseHeaders = @{}
foreach ($h in $response.Headers.Keys) {
  $responseHeaders[$h] = ($response.Headers[$h] -join ', ')
}

# For binary content (PDFs etc.), base64-encode the body to avoid JSON serialization issues
$responseBody = $response.Content
$bodyEncoding = 'text'
if ($response.Content -is [byte[]]) {
  $responseBody = [System.Convert]::ToBase64String($response.Content)
  $bodyEncoding = 'base64'
}

@{
  statusCode = $statusCode
  headers = $responseHeaders
  body = $responseBody
  bodyEncoding = $bodyEncoding
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

    execFile('powershell.exe', [
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
        const msg = stderr || error.message;
        if (msg.includes('PIN verification failed')) {
          reject(new Error('PIN verification failed — wrong PIN or certificate locked. Do NOT retry to avoid blocking the certificate.'));
        } else {
          reject(new Error(`PowerShell proxy failed: ${msg}`));
        }
        return;
      }

      try {
        // Find the JSON object in output — PowerShell may emit extra output before it
        const jsonStart = stdout.indexOf('{"');
        if (jsonStart < 0) {
          throw new Error('No JSON object found in output');
        }
        const jsonEnd = stdout.lastIndexOf('}');
        const jsonStr = stdout.substring(jsonStart, jsonEnd + 1);
        const result = JSON.parse(jsonStr);
        resolve({
          statusCode: result.statusCode,
          headers: result.headers ?? {},
          body: result.body ?? '',
          bodyEncoding: result.bodyEncoding === 'base64' ? 'base64' : undefined,
        });
      } catch (err) {
        reject(new Error(`Failed to parse PowerShell response: ${(err as Error).message}\nOutput: ${stdout.substring(0, 500)}`));
      }
    });
  });
}
