<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Utils.php';

class RateLimiter {
    public static function check($ip) {
        $ipHash = md5($ip);
        $file = RATE_LIMITS_DIR . '/' . $ipHash;

        $now = time();
        $uploads = [];

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $uploads = json_decode($content, true) ?: [];
        }

        // Filter out old uploads
        $uploads = array_filter($uploads, function($timestamp) use ($now) {
            return $timestamp > ($now - RATE_LIMIT_WINDOW);
        });

        if (count($uploads) >= RATE_LIMIT_MAX) {
            return false;
        }

        return true;
    }

    public static function record($ip) {
        $ipHash = md5($ip);
        $file = RATE_LIMITS_DIR . '/' . $ipHash;

        $now = time();
        $uploads = [];

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $uploads = json_decode($content, true) ?: [];
        }

        $uploads[] = $now;

        // Filter out old uploads
        $uploads = array_filter($uploads, function($timestamp) use ($now) {
            return $timestamp > ($now - RATE_LIMIT_WINDOW);
        });

        file_put_contents($file, json_encode(array_values($uploads)));
    }
}
