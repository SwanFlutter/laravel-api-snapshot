<?php

declare(strict_types=1);

/**
 * Audit a generated collection for the things Postman cares about.
 * Usage: php devtools/inspect_collection.php <path-to-collection.json>
 */
$path = $argv[1] ?? null;

if (! $path || ! is_file($path)) {
    fwrite(STDERR, "usage: php inspect_collection.php <collection.json>\n");

    exit(1);
}

$json  = file_get_contents($path);
$col   = json_decode($json, true);

if (! is_array($col)) {
    fwrite(STDERR, 'invalid JSON: '
        . match (json_last_error()) {
            JSON_ERROR_UTF8 => 'malformed UTF-8 characters',
            default => json_last_error_msg(),
        } . "\n");

    exit(1);
}

echo "valid JSON          : yes\n";
echo "schema              : ", $col['info']['schema'] ?? '(missing)', "\n";
echo "_postman_id         : ", $col['info']['_postman_id'] ?? '(missing)', "\n";
echo "id is uuid v4       : ", preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) ($col['info']['_postman_id'] ?? '')) ? 'yes' : 'NO', "\n";
echo "name                : ", $col['info']['name'] ?? '(missing)', "\n";

$requests = [];
$folders  = [];

$walk = function (array $items, string $trail = '') use (&$walk, &$requests, &$folders): void {
    foreach ($items as $item) {
        if (isset($item['request'])) {
            $requests[] = [$trail, $item];
            continue;
        }

        $name    = (string) ($item['name'] ?? '');
        $sub     = $trail === '' ? $name : $trail . ' › ' . $name;
        $folders[$sub] = ($folders[$sub] ?? 0) + count(array_filter($item['item'] ?? [], fn ($i) => isset($i['request'])));
        $walk($item['item'] ?? [], $sub);
    }
};

$walk($col['item'] ?? []);

echo "requests            : ", count($requests), "\n";
echo "folders             : ", count($folders), "\n";
echo "collection vars     : ", implode(', ', array_map(fn ($v) => (string) ($v['key'] ?? '?'), $col['variable'] ?? [])) ?: '(none)', "\n";
echo "collection auth     : ", $col['auth']['type'] ?? '(none)', "\n";

// Postman resolves {{var}} from collection/variable, so every reference needs a definition.
$vars    = array_map(fn ($v) => (string) ($v['key'] ?? ''), $col['variable'] ?? []);
$used    = [];
$missing = [];

foreach ($requests as [, $item]) {
    $blob = json_encode($item['request'], JSON_UNESCAPED_UNICODE);
    preg_match_all('/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/', (string) $blob, $m);

    foreach ($m[1] as $ref) {
        $used[$ref] = ($used[$ref] ?? 0) + 1;

        if (! in_array($ref, $vars, true)) {
            $missing[$ref] = ($missing[$ref] ?? 0) + 1;
        }
    }
}

echo "\nvariable references :\n";
foreach ($used as $ref => $count) {
    echo "  ", str_pad((string) $ref, 16), " used ", $count, "x", isset($missing[$ref]) ? "  <-- NOT DEFINED" : "", "\n";
}

// Auth shape.
$byAuth = [];
foreach ($requests as [, $item]) {
    $byAuth[$item['request']['auth']['type'] ?? 'inherit'] = ($byAuth[$item['request']['auth']['type'] ?? 'inherit'] ?? 0) + 1;
}
echo "\nauth per request    : ", json_encode($byAuth), "\n";

// Body / url shape.
$modes = [];
$noUrl = 0;
$badPath = 0;
foreach ($requests as [, $item]) {
    $req  = $item['request'];
    $modes[$req['body']['mode'] ?? 'none'] = ($modes[$req['body']['mode'] ?? 'none'] ?? 0) + 1;

    if (! isset($req['url']['raw'], $req['url']['host'], $req['url']['path'])) {
        $noUrl++;
    }

    foreach ($req['url']['path'] ?? [] as $seg) {
        if (is_array($seg) && ($seg['type'] ?? '') === 'variable' && ! str_starts_with((string) ($seg['value'] ?? ''), ':')) {
            $badPath++;
        }
    }
}
echo "body modes          : ", json_encode($modes), "\n";
echo "urls missing parts  : ", $noUrl, "\n";
echo "path vars not ':x'  : ", $badPath, "\n";

// Raw bodies must be valid JSON strings, pretty-printed.
$badRaw = 0;
foreach ($requests as [, $item]) {
    $raw = $item['request']['body']['raw'] ?? null;

    if ($raw !== null && json_decode($raw, true) === null && json_last_error() !== JSON_ERROR_NONE) {
        $badRaw++;
        echo "  bad raw body: ", substr((string) $raw, 0, 80), "\n";
    }
}
echo "raw bodies not JSON : ", $badRaw, "\n";

// Descriptions must all survive encoding (this was the crash).
$badUtf8 = 0;
foreach ($requests as [, $item]) {
    if (! mb_check_encoding((string) ($item['request']['description'] ?? ''), 'UTF-8')) {
        $badUtf8++;
    }
}
echo "descriptions invalid: ", $badUtf8, "\n";

echo "\ntop folders:\n";
arsort($folders);

foreach (array_slice($folders, 0, 15, true) as $name => $count) {
    echo "  ", str_pad((string) $name, 42), " ", $count, "\n";
}

echo "\nsamples:\n";

foreach (array_slice($requests, 0, 3) as [, $item]) {
    $req = $item['request'];
    echo "--- ", $req['method'], ' ', $req['url']['raw'] ?? '', "\n";

    if (isset($req['body']['raw'])) {
        echo substr((string) $req['body']['raw'], 0, 320), "\n";
    } elseif (isset($req['body']['formdata'])) {
        foreach (array_slice($req['body']['formdata'], 0, 8) as $f) {
            echo "  ", str_pad((string) $f['key'], 24), " ", $f['type'], ' = ', (string) ($f['src'] !== [] ? json_encode($f['src']) : $f['value'] ?? ''), "\n";
        }
    }

    foreach (array_slice($req['url']['query'] ?? [], 0, 8) as $q) {
        echo "  ? ", str_pad((string) $q['key'], 24), ' = ', (string) ($q['value'] ?? ''), "\n";
    }
}
