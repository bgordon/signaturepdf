<?php

class Image2SVG
{
    public static function isimageMagickInstalled() {
        return ToolCheck::run('convert --version', null) ?: array(false);
    }

    public static function ispotraceInstalled() {
        return ToolCheck::run('potrace --version', null) ?: array(false);
    }
}
