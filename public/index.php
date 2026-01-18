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
        echo "404 Not Found or Expired";
        exit;
    }

    $ip = Utils::getClientIP();
    Logger::info("View: $uuid from $ip");

    $mimeType = $meta['mime_type'];
    $content = $meta['content'];

    // If it's an image, show preview
    if (strpos($mimeType, 'image/') === 0) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Preview - <?php echo Utils::escape($meta['filename']); ?></title>
            <style>
                body { background: #1e1e1e; color: #fff; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                img { max-width: 90%; max-height: 90%; border-radius: 8px; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
                .info { position: absolute; top: 10px; left: 10px; font-family: sans-serif; opacity: 0.7; }
            </style>
        </head>
        <body>
            <div class="info">
                <?php echo Utils::escape($meta['filename']); ?> (<?php echo $mimeType; ?>)<br>
                Expires: <?php echo date('Y-m-d H:i', $meta['expires_at']); ?>
            </div>
            <img src="data:<?php echo $mimeType; ?>;base64,<?php echo base64_encode($content); ?>">
        </body>
        </html>
        <?php
        exit;
    }

    // If it's text or code
    if (strpos($mimeType, 'text/') === 0 || $mimeType === 'application/json' || $mimeType === 'application/javascript' || $mimeType === 'application/xml') {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo Utils::escape($meta['filename']); ?></title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github-dark.min.css">
            <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
            <script>hljs.highlightAll();</script>
            <style>
                body { background: #0d1117; color: #c9d1d9; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; margin: 0; padding: 20px; }
                pre { background: #161b22; padding: 16px; border-radius: 6px; overflow: auto; line-height: 1.45; }
                code { font-family: ui-monospace,SFMono-Regular,SF Mono,Menlo,Consolas,Liberation Mono,monospace; font-size: 85%; }
                .meta { margin-bottom: 20px; font-size: 0.9em; opacity: 0.8; border-bottom: 1px solid #30363d; padding-bottom: 10px; }
            </style>
        </head>
        <body>
            <div class="meta">
                <strong>File:</strong> <?php echo Utils::escape($meta['filename']); ?> |
                <strong>Type:</strong> <?php echo $mimeType; ?> |
                <strong>Expires:</strong> <?php echo date('Y-m-d H:i', $meta['expires_at']); ?> |
                <a href="?download=1" style="color: #58a6ff;">Download Raw</a>
            </div>
            <?php if (isset($_GET['download'])): ?>
                <?php
                header('Content-Type: ' . $mimeType);
                header('Content-Disposition: attachment; filename="' . $meta['filename'] . '"');
                echo $content;
                exit;
                ?>
            <?php endif; ?>
            <pre><code><?php echo Utils::escape($content); ?></code></pre>
        </body>
        </html>
        <?php
        exit;
    }

    // Default: Download
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $meta['filename'] . '"');
    echo $content;
    exit;
}

// --- Frontend logic (if no UUID matched and not a POST) ---
if ($uri === '/') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Text & File Storage</title>
        <style>
            body { font-family: sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; line-height: 1.6; background: #f4f4f9; }
            .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { margin-top: 0; color: #333; }
            textarea { width: 100%; height: 200px; padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            .form-group { margin-bottom: 20px; }
            input[type="file"] { display: block; margin-bottom: 10px; }
            button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 16px; }
            button:hover { background: #0056b3; }
            .footer { margin-top: 30px; font-size: 0.8em; color: #666; text-align: center; }
            code { background: #eee; padding: 2px 4px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Upload Text or File</h1>
            <p>Temporary storage (7 days). No database. Public access via UUID.</p>

            <form action="/" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Paste text:</label>
                    <textarea name="text" placeholder="Paste your logs or code here..."></textarea>
                </div>
                <div class="form-group">
                    <label>Or upload a file:</label>
                    <input type="file" name="file">
                </div>
                <button type="submit">Upload & Get URL</button>
            </form>

            <div style="margin-top: 40px;">
                <h3>CLI Usage (curl)</h3>
                <pre><code># Upload raw text
curl -X POST --data "hello world" <?php echo $baseUrl; ?>/api/upload

# Upload a file
curl -F "file=@path/to/file.txt" <?php echo $baseUrl; ?>/api/upload</code></pre>
            </div>
        </div>
        <div class="footer">
            &copy; <?php echo date('Y'); ?> Temporary Storage. All files expire in 7 days.
        </div>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(404);
echo "404 Not Found";
