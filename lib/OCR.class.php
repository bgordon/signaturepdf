<?php

class OCR
{
    public static function isInstalled() {
        return ToolCheck::run('ocrmypdf --version');
    }

    public static function isBrowserAvailable() {
        return array('Tesseract.js');
    }
}
