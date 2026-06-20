<?php
// Динамический PWA-манифест: имя, цвета и т.д. берутся из config.json
// (через config.php), чтобы переименование проекта в конфиге меняло и ярлык PWA.
require_once __DIR__ . '/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$manifest = [
    'name'             => SITE_NAME,
    'short_name'       => defined('PWA_SHORT_NAME') ? PWA_SHORT_NAME : SITE_NAME,
    'description'      => defined('PWA_DESCRIPTION') ? PWA_DESCRIPTION : SITE_DESCRIPTION,
    'start_url'        => './',
    'scope'           => './',
    'display'          => 'standalone',
    'background_color' => defined('PWA_BG_COLOR') ? PWA_BG_COLOR : '#1e1f22',
    'theme_color'      => defined('STATUS_BAR_COLOR') ? STATUS_BAR_COLOR : (defined('PWA_THEME_COLOR') ? PWA_THEME_COLOR : '#2d7dff'),
    'orientation'      => 'any',
    'lang'             => defined('APP_LANG') ? APP_LANG : 'ru',
    'icons'            => [
        [
            'src'     => 'icon_tC_192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => 'icon_tC_512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
