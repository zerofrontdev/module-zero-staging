<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model;

/**
 * Field values as a change stores them and as the admin enters them: the
 * same value whichever way it was written ("27", "27.00", 27.0000 from the
 * database), and a short text of it for lists.
 */
class Values
{
    /**
     * A value from the change form, in the field's type. Empty text and an
     * empty price are null: the field is cleared (a special price removed).
     */
    public function fromInput(mixed $value, string $type): mixed
    {
        switch ($type) {
            case 'boolean':
                return $value === '1' || $value === 1 || $value === true ? 1 : 0;
            case 'price':
                $value = trim(str_replace([' ', ','], ['', '.'], (string) $value));
                return $value === '' ? null : round((float) $value, 4);
            case 'content':
                return is_array($value) ? $value : null;
            default:
                $value = (string) $value;
                return trim($value) === '' ? null : $value;
        }
    }

    /**
     * Whether two values of a field are the same value.
     */
    public function same(mixed $a, mixed $b, string $type): bool
    {
        return $this->normalize($a, $type) === $this->normalize($b, $type);
    }

    /**
     * For lists: "27.00", "Yes", "Summer Sale…", "3 components".
     */
    public function summary(mixed $value, array $field): string
    {
        if ($value === null || $value === '') {
            return (string) __('(empty)');
        }
        switch ($field['type']) {
            case 'boolean':
                return (string) ((int) $value ? __('Yes') : __('No'));
            case 'price':
                return number_format((float) $value, 2, '.', '');
            case 'select':
                return (string) ($field['options'][(string) $value] ?? $value);
            case 'content':
                $count = is_array($value) ? count((array) ($value['components'] ?? [])) : 0;
                return (string) __('%1 components', $count);
            default:
                $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? '');
                return mb_strlen($text) > 60 ? mb_substr($text, 0, 59) . '…' : $text;
        }
    }

    private function normalize(mixed $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        switch ($type) {
            case 'boolean':
                return (int) $value ? '1' : '0';
            case 'price':
                return number_format((float) $value, 4, '.', '');
            case 'content':
                return is_array($value) ? (string) json_encode($this->sortKeys($value)) : (string) $value;
            default:
                return str_replace("\r\n", "\n", (string) $value);
        }
    }

    private function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }
        return $value;
    }
}
