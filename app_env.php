<?php
declare(strict_types=1);

/** Local, per-copy configuration stored beside the application. */
function appEnvPath(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.env';
}

function appEnvRead(): array
{
    $path = appEnvPath();
    if (!is_file($path)) return [];

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new RuntimeException('Programmi .env seadete faili ei saanud lugeda.');
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) continue;
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $decoded = json_decode($value, true);
            if (is_string($decoded)) $value = $decoded;
        } elseif (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            $value = substr($value, 1, -1);
        }
        $values[$name] = $value;
    }
    return $values;
}

function appEnvWrite(array $values): void
{
    $lines = [
        '# Selle programmi koopia kohalikud seaded. Hoia fail privaatsena.',
        '# Iga eraldi programm kasutab enda .env faili.',
    ];
    ksort($values, SORT_STRING);
    foreach ($values as $name => $value) {
        if (!is_string($name) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) continue;
        $lines[] = $name . '=' . json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    $path = appEnvPath();
    if (file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Programmi kohalikku .env seadete faili ei saanud salvestada.');
    }
    @chmod($path, 0600);
}

function appEnvSetMany(array $updates): void
{
    appEnvWrite(array_replace(appEnvRead(), $updates));
}

function appEnvEnsureFile(): void
{
    if (is_file(appEnvPath())) return;
    appEnvWrite([
        'APP_GMAIL_ADDRESS' => '',
        'APP_GMAIL_SENDER_NAME' => '',
        'APP_GMAIL_APP_PASSWORD' => '',
    ]);
}
