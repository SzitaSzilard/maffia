<#
.SYNOPSIS
    Netmafia modulok auditálása a Fejlesztési Szabályzat alapján.
.DESCRIPTION
    A script végigjárja a megadott mappákat, és ellenőrzi a PHP, Twig és egyéb fájlokat
    a szabályzatban leírt előírásoknak való megfelelés szempontjából.
    Eredményül egy részletes HTML audit jegyzőkönyvet generál.
.PARAMETER ModulesPath
    A modulokat tartalmazó gyökérkönyvtár (pl. "src/Modules").
.PARAMETER OutputPath
    A kimeneti HTML fájl elérési útja.
.PARAMETER IncludePaths
    További mappák, amelyeket be kell járni (pl. "src/Infrastructure").
.EXAMPLE
    .\Audit-Netmafia.ps1 -ModulesPath "src/Modules" -OutputPath "report.html"
#>

param(
    [string]$ModulesPath = "src/Modules",
    [string]$OutputPath = "audit_report.html",
    [string[]]$IncludePaths = @()
)

# UTF-8 BOM nélküli íráshoz segédfüggvény
function Write-FileUtf8NoBom {
    param([string]$Path, [string]$Content)
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($Path, $Content, $utf8NoBom)
}

# Eredmények gyűjtése
$results = @{}  # Kulcs: szabály azonosító, érték: lista találatokról (fájl, sor, üzenet, súlyosság)
$summary = @{}  # Kulcs: szabály azonosító, érték: státusz (OK, WARNING, ERROR)

# Segédfüggvény találat rögzítésére
function Add-Issue {
    param(
        [string]$RuleId,
        [string]$File,
        [int]$Line,
        [string]$Message,
        [string]$Severity = "ERROR"  # ERROR, WARNING, INFO
    )
    if (-not $results.ContainsKey($RuleId)) {
        $results[$RuleId] = @()
    }
    $results[$RuleId] += [PSCustomObject]@{
        File = $File
        Line = $Line
        Message = $Message
        Severity = $Severity
    }
    # Ha már van OK, akkor felülírjuk, ha rosszabb jön
    if (-not $summary.ContainsKey($RuleId) -or $summary[$RuleId] -eq "OK") {
        $summary[$RuleId] = $Severity
    } elseif ($Severity -eq "ERROR" -and $summary[$RuleId] -ne "ERROR") {
        $summary[$RuleId] = "ERROR"
    } elseif ($Severity -eq "WARNING" -and $summary[$RuleId] -eq "OK") {
        $summary[$RuleId] = "WARNING"
    }
}

# Összes fájl bejárása
$allFiles = @()
if (Test-Path $ModulesPath) {
    $allFiles += Get-ChildItem -Path $ModulesPath -Recurse -File
}
foreach ($inc in $IncludePaths) {
    if (Test-Path $inc) {
        $allFiles += Get-ChildItem -Path $inc -Recurse -File
    }
}

# Szabályok definíciója (magyarázó szöveggel)
$ruleDescriptions = @{
    "1.1" = "declare(strict_types=1) megléte minden PHP fájlban"
    "1.2" = "DDD struktúra (Actions, Domain, Config) és dependency injection (new kerülése)"
    "2.1" = "SELECT * használatának tilalma"
    "2.2" = "Paraméteres query-k kötelezőek (SQL injection ellen)"
    "2.3" = "N+1 query tilalma (ciklusban DB hívás)"
    "2.5" = "Tranzakciók használata (beginTransaction, commit, rollBack)"
    "2.6" = "FOR UPDATE használata egyenlegmódosításnál"
    "2.9" = "Cache invalidáció (CacheService->forget) értesítések/üzenetek után"
    "3.1" = "$_SERVER használata tilos Service rétegben"
    "3.2" = "XSS védelem: |raw használata előtt htmlspecialchars"
    "3.3" = "CSRF token megléte POST formokban"
    "3.4" = "IDOR ellenőrzés (ownership check ID alapú lekéréseknél)"
    "3.5" = "File upload biztonsági ellenőrzések"
    "3.6" = "Jelszókezelés: password_hash / password_verify"
    "3.7" = "Anti-spoofing (kliens adat nem lehet megbízható)"
    "4.1" = "Input validálás (filter_var, preg_match, stb.)"
    "4.2" = "Kereszt-validáció és overflow védelem szorzásoknál"
    "4.3" = "RateLimiter használata publikus endpointokon"
    "5.1" = "Exception típusok (nem dob nyers Exception-t)"
    "5.2" = "PRG pattern (flash + redirect)"
    "6"   = "SessionService használata, nem közvetlen $_SESSION"
    "7.1" = "HTMX kompatibilitás (HX-Request header ellenőrzés)"
    "7.2" = "HX-Trigger használata globális állapotváltozáskor"
    "8"   = "Cache használat gyakran változatlan adatokra"
    "9.1" = "Integer overflow védelem szorzásoknál"
    "9.2" = "Direkt SQL currency módosítás tiltása (money, credits, bullets)"
    "9.3" = "@audit_source használata try-finally blokkban"
    "10"  = "Enum/whitelist validáció"
    "11.1" = "Twig biztonság: |raw, href, inline JS tiltása"
    "12"  = "HTMX-only végpontok HX-Request ellenőrzése"
    "13"  = "Fejlesztési checklist egyes elemei (pl. indexek)"
    "14"  = "Meglévő szolgáltatások használata (MoneyService, stb.)"
}

# Kezdetben minden szabály OK, ha nincs találat
foreach ($key in $ruleDescriptions.Keys) {
    $summary[$key] = "OK"
}

# --- Ellenőrző függvények ---

function Check-StrictTypes {
    param($file, $content)
    if ($content -notmatch 'declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;') {
        Add-Issue -RuleId "1.1" -File $file.FullName -Line 1 -Message "Hiányzó declare(strict_types=1) a fájl elején." -Severity "ERROR"
    }
}

function Check-SelectStar {
    param($file, $content)
    # Nem szűrünk olyanokat, ahol kommentben van, de alapvetően jó jelzés
    if ($content -match '(?<![''"])SELECT\s+\*\s+FROM' -and $content -notmatch 'EXISTS\s*\(?\s*SELECT\s+\*') {
        Add-Issue -RuleId "2.1" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "SELECT * használata: $($matches[0])" -Severity "ERROR"
    }
}

function Check-ParametrizedQueries {
    param($file, $content)
    # Keressünk olyan query-ket, ahol string concatenation van változóval
    if ($content -match '\.\s*\$[a-zA-Z_][a-zA-Z0-9_]*' -or $content -match '\.\s*\{[^}]+\}' -or $content -match 'query\s*\(\s*".*?\$[a-zA-Z_][a-zA-Z0-9_]*.*?"') {
        Add-Issue -RuleId "2.2" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Lehetséges nem paraméterezett query (string concat): $($matches[0])" -Severity "WARNING"
    }
}

function Check-NplusOne {
    param($file, $content)
    # Ciklusban DB hívás: foreach ... { $db->... }
    if ($content -match 'foreach\s*\([^)]+\)\s*\{[^}]*\$this->db->(?:fetch|execute|query)[^}]*\}') {
        Add-Issue -RuleId "2.3" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Ciklusban adatbázis hívás (N+1 gyanú): $($matches[0])" -Severity "WARNING"
    }
}

function Check-Transactions {
    param($file, $content)
    # Ha van beginTransaction, de nincs rollBack ellenőrzés catch-ben
    if ($content -match '\$this->db->beginTransaction\(') {
        if ($content -notmatch 'isTransactionActive\(\)\s*\&\&\s*\$this->db->rollBack\(') {
            Add-Issue -RuleId "2.5" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Tranzakció rollBack hiányzik vagy nincs isTransactionActive ellenőrzés." -Severity "ERROR"
        }
    }
}

function Check-ForUpdate {
    param($file, $content)
    # Olyan UPDATE users SET money/credits/bullets ahol nincs előtte FOR UPDATE ugyanazon a soron (vagy legalább egy lekérdezés FOR UPDATE-val)
    # Egyszerű keresés: olyan UPDATE, amely érinti a fenti mezőket, és a közelben nincs SELECT ... FOR UPDATE
    if ($content -match 'UPDATE\s+users\s+SET\s+(?:money|credits|bullets)\s*=') {
        # Megnézzük, hogy ugyanabban a függvényben van-e FOR UPDATE
        $functionBody = Get-FunctionBody $content $matches[0]
        if ($functionBody -and $functionBody -notmatch 'FOR UPDATE') {
            Add-Issue -RuleId "2.6" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Egyenlegmódosítás FOR UPDATE nélkül: $($matches[0])" -Severity "ERROR"
        }
    }
}

function Check-CacheInvalidation {
    param($file, $content)
    if ($content -match 'CacheService' -and $content -notmatch '->forget\(') {
        # Ha van CacheService injektálva, de sehol nem hívják a forget-et, az gyanús
        Add-Issue -RuleId "2.9" -File $file.FullName -Line 1 -Message "CacheService injektálva, de nincs forget hívás (lehet, hogy hiányzik a cache törlés)." -Severity "WARNING"
    }
}

function Check-ServerSuperglobal {
    param($file, $content)
    if ($content -match '\$_SERVER' -and $file.FullName -notmatch 'Actions?\\') {
        Add-Issue -RuleId "3.1" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message '$_SERVER használata nem Action rétegben.' -Severity "ERROR"
    }
}

function Check-RawInTwig {
    param($file, $content)
    if ($file.Extension -eq '.twig' -and $content -match '\|\s*raw') {
        Add-Issue -RuleId "3.2" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "|raw használata Twig-ben. Ellenőrizd, hogy az adat előtte escape-elve lett-e PHP-ban." -Severity "WARNING"
    }
}

function Check-CsrfInForms {
    param($file, $content)
    if ($file.Extension -eq '.twig' -and $content -match '<form[^>]*method="POST"') {
        if ($content -notmatch '{{ csrf.keys\|raw }}') {
            Add-Issue -RuleId "3.3" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "POST form CSRF token nélkül." -Severity "ERROR"
        }
    }
}

function Check-IDOR {
    param($file, $content)
    # Olyan SELECT ... WHERE id = ? ami után nincs AND user_id = ? (vagy hasonló ownership)
    # Nagyon egyszerű heurisztika: keresünk fetchAssociative-t vagy hasonlót, ahol a WHERE csak id-t említ
    if ($content -match '(fetch(?:Associative|One|All)|executeQuery)\s*\(\s*"[^"]*\bWHERE\s+(\w+\.)?id\s*=\s*\?[^"]*"(?:[^,]*,)?\s*\[\s*\$[^]]+\]\s*\)') {
        # Ha nincs a közelben user_id mező is a WHERE-ben, akkor gyanús
        $wholeMatch = $matches[0]
        if ($wholeMatch -notmatch 'user_id\s*=\s*\?' -and $wholeMatch -notmatch 'owner_id\s*=\s*\?' -and $wholeMatch -notmatch 'AND' -match 'WHERE\s+.*id\s*=\s*\?\s*$') {
            Add-Issue -RuleId "3.4" -File $file.FullName -Line (Get-LineNumber $content $wholeMatch) -Message "ID alapú lekérdezés ownership ellenőrzés nélkül (lehetséges IDOR)." -Severity "WARNING"
        }
    }
}

function Check-FileUpload {
    param($file, $content)
    # Ellenőrizzük, hogy van-e file upload ellenőrzés (allowedExtensions, finfo, stb.)
    if ($content -match '\$_FILES' -or $content -match '\$request->getUploadedFiles') {
        $checks = @('allowedExtensions', 'finfo', 'MIME', 'maxSize', 'random_bytes', 'bin2hex')
        $missing = @()
        foreach ($check in $checks) {
            if ($content -notmatch $check) {
                $missing += $check
            }
        }
        if ($missing.Count -gt 0) {
            Add-Issue -RuleId "3.5" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "File upload lehetséges, de hiányzó ellenőrzések: $($missing -join ', ')" -Severity "WARNING"
        }
    }
}

function Check-PasswordHashing {
    param($file, $content)
    if ($content -match 'password_hash' -and $content -notmatch 'PASSWORD_BCRYPT') {
        Add-Issue -RuleId "3.6" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "password_hash használata BCRYPT algoritmus megadása nélkül." -Severity "WARNING"
    }
    if ($content -match 'password_verify' -and $content -notmatch 'password_hash') {
        # csak verify, de nincs hash, az ok
    }
}

function Check-AntiSpoofing {
    param($file, $content)
    # Olyan POST feldolgozás, ahol közvetlenül $_POST['price']-t használnak, nem ID alapján keresnek
    if ($content -match '\$_POST\s*\[\s*[''"](?:price|name|amount)[''"]\s*\]' -and $content -notmatch 'SELECT.*WHERE\s+id\s*=\s*\?') {
        Add-Issue -RuleId "3.7" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Kliens által küldött ár/név közvetlen elfogadása (anti-spoofing veszély)." -Severity "WARNING"
    }
}

function Check-InputValidation {
    param($file, $content)
    # Ellenőrizzük, hogy a beolvasott adatokon van-e validálás
    if ($content -match '\$_(?:POST|GET|REQUEST)\s*\[\s*[''"].+?[''"]\s*\]') {
        $line = Get-LineNumber $content $matches[0]
        # Később a függvényben keresünk valamilyen validáló függvényt (filter_var, preg_match, stb.)
        $functionBody = Get-FunctionBody $content $matches[0]
        if ($functionBody -and $functionBody -notmatch 'filter_var|preg_match|ctype_digit|is_numeric|trim|mb_strtolower') {
            Add-Issue -RuleId "4.1" -File $file.FullName -Line $line -Message "Felhasználói input validálás nélkül: $($matches[0])" -Severity "WARNING"
        }
    }
}

function Check-OverflowProtection {
    param($file, $content)
    # Keressük a szorzásokat, és hogy előtte van-e felső határ ellenőrzés
    if ($content -match '\$[a-zA-Z_][a-zA-Z0-9_]*\s*\*\s*\$[a-zA-Z_][a-zA-Z0-9_]*') {
        $line = Get-LineNumber $content $matches[0]
        $functionBody = Get-FunctionBody $content $matches[0]
        if ($functionBody -and $functionBody -notmatch 'MAX_|max') {
            Add-Issue -RuleId "4.2" -File $file.FullName -Line $line -Message "Szorzás található, de nincs látható felső korlát ellenőrzés (integer overflow veszély)." -Severity "WARNING"
        }
    }
}

function Check-RateLimiter {
    param($file, $content)
    if ($file.FullName -match 'Actions?' -and $content -match 'public\s+function\s+\w+' -and $content -notmatch 'RateLimiter') {
        Add-Issue -RuleId "4.3" -File $file.FullName -Line 1 -Message "Action-ben nincs RateLimiter használat (publikus endpoint lehet)." -Severity "INFO"
    }
}

function Check-ExceptionTypes {
    param($file, $content)
    if ($content -match 'throw\s+new\s+Exception\(' -and $file.FullName -notmatch 'test') {
        Add-Issue -RuleId "5.1" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Nyers Exception dobása (használj InvalidInputException, GameException, RuntimeException)." -Severity "ERROR"
    }
}

function Check-PrgPattern {
    param($file, $content)
    if ($file.FullName -match 'Actions?' -and $content -match '\$this->session->flash' -and $content -match '\$response->withHeader\(\s*[''"]Location[''"]' -and $content -notmatch '\$this->session->flash') {
        Add-Issue -RuleId "5.2" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Redirect történik, de nincs előtte flash üzenet beállítva." -Severity "WARNING"
    }
}

function Check-SessionService {
    param($file, $content)
    if ($content -match '\$_SESSION' -and $file.FullName -notmatch 'SessionService' -and $file.FullName -notmatch 'Middleware') {
        Add-Issue -RuleId "6" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message '$_SESSION közvetlen használata (használj SessionService-t).' -Severity "ERROR"
    }
}

function Check-HtmxHeaders {
    param($file, $content)
    if ($file.FullName -match 'Actions?' -and $content -match '\$request->hasHeader\(\s*[''"]HX-Request[''"]\s*\)' -and $content -notmatch 'if\s*\(\s*\$isHtmx') {
        # OK
    } elseif ($file.FullName -match 'Actions?' -and $content -match 'public\s+function' -and $content -notmatch 'HX-Request') {
        Add-Issue -RuleId "7.1" -File $file.FullName -Line 1 -Message "Action nem ellenőrzi a HX-Request headert (HTMX kompatibilitás hiánya)." -Severity "INFO"
    }
    if ($content -match 'withHeader\(\s*[''"]HX-Trigger[''"]') {
        # OK
    } elseif ($file.FullName -match 'Actions?' -and $content -match 'public\s+function' -and $content -match 'értesítés|üzenet|badge') {
        Add-Issue -RuleId "7.2" -File $file.FullName -Line 1 -Message "Lehet, hogy hiányzik a HX-Trigger a globális állapot frissítéséhez." -Severity "INFO"
    }
}

function Check-CacheUsage {
    param($file, $content)
    if ($content -match '\$this->cache->get\(') {
        # OK
    } elseif ($content -match 'SELECT\s+.*\bFROM\b.*\b(?:countries|config|static)\b' -and $content -notmatch 'cache') {
        Add-Issue -RuleId "8" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Gyakran változatlan adat lekérése cache nélkül (pl. országok)." -Severity "WARNING"
    }
}

function Check-DirectCurrencyUpdate {
    param($file, $content)
    if ($content -match 'UPDATE\s+users\s+SET\s+(?:money|credits|bullets)\s*=' -and $content -notmatch 'MoneyService|CreditService|BulletService') {
        Add-Issue -RuleId "9.2" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Direkt SQL currency módosítás service helyett." -Severity "ERROR"
    }
}

function Check-AuditSource {
    param($file, $content)
    if ($content -match 'SET @audit_source' -and $content -notmatch 'finally\s*\{.*SET @audit_source = NULL') {
        Add-Issue -RuleId "9.3" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "@audit_source beállítva, de nincs finally blokk a NULL visszaállításra." -Severity "ERROR"
    }
}

function Check-EnumWhitelist {
    param($file, $content)
    if ($content -match '\$_(?:POST|GET|REQUEST)\s*\[\s*[''"]\w+[''"]\s*\]' -and $content -match 'category|type|currency|tab') {
        $line = Get-LineNumber $content $matches[0]
        $functionBody = Get-FunctionBody $content $matches[0]
        if ($functionBody -and $functionBody -notmatch 'array_key_exists|in_array') {
            Add-Issue -RuleId "10" -File $file.FullName -Line $line -Message "Kategória/típus input whitelist validáció nélkül." -Severity "WARNING"
        }
    }
}

function Check-TwigSecurity {
    param($file, $content)
    if ($file.Extension -eq '.twig') {
        # Inline eseménykezelők
        if ($content -match 'on(click|load|submit|mouseover)\s*=') {
            Add-Issue -RuleId "11.1" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Inline eseménykezelő használata (tilos)." -Severity "ERROR"
        }
        # User input href-ben
        if ($content -match 'href\s*=\s*"\{\{[^}]*\}\}"' -and $content -notmatch 'href="/') {
            Add-Issue -RuleId "11.1" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "User input közvetlenül href attribútumban (XSS veszély)." -Severity "ERROR"
        }
    }
}

function Check-HtmxOnlyEndpoints {
    param($file, $content)
    if ($file.FullName -match 'Actions?' -and $content -match 'public\s+function' -and $content -match 'HX-Request') {
        # Ellenőrizzük, hogy a nem-HTMX kéréseket átirányítja-e
        if ($content -notmatch 'if\s*\(\s*!\s*\$isHtmx\s*\)\s*\{.*withHeader\(.*Location') {
            Add-Issue -RuleId "12" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "HTMX-only végpont, de hiányzik a nem-HTMX kérések átirányítása." -Severity "WARNING"
        }
    }
}

function Check-IndexesInSql {
    param($file, $content)
    if ($file.Extension -in '.sql', '.php' -and $content -match 'CREATE\s+TABLE') {
        if ($content -notmatch 'INDEX' -and $content -notmatch 'PRIMARY KEY') {
            Add-Issue -RuleId "13" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "CREATE TABLE indexek nélkül (user_id, created_at, FK-k?)." -Severity "WARNING"
        }
    }
}

function Check-ServiceUsage {
    param($file, $content)
    # Ha van direkt SQL currency update, azt már jeleztük. Itt azt nézzük, hogy van-e olyan hely, ahol lehetne használni a service-t, de nem.
    if ($content -match 'UPDATE\s+users\s+SET' -and $content -notmatch 'MoneyService|CreditService|BulletService|HealthService|XpService') {
        Add-Issue -RuleId "14" -File $file.FullName -Line (Get-LineNumber $content $matches[0]) -Message "Lehetséges, hogy valamelyik meglévő service-t kellene használni a direkt UPDATE helyett." -Severity "INFO"
    }
}

# Segédfüggvény a sor számának meghatározásához
function Get-LineNumber {
    param($content, $substring)
    $index = $content.IndexOf($substring)
    if ($index -eq -1) { return 1 }
    $lines = $content.Substring(0, $index).Split("`n")
    return $lines.Count
}

# Segédfüggvény egy függvénytörzs kinyerésére (egyszerűsített)
function Get-FunctionBody {
    param($content, $approxPos)
    if ($approxPos -is [string]) {
        $approxPos = $content.IndexOf($approxPos)
    }
    if ($approxPos -eq -1) { return $null }
    # Keressük a legközelebbi függvény elejét és végét (csak nagyon durva)
    $start = $content.LastIndexOf('function ', $approxPos)
    if ($start -eq -1) { return $null }
    $braceOpen = $content.IndexOf('{', $start)
    if ($braceOpen -eq -1) { return $null }
    $braceCount = 1
    $pos = $braceOpen + 1
    while ($braceCount -gt 0 -and $pos -lt $content.Length) {
        $c = $content[$pos]
        if ($c -eq '{') { $braceCount++ }
        elseif ($c -eq '}') { $braceCount-- }
        $pos++
    }
    return $content.Substring($braceOpen, $pos - $braceOpen)
}

# Fő ellenőrző ciklus
foreach ($file in $allFiles) {
    if ($file.Extension -notin '.php', '.twig', '.sql') { continue }
    try {
        $content = Get-Content -Path $file.FullName -Raw -Encoding UTF8
    } catch {
        Write-Warning "Nem sikerült beolvasni: $($file.FullName)"
        continue
    }

    # PHP fájlok
    if ($file.Extension -eq '.php') {
        Check-StrictTypes -file $file -content $content
        Check-SelectStar -file $file -content $content
        Check-ParametrizedQueries -file $file -content $content
        Check-NplusOne -file $file -content $content
        Check-Transactions -file $file -content $content
        Check-ForUpdate -file $file -content $content
        Check-CacheInvalidation -file $file -content $content
        Check-ServerSuperglobal -file $file -content $content
        Check-IDOR -file $file -content $content
        Check-FileUpload -file $file -content $content
        Check-PasswordHashing -file $file -content $content
        Check-AntiSpoofing -file $file -content $content
        Check-InputValidation -file $file -content $content
        Check-OverflowProtection -file $file -content $content
        Check-RateLimiter -file $file -content $content
        Check-ExceptionTypes -file $file -content $content
        Check-PrgPattern -file $file -content $content
        Check-SessionService -file $file -content $content
        Check-HtmxHeaders -file $file -content $content
        Check-CacheUsage -file $file -content $content
        Check-DirectCurrencyUpdate -file $file -content $content
        Check-AuditSource -file $file -content $content
        Check-EnumWhitelist -file $file -content $content
        Check-IndexesInSql -file $file -content $content
        Check-ServiceUsage -file $file -content $content
    }

    # Twig fájlok
    elseif ($file.Extension -eq '.twig') {
        Check-RawInTwig -file $file -content $content
        Check-CsrfInForms -file $file -content $content
        Check-TwigSecurity -file $file -content $content
    }

    # SQL fájlok
    elseif ($file.Extension -eq '.sql') {
        Check-IndexesInSql -file $file -content $content
    }
}

# HTML jelentés generálása
$html = @"
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Netmafia Audit Jegyzőkönyv</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        .rule { margin-bottom: 30px; border: 1px solid #ddd; border-radius: 5px; padding: 10px; }
        .rule h2 { margin: 0 0 10px 0; cursor: pointer; }
        .rule-header { display: flex; align-items: center; }
        .status { padding: 3px 8px; border-radius: 3px; font-weight: bold; margin-left: 10px; }
        .status.OK { background-color: #d4edda; color: #155724; }
        .status.WARNING { background-color: #fff3cd; color: #856404; }
        .status.ERROR { background-color: #f8d7da; color: #721c24; }
        .status.INFO { background-color: #d1ecf1; color: #0c5460; }
        .issues { margin-top: 10px; display: none; }
        .issue { border-bottom: 1px solid #eee; padding: 5px 0; }
        .issue .file { font-family: monospace; background: #f4f4f4; padding: 2px 4px; }
        .issue .line { font-weight: bold; }
        .severity { font-size: 0.9em; padding: 2px 5px; border-radius: 3px; }
        .severity.ERROR { background-color: #f8d7da; }
        .severity.WARNING { background-color: #fff3cd; }
        .severity.INFO { background-color: #d1ecf1; }
        .summary { margin-bottom: 30px; }
        .summary table { border-collapse: collapse; width: 100%; }
        .summary th, .summary td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .summary th { background-color: #f2f2f2; }
    </style>
    <script>
        function toggleIssues(id) {
            var elem = document.getElementById('issues-' + id);
            if (elem.style.display === 'none' || elem.style.display === '') {
                elem.style.display = 'block';
            } else {
                elem.style.display = 'none';
            }
        }
    </script>
</head>
<body>
    <h1>Netmafia Audit Jegyzőkönyv</h1>
    <p>Készült: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')</p>
    <div class="summary">
        <h2>Összesítés</h2>
        <table>
            <tr><th>Szabály</th><th>Leírás</th><th>Státusz</th></tr>
"@

$sortedRules = $ruleDescriptions.Keys | Sort-Object
foreach ($rule in $sortedRules) {
    $desc = $ruleDescriptions[$rule]
    $status = if ($summary.ContainsKey($rule)) { $summary[$rule] } else { "OK" }
    $html += "<tr><td>$rule</td><td>$desc</td><td><span class='status $status'>$status</span></td></tr>"
}

$html += @"
        </table>
    </div>
    <h2>Részletes találatok</h2>
"@

$counter = 0
foreach ($rule in $sortedRules) {
    $desc = $ruleDescriptions[$rule]
    $status = if ($summary.ContainsKey($rule)) { $summary[$rule] } else { "OK" }
    $issues = if ($results.ContainsKey($rule)) { $results[$rule] } else { @() }
    $counter++
    $html += @"
    <div class="rule">
        <div class="rule-header" onclick="toggleIssues('$counter')">
            <h2>$rule - $desc</h2>
            <span class="status $status">$status</span>
        </div>
        <div id="issues-$counter" class="issues" style="display: none;">
"@
    if ($issues.Count -eq 0) {
        $html += "<p><em>Nincs probléma.</em></p>"
    } else {
        foreach ($issue in $issues) {
            $html += @"
            <div class="issue">
                <span class="file">$($issue.File)</span> <span class="line">[$($issue.Line)]</span>
                <span class="severity $($issue.Severity)">$($issue.Severity)</span>
                <br> $($issue.Message)
            </div>
"@
        }
    }
    $html += "</div></div>"
}

$html += @"
</body>
</html>
"@

Write-FileUtf8NoBom -Path $OutputPath -Content $html
Write-Host "Audit jelentés elkészült: $OutputPath"
