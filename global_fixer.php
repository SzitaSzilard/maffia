<?php
// global_fixer.php
// Ez a szkript végigmegy az src/Modules mappán, és elvégzi a biztonsági cseréket:
// 1. rand() / mt_rand() -> random_int()
// 2. array_rand($arr) -> random_int(0, count($arr) - 1)
// 3. (int) $this->db->fetchOne(...) -> Biztonságos Exception check
// (A Ledger bypass-t az AmmoFactory-ban manuálisan fogjuk fixálni, mert logikai átalakítást is igényel.)

$dir = new RecursiveDirectoryIterator('c:/laragon/www/Netmafia/src/Modules');
$iterator = new RecursiveIteratorIterator($dir);
$phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$fixRngCount = 0;
$fixCastCount = 0;

foreach ($phpFiles as $fileInfo) {
    $file = $fileInfo[0];
    $content = file_get_contents($file);
    $originalContent = $content;
    
    // 1. RNG CSERÉK
    // rand(x, y) vagy mt_rand(x, y) -> random_int(x, y)
    $content = preg_replace('/\b(mt_rand|rand)\s*\(/i', 'random_int(', $content, -1, $count1);
    
    // array_rand($arr) -> Mivel a logika bonyolultabb (tömböt vár, kulcsot ad), 
    // egy biztonságos wrapper array_keys-zel:
    // array_rand($arr)  =>  array_keys($arr)[random_int(0, count($arr) - 1)]
    $content = preg_replace(
        '/\barray_rand\s*\(\s*(\$[a-zA-Z0-9_]+)\s*\)/i',
        'array_keys($1)[random_int(0, max(0, count($1) - 1))]',
        $content,
        -1,
        $count2
    );
    
    $fixRngCount += $count1 + $count2;

    // 2. SILENT CASTING CSERÉK
    // Keresünk ilyen mintákat: $val = (int) $this->db->fetchOne(...);
    // Vagy: return (int) $this->db->fetchOne(...)
    
    // Ezt kicsit ügyesebben csináljuk: Mivel a fetchOne hívás többsoros is lehet a query miatt,
    // a legegyszerűbb, ha a metódus definíciót hagyjuk meg, de készítünk egy statikus segédfüggvényt
    // VAGY manuálisan cseréljük a fájlokat, de ez 35 hely.
    
    // Készítünk egy egyszerű Regex State Machine-t:
    $lines = explode("\n", $content);
    $newLines = [];
    $inFetchOne = false;
    $fetchOneVar = '';
    $fetchOneString = '';
    $isReturn = false;
    
    foreach ($lines as $i => $line) {
        // Keressük az (int) fetchOne kezdését
        if (preg_match('/^(\s*)(?:(\$[a-zA-Z0-9_]+)\s*=\s*)?\(int\)\s*\$this->db->fetchOne\(/i', $line, $matches) || 
            preg_match('/^(\s*)(return)\s*\(int\)\s*\$this->db->fetchOne\(/i', $line, $matches)) {
            
            $indent = $matches[1];
            $varName = isset($matches[2]) && $matches[2] !== 'return' ? $matches[2] : null;
            $isReturn = isset($matches[2]) && $matches[2] === 'return';
            
            // Ha ez egy egysoros fetchOne
            if (strpos($line, ');') !== false) {
                // Csere menete egysorosnál
                $cleanFetch = preg_replace('/\(int\)\s*\$this->db->fetchOne\(/i', '$this->db->fetchOne(', $line);
                if ($varName) {
                    // $var =  alakról leszedjük a változó beállítást a cleanFetch-ből
                    $cleanFetch = preg_replace('/^\s*\$[a-zA-Z0-9_]+\s*=\s*/', '', trim($cleanFetch));
                    $newLines[] = $indent . '$__fetchResult = ' . $cleanFetch;
                    $newLines[] = $indent . 'if ($__fetchResult === false) {';
                    $newLines[] = $indent . '    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");';
                    $newLines[] = $indent . '}';
                    $newLines[] = $indent . $varName . ' = (int) $__fetchResult;';
                } elseif ($isReturn) {
                    $cleanFetch = preg_replace('/^\s*return\s*/', '', trim($cleanFetch));
                    $newLines[] = $indent . '$__fetchResult = ' . $cleanFetch;
                    $newLines[] = $indent . 'if ($__fetchResult === false) {';
                    $newLines[] = $indent . '    throw new \Netmafia\Shared\Exceptions\GameException("A kért adat nem található az adatbázisban!");';
                    $newLines[] = $indent . '}';
                    $newLines[] = $indent . 'return (int) $__fetchResult;';
                }
                $fixCastCount++;
                continue;
            } else {
                // Többsoros fetchOne - Nem nyerő regex-szel parseolni a zárójeleket.
                // Erre a PHP kódra manuális replace a legjobb (nem sok van, amit több sorba törtek)
                // Hagyjuk érintetlenül a bonyolultakat, azt majd manuálisan!
                $newLines[] = $line;
            }
        } else {
            $newLines[] = $line;
        }
    }
    
    $newContent = implode("\n", $newLines);
    
    if ($newContent !== $originalContent) {
        file_put_contents($file, $newContent);
        echo "✅ Módosítva: $file\n";
    }
}

echo "===============================\n";
echo "Sikeres RNG javítások: $fixRngCount\n";
echo "Sikeres Type Cast javítások (egysorosak): $fixCastCount\n";
echo "A többsoros fetchOne hívásokat manuálisan kell átírni a biztonság kedvéért.\n";
