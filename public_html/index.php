<?php

require_once __DIR__ . '/../app/src/config.php';
require_once __DIR__ . '/../app/src/Utils.php';
require_once __DIR__ . '/../app/src/Storage.php';
require_once __DIR__ . '/../app/src/RateLimiter.php';
require_once __DIR__ . '/../app/src/Logger.php';

// Simple Router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Helper to get Base URL
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptName = $_SERVER['SCRIPT_NAME'];
$basePath = str_replace('\\', '/', dirname($scriptName));
$basePath = ($basePath === '/' || $basePath === '.') ? '' : $basePath;
$baseUrl = "$protocol://$host$basePath";

// Relative URI for routing
$relativeUri = $uri;
if ($basePath !== '' && strpos($uri, $basePath) === 0) {
    $relativeUri = substr($uri, strlen($basePath));
}
if ($relativeUri === '' || $relativeUri === false) $relativeUri = '/';

// Cleanup occasionally (1% chance)
if (rand(1, 100) === 1) {
    Storage::cleanup();
}

// --- CLI Script Serving ---
if ($relativeUri === '/cli.sh') {
    header('Content-Type: text/x-shellscript');
    $template = file_get_contents(__DIR__ . '/../app/src/cli.sh.template');
    echo str_replace('{{BASE_URL}}', $baseUrl, $template);
    exit;
}

// --- API/Upload Logic ---
if (($relativeUri === '/api/upload' || $relativeUri === '/') && $method === 'POST') {
    $ip = Utils::getClientIP();
    if (!RateLimiter::check($ip)) {
        Logger::error("Rate limit exceeded for IP: $ip");
        Utils::jsonResponse(['error' => 'Límite de velocidad excedido. Máximo 10 subidas por minuto.'], 429);
    }

    // CSRF Validation for web form
    if ($relativeUri === '/') {
        if (!Utils::validateCSRFToken($_POST['csrf_token'] ?? '')) {
            Logger::error("CSRF token validation failed for IP: $ip");
            Utils::jsonResponse(['error' => 'Validación de seguridad fallida.'], 403);
        }
    }

    $content = '';
    $filename = null;
    $mimeType = 'text/plain';

    // Handle multipart/form-data
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            if ($file['size'] > MAX_FILE_SIZE) {
                Utils::jsonResponse(['error' => 'El archivo es demasiado grande. Máximo 5MB.'], 400);
            }
            $content = file_get_contents($file['tmp_name']);
            $filename = $file['name'];
            $mimeType = $file['type'] ?: 'application/octet-stream';
        } else {
            Utils::jsonResponse(['error' => 'Error al subir el archivo: ' . $file['error']], 400);
        }
    } elseif (!empty($_POST['text'])) {
        $content = $_POST['text'];
        $filename = 'texto.txt';
    } else {
        // Handle raw body
        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > MAX_FILE_SIZE) {
            Utils::jsonResponse(['error' => 'El contenido es demasiado grande. Máximo 5MB.'], 400);
        }
        $content = file_get_contents('php://input');
        if (empty($content)) {
            Utils::jsonResponse(['error' => 'No se proporcionó contenido.'], 400);
        }
        $filename = 'crudo.txt';
    }

    $meta = Storage::save($content, $filename, $mimeType);
    if ($meta) {
        RateLimiter::record($ip);
        Logger::info("Upload successful: {$meta['uuid']} from $ip");
        $response = [
            'uuid' => $meta['uuid'],
            'url' => $baseUrl . '/' . $meta['uuid'],
            'expires_at' => date('Y-m-d', $meta['expires_at'])
        ];

        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || $relativeUri === '/api/upload') {
            Utils::jsonResponse($response);
        } else {
            header("Location: $baseUrl/{$meta['uuid']}");
            exit;
        }
    } else {
        Logger::error("Failed to save upload from $ip");
        Utils::jsonResponse(['error' => 'Error al guardar el contenido.'], 500);
    }
}

// --- View/Download/Raw Logic ---
$match = [];
if (preg_match('#^/([a-f0-9-]{36})(?:/(raw|download))?$#', $relativeUri, $match)) {
    $uuid = $match[1];
    $mode = $match[2] ?? null;

    $meta = Storage::get($uuid);
    if (!$meta) {
        http_response_code(404);
        echo "404 No encontrado o expirado";
        exit;
    }

    $ip = Utils::getClientIP();
    Logger::info("View: $uuid from $ip");

    // Friendly download URL and legacy query ?download=1
    if ($mode === 'download' || isset($_GET['download'])) {
        $downloadMime = $meta['mime_type'] ?: 'application/octet-stream';
        $downloadName = $meta['filename'] ?? $uuid;
        header('Content-Type: ' . $downloadMime);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        echo $meta['content'];
        exit;
    }

    // Raw view
    if ($mode === 'raw') {
        $rawMime = $meta['mime_type'] ?: 'text/plain; charset=utf-8';
        $rawName = $meta['filename'] ?? $uuid;
        header('Content-Type: ' . $rawMime);
        header('Content-Disposition: inline; filename="' . $rawName . '"');
        echo $meta['content'];
        exit;
    }

    $mimeType = $meta['mime_type'];
    $content = $meta['content'];
    $isImage = strpos($mimeType, 'image/') === 0;
    $isText = strpos($mimeType, 'text/') === 0 || in_array($mimeType, ['application/json', 'application/javascript', 'application/xml']);

    $downloadUrl = $baseUrl . '/' . $uuid . '/download';
    $rawUrl = $baseUrl . '/' . $uuid . '/raw';

    ?>
    <!DOCTYPE html>
    <html lang="es" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo Utils::escape($meta['filename']); ?> - TmpText</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github-dark.min.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');
            body { font-family: 'Inter', sans-serif; }
        </style>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        colors: {
                            border: "hsl(240 5.9% 90%)",
                            input: "hsl(240 5.9% 90%)",
                            ring: "hsl(240 5.9% 10%)",
                            background: "hsl(0 0% 100%)",
                            foreground: "hsl(240 10% 3.9%)",
                            primary: {
                                DEFAULT: "hsl(240 5.9% 10%)",
                                foreground: "hsl(0 0% 98%)",
                            },
                            muted: {
                                DEFAULT: "hsl(240 4.8% 95.9%)",
                                foreground: "hsl(240 3.8% 46.1%)",
                            },
                        }
                    }
                }
            }
        </script>
    </head>
    <body class="bg-slate-50 dark:bg-zinc-950 text-slate-900 dark:text-zinc-50 min-h-screen flex flex-col">
        <header class="border-b border-slate-200 dark:border-zinc-800 bg-white/50 dark:bg-zinc-900/50 backdrop-blur-md sticky top-0 z-10">
            <div class="max-w-6xl mx-auto px-4 h-14 flex items-center justify-between">
                <a href="/" class="font-semibold text-lg tracking-tight">TmpText</a>
                <div class="flex items-center gap-4 text-sm">
                    <span class="text-slate-500 dark:text-zinc-400 hidden sm:inline">Vence: <?php echo date('Y-m-d H:i', $meta['expires_at']); ?></span>
                    <a href="<?php echo $rawUrl; ?>" class="bg-slate-100 dark:bg-zinc-800 text-slate-900 dark:text-zinc-50 px-3 py-1.5 rounded-md font-medium hover:opacity-90 transition-opacity">Raw</a>
                    <a href="<?php echo $downloadUrl; ?>" class="bg-zinc-900 dark:bg-zinc-50 text-zinc-50 dark:text-zinc-900 px-3 py-1.5 rounded-md font-medium hover:opacity-90 transition-opacity">Descargar</a>
                </div>
            </div>
        </header>

        <main class="flex-1 max-w-6xl w-full mx-auto p-4 sm:p-6 lg:p-8">
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight mb-1"><?php echo Utils::escape($meta['filename']); ?></h1>
                <p class="text-sm text-slate-500 dark:text-zinc-400"><?php echo $mimeType; ?> • <?php echo strlen($content); ?> bytes</p>
            </div>

            <div class="rounded-lg border border-slate-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden shadow-sm">
                <?php if ($isImage): ?>
                    <div class="p-4 flex justify-center bg-slate-100 dark:bg-zinc-800/50">
                        <img src="data:<?php echo $mimeType; ?>;base64,<?php echo base64_encode($content); ?>" class="max-w-full h-auto rounded-md shadow-lg border border-slate-200 dark:border-zinc-700">
                    </div>
                <?php elseif ($isText): ?>
                    <pre class="p-0 m-0"><code class="hljs block p-6 !bg-transparent"><?php echo Utils::escape($content); ?></code></pre>
                    <script>hljs.highlightAll();</script>
                <?php else: ?>
                    <div class="p-12 text-center">
                        <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <h3 class="mt-2 text-sm font-semibold">Archivo Binario</h3>
                        <p class="mt-1 text-sm text-slate-500">Vista previa no disponible para este tipo de archivo.</p>
                        <div class="mt-6">
                            <a href="<?php echo $downloadUrl; ?>" class="inline-flex items-center rounded-md bg-zinc-900 dark:bg-zinc-50 px-3 py-2 text-sm font-semibold text-white dark:text-zinc-900 shadow-sm hover:opacity-90">Descargar Original</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="border-t border-slate-200 dark:border-zinc-800 py-6 text-center text-sm text-slate-500 dark:text-zinc-500 flex flex-col items-center gap-2">
            <div>&copy; <?php echo date('Y'); ?> TmpText. Almacenamiento temporal.</div>
            <div>Autor: Paulo Cesar Garcia • <a href="https://github.com/paulocesargarcia/tmptextcom" class="underline decoration-zinc-700">Repositorio</a></div>
            <div>IP: <?php echo Utils::getClientIP(); ?></div>
        </footer>
    </body>
    </html>
    <?php
    exit;
}

// --- Frontend logic (Home) ---
if ($relativeUri === '/') {
    $ip = Utils::getClientIP();
    $csrf_token = Utils::generateCSRFToken();
    ?>
    <!DOCTYPE html>
    <html lang="es" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TmpText - Almacenamiento Temporal de Archivos y Texto</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
            body { font-family: 'Inter', sans-serif; }
        </style>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        colors: {
                            background: "hsl(240 10% 3.9%)",
                            foreground: "hsl(0 0% 98%)",
                            primary: {
                                DEFAULT: "hsl(0 0% 98%)",
                                foreground: "hsl(240 5.9% 10%)",
                            },
                        }
                    }
                }
            }
        </script>
    </head>
    <body class="bg-zinc-950 text-zinc-50 min-h-screen flex flex-col items-center justify-center p-4">
        <div class="max-w-3xl w-full">
            <header class="mb-12 text-center">
                <h1 class="text-4xl font-bold tracking-tight mb-2">TmpText</h1>
                <p class="text-zinc-400">Almacenamiento seguro, temporal y sin base de datos para tus archivos.</p>
            </header>

            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-2xl">
                <form action="/" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div>
                        <label class="block text-sm font-medium mb-2 text-zinc-300">Pegar contenido de texto</label>
                        <textarea name="text" placeholder="Pegá tu código, logs o notas aquí..."
                            class="w-full h-48 bg-zinc-950 border border-zinc-800 rounded-lg p-4 text-sm font-mono focus:ring-2 focus:ring-zinc-700 outline-none transition-all resize-none"></textarea>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-4 items-center justify-between pt-4 border-t border-zinc-800">
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <label class="flex items-center gap-2 cursor-pointer bg-zinc-800 hover:bg-zinc-700 px-4 py-2 rounded-lg transition-colors text-sm font-medium">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                <span>Subir Archivo</span>
                                <input type="file" name="file" class="hidden" onchange="this.nextElementSibling.innerText = this.files[0].name">
                                <span class="text-xs text-zinc-500 ml-2 italic truncate max-w-[100px]"></span>
                            </label>
                        </div>
                        <button type="submit" class="w-full sm:w-auto bg-zinc-50 text-zinc-950 px-6 py-2.5 rounded-lg font-semibold hover:bg-zinc-200 transition-colors">
                            Guardar y Compartir
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-12 space-y-8 text-sm">
                <div class="space-y-3">
                    <h3 class="font-semibold text-zinc-300">CLI (Ejecución Remota)</h3>
                    <p class="text-zinc-500 leading-relaxed">Ejecutá sin instalar nada. Ideal para servidores.</p>
                    <div class="space-y-2">
                        <div class="bg-zinc-900 p-3 rounded-lg border border-zinc-800 font-mono text-[11px] overflow-x-auto">
                            <span class="text-zinc-400">bash <(curl -s "<?php echo $baseUrl; ?>/cli.sh") -t "hola mundo"</span>
                        </div>
                        <div class="bg-zinc-900 p-3 rounded-lg border border-zinc-800 font-mono text-[11px] overflow-x-auto">
                            <span class="text-zinc-400">cat app.log | bash <(curl -s "<?php echo $baseUrl; ?>/cli.sh")</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <h3 class="font-semibold text-zinc-300">Uso directo con curl</h3>
                    <p class="text-zinc-500 leading-relaxed">Acceso directo a la API para tus scripts.</p>
                    <div class="space-y-2">
                        <div class="bg-zinc-900 p-3 rounded-lg border border-zinc-800 font-mono text-[11px] overflow-x-auto">
                            <span class="text-zinc-400">curl -X POST --data "texto de prueba" <?php echo $baseUrl; ?>/api/upload</span>
                        </div>
                        <div class="bg-zinc-900 p-3 rounded-lg border border-zinc-800 font-mono text-[11px] overflow-x-auto">
                            <span class="text-zinc-400">curl -F "file=@app.log" <?php echo $baseUrl; ?>/api/upload</span>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="mt-16 text-center text-xs text-zinc-600 space-y-2 flex flex-col items-center">
                <div>Los archivos se eliminan después de 7 días. • Límite de 10/min por IP.</div>
                <div>Autor: Paulo Cesar Garcia • <a href="https://github.com/paulocesargarcia/tmptextcom" class="underline decoration-zinc-800">Repositorio</a></div>
                <div>IP: <?php echo $ip; ?></div>
            </footer>
        </div>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(404);
echo "404 No encontrado";
