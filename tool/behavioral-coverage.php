<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$unexpectedSurfaces = [];

if (is_dir($root . '/src/Controller')) {
    $unexpectedSurfaces[] = 'src/Controller';
}
if (is_dir($root . '/templates')) {
    $unexpectedSurfaces[] = 'templates';
}
if (is_dir($root . '/assets')) {
    $unexpectedSurfaces[] = 'assets';
}

if ([] !== $unexpectedSurfaces) {
    fwrite(STDERR, sprintf(
        "Inspecting behavioral coverage inventory must be updated for newly discovered user-visible surfaces: %s\n",
        implode(', ', $unexpectedSurfaces),
    ));
    exit(1);
}

$coverageDirectory = $root . '/var/coverage';
if (!is_dir($coverageDirectory) && !mkdir($coverageDirectory, 0775, true) && !is_dir($coverageDirectory)) {
    fwrite(STDERR, "Unable to create var/coverage.\n");
    exit(1);
}

$emptyInventory = ['eligible' => [], 'covered' => []];
$evidence = [
    'schema' => 'behavioral-ui-coverage-v2',
    'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    'producer' => [
        'kind' => 'repository_script',
        'script' => 'test:behavioral-coverage',
    ],
    'dimensions' => [
        'functional' => $emptyInventory,
        'behavioral' => $emptyInventory,
        'ui' => $emptyInventory,
        'critical' => $emptyInventory,
    ],
];

file_put_contents(
    $coverageDirectory . '/behavioral-ui.json',
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

fwrite(STDOUT, "Inspecting exposes no user-visible HTTP/UI surface; behavioral inventories are empty and complete.\n");
