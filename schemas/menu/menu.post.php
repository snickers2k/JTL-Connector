<?php
defined('BOOTSTRAP') or die('Access denied');

$schema['central']['jtl_connector'] = [
    'items' => [
        'jtl_connector_manage' => [
            'href' => 'jtl_connector.manage',
            'position' => 999,
        ],
    ],
    'position' => 999,
];

return $schema;
