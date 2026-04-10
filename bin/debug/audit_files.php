<?php
// audit_files.php

// 1. Get all PHP classes in src
$dir = new RecursiveDirectoryIterator('src');
$iterator = new RecursiveIteratorIterator($dir);
$phpFiles = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$definedClasses = [];

foreach ($phpFiles as $file) {
    $content = file_get_contents($file[0]);
    if (preg_match('/namespace\s+(.+?);/', $content, $nsMatch) && preg_match('/class\s+(\w+)/', $content, $classMatch)) {
        $fullClassName = $nsMatch[1] . '\\' . $classMatch[1];
        $definedClasses[] = $fullClassName;
    }
}

// 2. Scan config files for references
$configFiles = ['config/container.php', 'config/routes.php', 'config/middleware.php'];
$configContent = '';
foreach ($configFiles as $cf) {
    if (file_exists($cf)) {
        $configContent .= file_get_contents($cf);
    }
}

// 3. Check for usage
$potentiallyUnused = [];
foreach ($definedClasses as $class) {
    // strict check? or just grep?
    // grep is safer because of aliases "use X as Y"
    // simplistic check: is the class name (without namespace) in the config?
    // Better: is the Full Class Name in the config?
    // OR is it imported via "use"?
    
    // We are looking for entry points (Actions, Services) that MUST be in container/routes.
    // Domain entities, ValueObjects, Exceptions won't be in container usually.
    
    // Filter out: Exceptions, ValueObjects, Entities implies -> skip if folder contains "ValueObject" or "Entity" or "Exception"
    if (strpos($class, 'Exception') !== false || strpos($class, 'ValueObject') !== false || strpos($class, 'Entity') !== false) {
        continue;
    }

    if (strpos($configContent, $class) === false) {
         // Check if it is a Service or Action
         if (strpos($class, 'Service') !== false || strpos($class, 'Action') !== false) {
             $potentiallyUnused[] = $class;
         }
    }
}

echo "Potentially Unused/Unregistered Services & Actions:\n";
foreach ($potentiallyUnused as $class) {
    echo "- $class\n";
}
