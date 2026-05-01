<?php

class Compression
{
    public static function isgsInstalled() {
        $output = null;
        $returnCode = null;

        exec('gs --version', $output, $returnCode);

        if ($returnCode !== 0 || !$output) {
            return array(false);
        }
        return $output;
    }

    public static function isBrowserAvailable() {
        return array('pdf.js + pdf-lib');
    }
}
