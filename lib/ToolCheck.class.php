<?php

/**
 * Cached detection of external command line tools (gs, ocrmypdf, pdftk, ...).
 *
 * Starting a process just to read a version number costs 5-40 ms per tool and
 * was done on every page view. Results are cached per request and for one hour
 * in a small JSON file in the system temp directory.
 */
class ToolCheck
{
    const TTL = 3600;

    private static $cache = null;

    /**
     * Runs $command once per TTL and returns its output lines, or false when the
     * command produced no output or exited with a code not listed in $okCodes
     * (null accepts every exit code).
     */
    public static function run($command, $okCodes = array(0))
    {
        self::load();
        $key = $command.'|'.(is_array($okCodes) ? implode(',', $okCodes) : '*');

        if (isset(self::$cache[$key]) && self::$cache[$key]['at'] > time() - self::TTL) {
            return self::$cache[$key]['out'];
        }

        $output = array();
        $returnCode = 1;

        if (self::canExec()) {
            exec($command, $output, $returnCode);
        }

        $result = ($okCodes === null || in_array($returnCode, $okCodes, true)) && $output ? $output : false;
        self::$cache[$key] = array('at' => time(), 'out' => $result);
        self::save();

        return $result;
    }

    public static function canExec()
    {
        static $can = null;

        if ($can === null) {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            $can = function_exists('exec') && !in_array('exec', $disabled, true);
        }

        return $can;
    }

    private static function file()
    {
        return rtrim(sys_get_temp_dir(), '/\\').'/signaturepdf-tools-'.substr(md5(__DIR__.'|'.getenv('PATH')), 0, 12).'.json';
    }

    private static function load()
    {
        if (self::$cache !== null) {
            return;
        }

        $data = is_readable(self::file()) ? json_decode((string) @file_get_contents(self::file()), true) : null;
        self::$cache = is_array($data) ? $data : array();
    }

    private static function save()
    {
        @file_put_contents(self::file(), json_encode(self::$cache), LOCK_EX);
    }
}
