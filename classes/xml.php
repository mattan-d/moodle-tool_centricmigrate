<?php
// Copyright © CentricApp LTD. dev@centricapp.co.il

namespace tool_centricmigrate;

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers for Workplace export XML.
 */
class xml {

    /** Workplace sentinel for SQL NULL. */
    public const NULL_TOKEN = '$@NULL@$';

    /**
     * Parse an XML string into a nested array.
     *
     * @param string $xml
     * @return array
     */
    public static function to_array(string $xml): array {
        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false) {
            return [];
        }

        return self::element_to_array($element);
    }

    /**
     * Normalise a scalar Workplace value.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function value($value) {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            if ($value === []) {
                return '';
            }
            if (isset($value[0]) && count($value) === 1 && !is_array($value[0])) {
                return self::value($value[0]);
            }
            return $value;
        }
        $value = (string)$value;
        if ($value === self::NULL_TOKEN) {
            return null;
        }
        return $value;
    }

    /**
     * Integer value or the given default.
     *
     * @param mixed $value
     * @param int $default
     * @return int
     */
    public static function int($value, int $default = 0): int {
        $value = self::value($value);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int)$value;
    }

    /**
     * Return repeating child items as a list of arrays.
     *
     * @param array $parent
     * @param string $wrapper
     * @param string $item
     * @return array
     */
    public static function items(array $parent, string $wrapper, string $item): array {
        if (empty($parent[$wrapper][$item])) {
            return [];
        }
        $raw = $parent[$wrapper][$item];
        if (!is_array($raw)) {
            return [];
        }
        if (self::is_assoc($raw)) {
            return [$raw];
        }
        return $raw;
    }

    /**
     * File entries from a `_files` node.
     *
     * @param array $parent
     * @return array
     */
    public static function files(array $parent): array {
        return self::items($parent, '_files', '_file');
    }

    /**
     * @param \SimpleXMLElement $element
     * @return array
     */
    protected static function element_to_array(\SimpleXMLElement $element): array {
        $result = [];

        foreach ($element->children() as $child) {
            $name = $child->getName();
            if ($child->count() > 0) {
                $value = self::element_to_array($child);
            } else {
                $value = self::value((string)$child);
            }

            if (!array_key_exists($name, $result)) {
                $result[$name] = $value;
                continue;
            }

            if (!is_array($result[$name]) || self::is_assoc($result[$name])) {
                $result[$name] = [$result[$name]];
            }
            $result[$name][] = $value;
        }

        return $result;
    }

    /**
     * @param array $value
     * @return bool
     */
    protected static function is_assoc(array $value): bool {
        if ($value === []) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
