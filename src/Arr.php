<?php

namespace Bolt\Collection;

use ArrayAccess;
use InvalidArgumentException;
use RuntimeException;
use Traversable;
use Webmozart\Assert\Assert;

/**
 * Array helper functions.
 *
 * @author Carson Full <carsonfull@gmail.com>
 */
class Arr
{
    /**
     * Return the values from a single column in the input array, identified by the $columnKey.
     *
     * Optionally, an $indexKey may be provided to index the values in the returned array by the
     * values from the $indexKey column in the input array.
     *
     * @param Traversable|array $input     A list of arrays or objects from which to pull a column of values
     * @param string|int        $columnKey The column of values to return
     * @param string|int|null   $indexKey  The column to use as the index/keys for the returned array
     *
     * @return array
     */
    public static function column($input, $columnKey, $indexKey = null)
    {
        Assert::isTraversable($input);

        $output = [];

        foreach ($input as $row) {
            $key = $value = null;
            $keySet = false;

            if ($columnKey === null) {
                $value = $row;
            } elseif (is_array($row) && array_key_exists($columnKey, $row)) {
                $value = $row[$columnKey];
            } elseif ($row instanceof ArrayAccess && isset($row[$columnKey])) {
                $value = $row[$columnKey];
            } elseif (is_object($row) && isset($row->{$columnKey})) {
                $value = $row->{$columnKey};
            } else {
                continue;
            }

            if ($indexKey !== null) {
                /*
                 * For arrays, we use array_key_exists because isset returns false for keys that exist with null values.
                 * For ArrayAccess we assume devs are smarter and don't have this edge case. Regardless, we don't have
                 * another way to check so it's up to them.
                 */
                if (is_array($row) && array_key_exists($indexKey, $row)) {
                    $keySet = true;
                    $key = (string) $row[$indexKey];
                } elseif ($row instanceof ArrayAccess && isset($row[$indexKey])) {
                    $keySet = true;
                    $key = (string) $row[$indexKey];
                } elseif (is_object($row) && isset($row->{$indexKey})) {
                    $keySet = true;
                    $key = (string) $row->{$indexKey};
                }
            }

            if ($keySet) {
                $output[$key] = $value;
            } else {
                $output[] = $value;
            }
        }

        return $output;
    }

    /**
     * Returns whether a key exists from an array (or ArrayAccess object) using a path syntax to retrieve nested data.
     *
     * This method does not allow for keys that contain "/". You must traverse
     * the array manually or using something more advanced like JMESPath to
     * work with keys that contain "/".
     *
     *     // Check if the the bar key of a set of nested arrays exists.
     *     // This is equivalent to isset($data['foo']['baz']['bar']) but won't
     *     // throw warnings for missing keys.
     *     has($data, 'foo/baz/bar');
     *
     * Note: isset() with nested data, `isset($data['a']['b'])`, won't call offsetExists for 'a'.
     * It calls offsetGet('a') and if 'a' doesn't exist and an isset isn't done in offsetGet, a warning is thrown.
     * It could be argued that that ArrayAccess object should fix its implementation of offsetGet, and I would agree.
     * But I think this can have nicer syntax.
     *
     * @param array|ArrayAccess $data Data to check values from
     * @param string            $path Path to traverse and check keys from
     *
     * @return bool
     */
    public static function has($data, $path)
    {
        static::assertAccessible($data);
        Assert::stringNotEmpty($path);

        $path = explode('/', $path);

        while (null !== ($part = array_shift($path))) {
            if (!($data instanceof ArrayAccess) && !is_array($data)) {
                return false;
            }
            if (!(isset($data[$part]) || array_key_exists($part, $data))) {
                return false;
            }
            $data = $data[$part];
        }

        return true;
    }

    /**
     * Gets a value from an array (or ArrayAccess object) using a path syntax to retrieve nested data.
     *
     * This method does not allow for keys that contain "/". You must traverse
     * the array manually or using something more advanced like JMESPath to
     * work with keys that contain "/".
     *
     *     // Get the bar key of a set of nested arrays.
     *     // This is equivalent to $data['foo']['baz']['bar'] but won't
     *     // throw warnings for missing keys.
     *     get($data, 'foo/baz/bar');
     *
     * This code is adapted from Michael Dowling in his Guzzle library.
     *
     * @param array|ArrayAccess $data    Data to retrieve values from
     * @param string            $path    Path to traverse and retrieve a value from
     * @param mixed|null        $default Default value to return if key does not exist
     *
     * @return mixed|null
     */
    public static function get($data, $path, $default = null)
    {
        static::assertAccessible($data);
        Assert::stringNotEmpty($path);

        $path = explode('/', $path);

        while (null !== ($part = array_shift($path))) {
            if ((!is_array($data) && !($data instanceof ArrayAccess)) || !isset($data[$part])) {
                return $default;
            }
            $data = $data[$part];
        }

        return $data;
    }

    /**
     * Set a value in a nested array (or ArrayAccess object) key.
     * Keys will be created as needed to set the value.
     *
     * This function does not support keys that contain "/" or "[]" characters
     * because these are special tokens used when traversing the data structure.
     * A value may be appended to an existing array by using "[]" as the final
     * key of a path.
     *
     *     get($data, 'foo/baz'); // null
     *     set($data, 'foo/baz/[]', 'a');
     *     set($data, 'foo/baz/[]', 'b');
     *     get($data, 'foo/baz');
     *     // Returns ['a', 'b']
     *
     * Note: To set values not directly under ArrayAccess objects their
     * offsetGet() method needs to be defined as return by reference.
     *
     *     public function &offsetGet($offset) {}
     *
     * This code is adapted from Michael Dowling in his Guzzle library.
     *
     * @param array|ArrayAccess $data  Data to modify by reference
     * @param string            $path  Path to set
     * @param mixed             $value Value to set at the key
     *
     * @throws \RuntimeException when trying to set using a nested path that travels through a scalar value or an
     *                           object whose offsetGet method isn't marked as return by reference
     */
    public static function set(&$data, $path, $value)
    {
        static::assertAccessible($data);
        Assert::stringNotEmpty($path);

        $queue = explode('/', $path);
        // Optimization for simple sets.
        if (count($queue) === 1) {
            if ($path === '[]') {
                $data[] = $value;
            } else {
                $data[$path] = $value;
            }

            return;
        }

        $invalidKey = null;
        $current = &$data;
        while (null !== ($key = array_shift($queue))) {
            if (!is_array($current) && !($current instanceof ArrayAccess)) {
                throw new RuntimeException(
                    sprintf(
                        "Cannot set '%s', because '%s' is already set and not an array or an object implementing ArrayAccess.",
                        $path,
                        $invalidKey
                    )
                );
            }
            if (!$queue) {
                if ($key === '[]') {
                    $current[] = $value;
                } else {
                    $current[$key] = $value;
                }
            } elseif (isset($current[$key])) {
                $current = &$current[$key];
            } elseif (!static::canReturnArraysByReference($current)) {
                throw new RuntimeException(
                    sprintf(
                        "Cannot set '%s', because '%s' is an %s which does not return arrays by reference from its offsetGet() method. See %s for an example of how to do this.",
                        $path,
                        $invalidKey,
                        get_class($current),
                        Bag::class
                    )
                );
            } else {
                $current[$key] = [];
                $current = &$current[$key];
            }
            $invalidKey = $key;
        }
    }

    /**
     * Returns whether the value is an array or an object implementing ArrayAccess.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public static function isAccessible($value)
    {
        return $value instanceof ArrayAccess || is_array($value);
    }

    /**
     * Asserts that the given value is an array or an object implementing ArrayAccess.
     *
     * @param mixed $value
     *
     * @throws InvalidArgumentException
     */
    public static function assertAccessible($value)
    {
        if (!static::isAccessible($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected an array or an object implementing ArrayAccess. Got: %s',
                    is_object($value) ? get_class($value) : gettype($value)
                )
            );
        }
    }

    /**
     * Returns whether the given item is an associative array.
     *
     * Note: Empty arrays are not.
     *
     * @param Traversable|array $array
     *
     * @return bool
     */
    public static function isAssociative($array)
    {
        if ($array instanceof Traversable) {
            $array = iterator_to_array($array);
        }
        if (!is_array($array) || $array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Returns whether the given item is an indexed array - zero indexed and sequential.
     *
     * Note: Empty arrays are.
     *
     * @param Traversable|array $array
     *
     * @return bool
     */
    public static function isIndexed($array)
    {
        if (!is_iterable($array)) {
            return false;
        }

        return !static::isAssociative($array);
    }

    /**
     * Replaces values from second array into first array recursively.
     *
     * This differs from {@see array_replace_recursive} in a couple ways:
     *  - Lists (indexed arrays) from second array completely replace list in first array.
     *  - Null values from second array do not replace lists or associative arrays in first
     *    (they do still replace scalar values).
     *
     * This method converts all traversable objects at any level to arrays in the return value.
     *
     * @param Traversable|array $array1
     * @param Traversable|array $array2
     *
     * @return array The combined array
     */
    public static function replaceRecursive($array1, $array2)
    {
        Assert::allIsTraversable([$array1, $array2]);

        if ($array1 instanceof Traversable) {
            $array1 = iterator_to_array($array1);
        }
        if ($array2 instanceof Traversable) {
            $array2 = iterator_to_array($array2);
        }

        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if ($value instanceof Traversable) {
                $value = iterator_to_array($value);
            }
            if (is_array($value) && static::isAssociative($value)
                && isset($merged[$key]) && is_iterable($merged[$key])
            ) {
                $merged[$key] = static::replaceRecursive($merged[$key], $value);
            } elseif ($value === null && isset($merged[$key]) && is_iterable($merged[$key])) {
                // Convert iterable to array to be consistent.
                if ($merged[$key] instanceof Traversable) {
                    $merged[$key] = iterator_to_array($merged[$key]);
                }
                continue;
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Determine whether the array/object can return arrays by reference.
     *
     * @param ArrayAccess|array $obj
     *
     * @return bool
     */
    private static function canReturnArraysByReference($obj)
    {
        if (is_array($obj)) {
            return true;
        }

        static $supportedClasses = [];

        $class = get_class($obj);
        if (isset($supportedClasses[$class])) {
            return $supportedClasses[$class];
        }

        $testKey = '__reference_test';
        $obj[$testKey] = [];
        if (!defined('HHVM_VERSION')) {
            $prev = set_error_handler('var_dump');
            restore_error_handler();
            set_error_handler(function ($type, $message, $file, $line) use ($prev, &$supportedClasses) {
                $regex = '/Indirect modification of overloaded element of ([\w\\\\]+) has no effect/';
                if (preg_match($regex, $message, $matches)) {
                    $supportedClasses[$matches[1]] = false;
                } elseif ($prev) {
                    return call_user_func($prev, $type, $message, $file, $line);
                } else {
                    // return false to let PHP handle error
                    return false;
                }
            });
            try {
                $test = &$obj[$testKey];
                if (!isset($supportedClasses[$class])) {
                    $supportedClasses[$class] = true;
                }
            } finally {
                restore_error_handler();
            }
        } else {
            $test1 = &$obj[$testKey];
            $test2 = &$obj[$testKey];
            $test1[$testKey] = 'test';
            if ($test1 === $test2) {
                $supportedClasses[$class] = true;
                unset($test1[$testKey]);
            } else {
                $supportedClasses[$class] = false;
            }
        }
        unset($obj[$testKey]);

        return $supportedClasses[$class];
    }

    /**
     * Private Constructor.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }
}
