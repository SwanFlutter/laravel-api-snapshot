<?php

declare(strict_types=1);

/**
 * Print one full request item, or the folder tree, from a generated collection.
 * Usage: php devtools/show_item.php <collection.json> ["METHOD path-part"] [full]
 */
$col = json_decode((string) file_get_contents($argv[1] ?? ''), true);
$want = $argv[2] ?? '';
$full = ($argv[3] ?? '') === 'full';

if (! is_array($col)) {
    fwrite(STDERR, "unreadable collection\n");

    exit(1);
}

if ($want === '') {
    $walk = function (array $items, string $pad = '') use (&$walk): void {
        foreach ($items as $item) {
            if (isset($item['request'])) {
                echo $pad, '- ', $item['request']['method'], ' ', $item['name'], "\n";
                continue;
            }

            echo $pad, '+ ', $item['name'], ' (', count($item['item'] ?? []), ")\n";
            $walk($item['item'] ?? [], $pad . '  ');
        }
    };

    $walk($col['item'] ?? []);

    exit(0);
}

$found = null;

$walk = function (array $items) use (&$walk, &$found, $want): void {
    foreach ($items as $item) {
        if ($found !== null) {
            return;
        }

        if (isset($item['request'])) {
            $label = $item['request']['method'] . ' ' . strtok($item['request']['url']['raw'] ?? '', '?');

            if (stripos($label, $want) !== false) {
                $found = $item;
            }

            continue;
        }

        $walk($item['item'] ?? []);
    }
};

$walk($col['item'] ?? []);

if ($found === null) {
    fwrite(STDERR, "no item matching: {$want}\n");

    exit(1);
}

echo json_encode(
    $full ? $found : array_intersect_key($found, array_flip(['name', 'request'])) + [],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
), "\n";
