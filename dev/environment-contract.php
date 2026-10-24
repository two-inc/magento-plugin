<?php

/**
 * Where this plugin declares the Environment setting (TWO-26613). Read only
 * by this repo's dev/environment-parity.php, which extracts the contract from
 * this repo's own code into dev/environment-contract.json; the other plugins
 * compare that JSON as data. After changing this file or the code it names,
 * run `php dev/environment-parity.php --write` and commit the JSON. Runs
 * inside that script and uses its helpers; declare no functions or classes.
 */

return [
    'platform' => 'magento',
    'cases' => 'Test/Unit/Model/Config/_files/environment-cases.json',
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
