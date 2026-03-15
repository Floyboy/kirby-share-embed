<?php

return [
    'freeze' => true,
    'ttl' => 60 * 60 * 24 * 30,
    'incompleteRetryTtl' => 60 * 15,
    'refresh-write-fields' => false,
    'web-image-safe-mode' => true,
    'web-image-allowed-hosts' => [],
    'web-image-require-license' => true,
    'storage' => [
        'path' => 'assets/share-embed',
        'root' => '',
        'url' => '',
    ],
    'icons' => [
        'path' => __DIR__ . '/icons',
    ],
    'youtube' => [
        'apiKey' => getenv('YOUTUBE_API_KEY') ?: '',
    ],
];
