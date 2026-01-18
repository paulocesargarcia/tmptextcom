<?php

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/Utils.php';
require_once __DIR__ . '/../src/Storage.php';
require_once __DIR__ . '/../src/RateLimiter.php';
require_once __DIR__ . '/../src/Logger.php';

// Simple Router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Cleanup occasionally (1% chance)
if (rand(1, 100) === 1) {
    Storage::cleanup();
}

// Helper to get Base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = "$protocol://$host";

// --- CLI Script Serving ---
if ($uri === '/cli.sh') {
    header('Content-Type: text/x-shellscript');
    $template = file_get_contents(__DIR__ . '/../src/cli.sh.template');
    echo str_replace('DEFAULT_URL="http://localhost:8000"', 'DEFAULT_URL="' . $baseUrl . '"', $template);
    exit;
}

// --- API/Upload Logic ---
if (($uri === '/api/upload' || $uri === '/') && $method === 'POST') {
    $ip = Utils::getClientIP();
    if (!RateLimiter::check($ip)) {
        Logger::error("Rate limit exceeded for IP: $ip");
        Utils::jsonResponse(['error' => 'Rate limit exceeded. Max 10 uploads per minute.'], 429);
    }

    $content = '';
    $filename = null;
    $mimeType = 'text/plain';

    // Handle multipart/form-data
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $content = file_get_contents($file['tmp_name']);
            $filename = $file['name'];
            $mimeType = $file['type'] ?: 'application/octet-stream';
        } else {
            Utils::jsonResponse(['error' => 'File upload error: ' . $file['error']], 400);
        }
    } elseif (!empty($_POST['text'])) {
        $content = $_POST['text'];
        $filename = 'text.txt';
    } else {
        // Handle raw body
        $content = file_get_contents('php://input');
        if (empty($content)) {
            Utils::jsonResponse(['error' => 'No content provided.'], 400);
        }
        $filename = 'raw.txt';
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

        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || $uri === '/api/upload') {
            Utils::jsonResponse($response);
        } else {
            header("Location: /{$meta['uuid']}");
            exit;
        }
    } else {
        Logger::error("Failed to save upload from $ip");
        Utils::jsonResponse(['error' => 'Failed to save content.'], 500);
    }
}

// --- View/Download Logic ---
$uuid = ltrim($uri, '/');
if (preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
    $meta = Storage::get($uuid);
    if (!$meta) {
        http_response_code(404);
        include_once 'error404.php'; // I'll keep it simple for now and just echo
        echo "404 Not Found or Expired";
        exit;
    }

    $ip = Utils::getClientIP();
    Logger::info("View: $uuid from $ip");

    if (isset($_GET['download'])) {
        header('Content-Type: ' . $meta['mime_type']);
        header('Content-Disposition: attachment; filename="' . $meta['filename'] . '"');
        echo $meta['content'];
        exit;
    }

    $mimeType = $meta['mime_type'];
    $content = $meta['content'];
    $isImage = strpos($mimeType, 'image/') === 0;
    $isText = strpos($mimeType, 'text/') === 0 || in_array($mimeType, ['application/json', 'application/javascript', 'application/xml']);

    ?>
    <!DOCTYPE html>
    <html lang="en" class="dark">
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
                    <span class="text-slate-500 dark:text-zinc-400 hidden sm:inline">Expires: <?php echo date('Y-m-d H:i', $meta['expires_at']); ?></span>
                    <a href="?download=1" class="bg-zinc-900 dark:bg-zinc-50 text-zinc-50 dark:text-zinc-900 px-3 py-1.5 rounded-md font-medium hover:opacity-90 transition-opacity">Download</a>
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
                        <h3 class="mt-2 text-sm font-semibold">Binary File</h3>
                        <p class="mt-1 text-sm text-slate-500">Preview not available for this file type.</p>
                        <div class="mt-6">
                            <a href="?download=1" class="inline-flex items-center rounded-md bg-zinc-900 dark:bg-zinc-50 px-3 py-2 text-sm font-semibold text-white dark:text-zinc-900 shadow-sm hover:opacity-90">Download Raw</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="border-t border-slate-200 dark:border-zinc-800 py-6 text-center text-sm text-slate-500 dark:text-zinc-500">
            &copy; <?php echo date('Y'); ?> TmpText. Temporary storage.
        </footer>
    </body>
    </html>
    <?php
    exit;
}

// --- Frontend logic (Home) ---
if ($uri === '/') {
    ?>
    <!DOCTYPE html>
    <html lang="en" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TmpText - Temporary File & Text Storage</title>
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
                <p class="text-zinc-400">Secure, temporary, database-less storage for your snippets and files.</p>
            </header>

            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-2xl">
                <form action="/" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium mb-2 text-zinc-300">Paste text content</label>
                        <textarea name="text" placeholder="Paste your code, logs or notes here..."
                            class="w-full h-48 bg-zinc-950 border border-zinc-800 rounded-lg p-4 text-sm font-mono focus:ring-2 focus:ring-zinc-700 outline-none transition-all resize-none"></textarea>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-4 items-center justify-between pt-4 border-t border-zinc-800">
                        <div class="flex items-center gap-2 w-full sm:w-auto">
                            <label class="flex items-center gap-2 cursor-pointer bg-zinc-800 hover:bg-zinc-700 px-4 py-2 rounded-lg transition-colors text-sm font-medium">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                <span>Upload File</span>
                                <input type="file" name="file" class="hidden" onchange="this.nextElementSibling.innerText = this.files[0].name">
                                <span class="text-xs text-zinc-500 ml-2 italic truncate max-w-[100px]"></span>
                            </label>
                        </div>
                        <button type="submit" class="w-full sm:w-auto bg-zinc-50 text-zinc-950 px-6 py-2.5 rounded-lg font-semibold hover:bg-zinc-200 transition-colors">
                            Save and Share
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-12 grid grid-cols-1 md:grid-cols-2 gap-8 text-sm">
                <div class="space-y-3">
                    <h3 class="font-semibold text-zinc-300">Remote CLI</h3>
                    <p class="text-zinc-500 leading-relaxed">Execute without installing. Just pipe your output or files.</p>
                    <div class="bg-zinc-900 p-3 rounded-lg border border-zinc-800 font-mono text-[11px] overflow-x-auto">
                        <span class="text-zinc-400">bash <(curl -s "<?php echo $baseUrl; ?>/cli.sh") -t "hello"</span>
                    </div>
                </div>
                <div class="space-y-3">
                    <h3 class="font-semibold text-zinc-300">Quick curl</h3>
                    <p class="text-zinc-500 leading-relaxed">Direct API access for your scripts and automations.</p>
                    <div class="bg-zinc-900 p-3 rounded-lg border border-zinc-800 font-mono text-[11px] overflow-x-auto">
                        <span class="text-zinc-400">curl -F "file=@app.log" <?php echo $baseUrl; ?>/api/upload</span>
                    </div>
                </div>
            </div>

            <footer class="mt-16 text-center text-xs text-zinc-600">
                All files are encrypted on rest and deleted after 7 days. • Rate limited to 10/min per IP.
            </footer>
        </div>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(404);
echo "404 Not Found";
