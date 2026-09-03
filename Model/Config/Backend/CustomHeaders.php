<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Entry gate and storage format for the admin's custom outbound HTTP header
 * table. Rows are re-keyed `_1`, `_2`, … on save because AbstractFieldArray
 * renders from a row-keyed array and the POST's own keys are wall-clock
 * timestamps, which would rewrite the whole stored value on every save.
 */
class CustomHeaders extends Value
{
    /**
     * RFC 7230 token — the only characters a header field name may contain.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/';

    /**
     * Printable ASCII only. `\z` rather than `$`, which would let a trailing
     * newline through — the response-splitting byte this exists to refuse.
     */
    private const VALUE_PATTERN = '/^[\x20-\x7E]+\z/';

    /**
     * Names the integration sets itself, plus the proxy-identity headers a
     * merchant must not be able to restate from here.
     */
    private const RESERVED_NAMES = [
        'host',
        'content-type',
        'content-length',
        'accept',
        'accept-language',
        'x-api-key',
        'two-delegated-authority-token',
        'x-forwarded-for',
        'x-real-ip',
    ];

    public static function isUsableName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1
            && !in_array(strtolower($name), self::RESERVED_NAMES, true);
    }

    public static function isSendableValue(string $value): bool
    {
        return preg_match(self::VALUE_PATTERN, $value) === 1;
    }

    /**
     * The flag is `'1'`/`''` because Prototype's setValue() ticks the checkbox
     * on any truthy string, and `'0'` is truthy in JavaScript.
     *
     * @param mixed $row
     * @return array{name: string, value: string, send_from_browser: string}
     */
    public static function normaliseRow($row): array
    {
        $row = is_array($row) ? $row : [];

        return [
            'name' => trim((string)($row['name'] ?? '')),
            // Spaces and tabs only: a stray control byte has to survive to be
            // refused by name rather than silently stripped here.
            'value' => trim((string)($row['value'] ?? ''), " \t"),
            'send_from_browser' => empty($row['send_from_browser']) ? '' : '1',
        ];
    }

    /**
     * @param string $stored
     * @return array<mixed>
     */
    public static function decode(string $stored): array
    {
        if (trim($stored) === '') {
            return [];
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $posted = $this->getValue();
        if (is_array($posted)) {
            $this->setValue($this->serialiseRows($posted));
        }

        return parent::beforeSave();
    }

    /**
     * @return $this
     */
    protected function _afterLoad()
    {
        $stored = $this->getValue();
        if (is_array($stored)) {
            return $this;
        }

        $rows = [];
        foreach (self::decode((string)$stored) as $key => $row) {
            $rows[(string)$key] = self::normaliseRow($row);
        }
        $this->setValue($rows);

        return $this;
    }

    /**
     * A malformed row is refused rather than stored: a header that cannot be
     * sent looks identical in the admin to one that works.
     *
     * @param array<mixed> $posted
     * @throws LocalizedException
     */
    private function serialiseRows(array $posted): string
    {
        unset($posted['__empty']);

        $rows = [];
        $seen = [];
        $position = 0;
        foreach ($posted as $row) {
            $position++;
            $row = self::normaliseRow($row);
            if ($row['name'] === '' && $row['value'] === '') {
                continue;
            }

            $this->assertRowIsSendable($row, $position);

            $key = strtolower($row['name']);
            if (isset($seen[$key])) {
                throw new LocalizedException(
                    __('Custom headers: "%1" is listed more than once. Give each header one row.', $row['name'])
                );
            }
            $seen[$key] = true;

            $rows['_' . (count($rows) + 1)] = $row;
        }

        if ($rows === []) {
            return '';
        }

        // Unreachable while every value is printable ASCII, and kept so that
        // loosening that rule cannot silently store an empty table instead.
        $encoded = json_encode($rows);
        if ($encoded === false) {
            throw new LocalizedException(
                __('Custom headers: the table could not be stored. Check the values for stray characters.')
            );
        }

        return $encoded;
    }

    /**
     * @param array{name: string, value: string, send_from_browser: string} $row
     * @throws LocalizedException
     */
    private function assertRowIsSendable(array $row, int $position): void
    {
        if ($row['name'] === '') {
            throw new LocalizedException(
                __('Custom headers: row %1 has a value but no header name.', $position)
            );
        }

        if ($row['value'] === '') {
            throw new LocalizedException(
                __('Custom headers: "%1" has no value. Give it one, or remove the row.', $row['name'])
            );
        }

        if (preg_match(self::NAME_PATTERN, $row['name']) !== 1) {
            throw new LocalizedException(
                __('Custom headers: "%1" is not a valid HTTP header name.', $row['name'])
            );
        }

        if (in_array(strtolower($row['name']), self::RESERVED_NAMES, true)) {
            throw new LocalizedException(
                __('Custom headers: "%1" is set by the extension itself and cannot be overridden.', $row['name'])
            );
        }

        if (!self::isSendableValue($row['value'])) {
            throw new LocalizedException(
                __(
                    'Custom headers: the value for "%1" may only contain printable ASCII characters — no '
                    . 'line breaks, control characters, or non-ASCII text.',
                    $row['name']
                )
            );
        }
    }
}
