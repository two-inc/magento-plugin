<?php

/**
 * Where this plugin declares the Environment setting, for
 * dev/environment-parity.php (TWO-26613), which loads this file in each of
 * the three plugin repos and compares what it finds. Runs inside that script
 * and uses its helpers; declare no functions or classes here.
 */

return [
    'platform' => 'magento',
    'cases' => 'Test/Unit/Model/Config/_files/environment-cases.json',
    'tests' => 'Test',
    'helpers' => [
        'file' => 'Model/Config/EnvironmentMode.php',
        'class' => 'EnvironmentMode',
        'consts' => ['modes' => 'MODES', 'default' => 'DEFAULT_MODE'],
        'methods' => ['isInternalEnvironmentsEnabled', 'recognise', 'normalise', 'options', 'migrate'],
        'options' => 'options',
        'read' => 'normalise',
    ],
    // The data patch deletes empty rows, which then read the config.xml
    // default; migrate() gives the same value for them.
    'migrate' => [
        'method' => 'migrate',
        'variants' => ['every scope' => ['args' => [], 'moves' => []]],
    ],
    'steps' => [
        [
            'file' => 'Setup/Patch/Data/NormaliseEnvironmentMode.php',
            'in' => ['NormaliseEnvironmentMode', 'apply'],
            'calls' => 'EnvironmentMode::migrate(',
        ],
    ],
    'declarations' => static function (array $p): array {
        $default = one(
            xml($p['file']('etc/config.xml'), 'etc/config.xml'),
            '/config/default/payment/two_payment/mode',
            'etc/config.xml payment/two_payment/mode'
        );
        $out = [
            'install_default' => [(string) $default],
            'labels' => labels($p['method']('Model/Config/Source/Mode.php', 'Mode', 'toOptionArray')),
            'field_label' => [],
            'description' => [],
        ];
        // Both admin forms declare the field: the base section and the
        // template every brand's section is built from.
        foreach (['etc/adminhtml/system.xml', 'etc/adminhtml/brand_form_template.xml'] as $path) {
            $field = one(xml($p['file']($path), $path), '//field[@id="mode"]', "{$path} field mode");
            $out['field_label'][] = (string) $field->label;
            $out['description'][] = (string) $field->comment;
        }
        return $out;
    },
];
