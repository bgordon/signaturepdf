<?php

class Compression
{
    public static function isgsInstalled() {
        $output = ToolCheck::run('gs --version');

        return $output ?: array(false);
    }

    public static function isBrowserAvailable() {
        return array('pdf.js + pdf-lib');
    }
}
