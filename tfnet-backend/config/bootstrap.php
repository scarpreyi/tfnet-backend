<?php

if (!function_exists('tfnet_load_dotenv')) {
    function tfnet_load_dotenv(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (substr($line, 0, 7) === 'export ') {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            if ($value !== '') {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                $isQuoted = ($first === '"' && $last === '"') || ($first === "'" && $last === "'");

                if ($isQuoted) {
                    $value = substr($value, 1, -1);
                }
            }

            $current = getenv($key);
            if ($current !== false && $current !== null && $current !== '') {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

tfnet_load_dotenv(__DIR__ . '/../.env');

if (!function_exists('tfnet_env')) {
    function tfnet_env(string $key, $default = null)
    {
        $value = getenv($key);

        if ($value === false || $value === null || $value === '') {
            if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
                $value = $_ENV[$key];
            } elseif (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
                $value = $_SERVER[$key];
            } else {
                return $default;
            }
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return $default;
            }
        }

        return $value;
    }
}

if (!function_exists('tfnet_send_json_headers')) {
    function tfnet_send_json_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }
}

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    tfnet_send_json_headers();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}
