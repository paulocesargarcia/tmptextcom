<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Utils.php';

class Storage {
    public static function save($content, $filename = null, $mimeType = 'text/plain') {
        $uuid = Utils::generateUUID();
        $filePath = FILES_DIR . '/' . $uuid;
        $metaPath = META_DIR . '/' . $uuid . '.json';

        if (file_put_contents($filePath, $content) === false) {
            return false;
        }

        $meta = [
            'uuid' => $uuid,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'created_at' => time(),
            'expires_at' => time() + (EXPIRATION_DAYS * 86400)
        ];

        if (file_put_contents($metaPath, json_encode($meta)) === false) {
            unlink($filePath);
            return false;
        }

        return $meta;
    }

    public static function get($uuid) {
        $metaPath = META_DIR . '/' . $uuid . '.json';
        $filePath = FILES_DIR . '/' . $uuid;

        if (!file_exists($metaPath) || !file_exists($filePath)) {
            return null;
        }

        $meta = json_decode(file_get_contents($metaPath), true);
        if (!$meta) {
            return null;
        }

        // Check expiration
        if (time() > $meta['expires_at']) {
            self::delete($uuid);
            return null;
        }

        $meta['content'] = file_get_contents($filePath);
        return $meta;
    }

    public static function delete($uuid) {
        $filePath = FILES_DIR . '/' . $uuid;
        $metaPath = META_DIR . '/' . $uuid . '.json';

        if (file_exists($filePath)) {
            unlink($filePath);
        }
        if (file_exists($metaPath)) {
            unlink($metaPath);
        }
    }

    public static function cleanup() {
        $files = scandir(META_DIR);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $uuid = str_replace('.json', '', $file);
            $metaPath = META_DIR . '/' . $file;
            $meta = json_decode(file_get_contents($metaPath), true);

            if ($meta && time() > $meta['expires_at']) {
                self::delete($uuid);
            }
        }
    }
}
