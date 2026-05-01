<?php

class OCR
{
    public static function isInstalled() {
        $output = null;
        $returnCode = null;

        exec('ocrmypdf --version', $output, $returnCode);

        if ($returnCode !== 0 || !$output) {
            return false;
        }
        return $output;
    }

    public static function isBrowserAvailable() {
        return array('Tesseract.js');
    }
}
