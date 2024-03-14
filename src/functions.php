<?php

namespace App;

if (!function_exists(__NAMESPACE__ . '\\convertToBytes')) {
    /**
     * @see https://stackoverflow.com/a/11807179
     *
     * @param string $from
     * @param bool $caseSensitive
     * @return int
     */
    function convertToBytes(string $from, bool $caseSensitive = true): int {
        $units = ['B', 'kiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $fallbackUnits = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];

        if (!$caseSensitive) {
            $from = strtolower($from);
            $units = array_map('strtolower', $units);
            $fallbackUnits = array_map('strtolower', $fallbackUnits);
        }
        if (!preg_match('/^(\d+)\s*([a-z]*)$/i', trim($from), $matches)) {
            throw new \InvalidArgumentException('Not a valid size.');
        }
        $number = floatval($matches[1]);
        $unit = (string)$matches[2];

        if (!strlen($unit)) {
            $unit = $caseSensitive ? 'B' : 'b';
        }

        if (in_array($unit, $units)) {
            $exponent = array_flip($units)[$unit];
        }
        elseif (in_array($unit, $fallbackUnits)) {
            $exponent = array_flip($fallbackUnits)[$unit];
        }
        else {
            throw new \InvalidArgumentException(sprintf('Invalid size unit "%s".', $unit));
        }

        return (int) ($number * (1024 ** $exponent));
    }
}


if (!function_exists(__NAMESPACE__ . '\\humanSize')) {
    /**
     * @see https://subinsb.com/convert-bytes-kb-mb-gb-php/
     *
     * @param int $sizeBytes
     * @param bool $translatable
     * @param string $format
     * @return string|array
     */
    function humanSize(int $sizeBytes, bool $translatable = false, string $format = '%d %s') {
        $base = log($sizeBytes) / log(1024);
        $suffix = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $baseIdx = (int)floor($base);

        $number = round(pow(1024, $base - floor($base)), 1);
        $unit = $suffix[$baseIdx];
        if ($translatable) {
            return [$number, $unit, 'filesize'];
        }

        return sprintf($format, $number, $unit);
    }
}

if (!function_exists(__NAMESPACE__ . '\\humanTime')) {
    /**
     * @param float $time
     * @return string
     */
    function humanTime(float $time) {
        return sprintf(
            '%02d:%02d',
            floor((int) $time / 60),
            floor((int) $time % 60)
        );
    }
}
