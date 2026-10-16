<?php

declare(strict_types=1);

/**
 * Second-pass audit: things that mean the generator missed input on a real app.
 * Usage: php devtools/audit_gaps.php <collection.json>
 */
$col = json_decode((string) file_get_contents($argv[1] ?? ''), true);

if (! is_array($col)) {
    fwrite(STDERR, "unreadable collection\n");

    exit(1);
}

$requests = [];

$walk = function (array $items, string $folder = '') use (&$walk, &$requests): void {
    foreach ($items as $item) {
        if (isset($item['request'])) {
            $requests[] = [$folder, $item['name'] ?? '', $item['request']];
            continue;
        }

        $name = (string) ($item['name'] ?? '');
        $walk($item['item'] ?? [], $folder === '' ? $name : $folder . ' > ' . $name);
    }
};

$walk($col['item'] ?? []);

$seen = [];
$dupes = [];
$noBody = [];
$noQuery = [];
$emptyName = [];
$fileFields = 0;
$withBody = 0;

foreach ($requests as [$folder, $name, $req]) {
    $raw = strtok((string) ($req['url']['raw'] ?? ''), '?');
    $key = ($req['method'] ?? '?') . ' ' . $raw;

    if (isset($seen[$key])) {
        $dupes[$key] = [$seen[$key], $folder];
    } else {
        $seen[$key] = $folder;
    }

    if (trim((string) $name) === '') {
        $emptyName[] = $key;
    }

    $mode = $req['body']['mode'] ?? 'none';

    if ($mode === 'raw' || $mode === 'formdata' || $mode === 'urlencoded') {
        $withBody++;

        foreach (($req['body']['formdata'] ?? []) as $field) {
            if (($field['type'] ?? '') === 'file') {
                $fileFields++;
            }
        }
    }

    if (in_array($req['method'] ?? '', ['POST', 'PUT', 'PATCH'], true) && $mode === 'none' && ($req['body']['mode'] ?? null) === 'none') {
        $noBody[] = $key;
    }

    if (in_array($req['method'] ?? '', ['GET', 'DELETE'], true) && ! ($req['url']['query'] ?? [])) {
        $noQuery[] = $key;
    }
}

printf("requests            : %d\nduplicates          : %d\n", count($requests), count($dupes));
foreach (array_slice($dupes, 0, 12, true) as $key => $f) {
    echo "  {$key}   [{$f[0]}] vs [{$f[1]}]\n";
}

printf("\nwrite verbs with no body : %d\n", count($noBody));
foreach (array_slice($noBody, 0, 25) as $key) {
    echo "  {$key}\n";
}

printf("\nGET/DELETE with no query : %d\n", count($noQuery));
foreach (array_slice($noQuery, 0, 15) as $key) {
    echo "  {$key}\n";
}

printf("\nempty item names    : %d\n", count($emptyName));
printf("file fields         : %d\n", $fileFields);
printf("requests with body  : %d\n", $withBody);

// Persian descriptions must be present and RTL text kept as-is.
$persian = 0;
$descCount = 0;
foreach ($requests as [, , $req]) {
    $d = (string) ($req['description'] ?? '');
    if ($d !== '') {
        $descCount++;
        if (preg_match('/\p{Arabic}/u', $d)) {
            $persian++;
        }
    }
}
printf("\ndescriptions        : %d (with Persian text: %d)\n", $descCount, $persian);

// Docblock summaries actually picked up from the app.
$summarised = 0;
foreach ($requests as [, , $req]) {
    $d = explode("\n", (string) ($req['description'] ?? ''))[0];
    if ($d !== '' && ! str_starts_with($d, 'این') && ! str_starts_with($d, 'Executes')) {
        $summarised++;
    }
}
printf("with docblock summary: %d\n", $summarised);
