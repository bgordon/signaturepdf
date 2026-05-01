<?php

class PHPCertificateSigner
{
    public static function isSupported()
    {
        self::loadLibraries();

        return function_exists('openssl_x509_read')
            && function_exists('openssl_pkey_get_private')
            && class_exists('\TCPDF')
            && class_exists('\setasign\Fpdi\Tcpdf\Fpdi');
    }

    public static function isConfigured($certificateFile, $privateKeyFile, $privateKeyPassword = null)
    {
        if (!self::isSupported()) {
            return false;
        }

        if (!$certificateFile || !$privateKeyFile) {
            return false;
        }

        if (!is_readable($certificateFile) || !is_readable($privateKeyFile)) {
            return false;
        }

        $certificateContent = @file_get_contents($certificateFile);
        $privateKeyContent = @file_get_contents($privateKeyFile);
        if ($certificateContent === false || $privateKeyContent === false) {
            return false;
        }

        $certificate = @openssl_x509_read($certificateContent);
        $privateKey = @openssl_pkey_get_private($privateKeyContent, $privateKeyPassword);

        if (!$privateKey && $certificateFile === $privateKeyFile) {
            $privateKey = @openssl_pkey_get_private($certificateContent, $privateKeyPassword);
        }


        return (bool) ($certificate && $privateKey);
    }

    public static function sign($inputPdf, $outputPdf, array $options = array())
    {
        self::loadLibraries();

        if (!self::isConfigured(
            isset($options['certificate_file']) ? $options['certificate_file'] : null,
            isset($options['private_key_file']) ? $options['private_key_file'] : null,
            isset($options['private_key_password']) ? $options['private_key_password'] : null
        )) {
            throw new Exception('OpenSSL certificate signer is not configured');
        }

        $certificateFile = 'file://' . realpath($options['certificate_file']);
        $privateKeyFile = 'file://' . realpath($options['private_key_file']);
        $extraCertificatesFile = '';
        if (!empty($options['extra_certificates_file']) && is_readable($options['extra_certificates_file'])) {
            $extraCertificatesFile = 'file://' . realpath($options['extra_certificates_file']);
        }

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCreator('SignaturePDF');
        $pdf->SetAuthor(!empty($options['name']) ? $options['name'] : 'SignaturePDF');
        $pdf->SetTitle(basename($inputPdf));
        $pdf->SetSubject(isset($options['reason']) ? $options['reason'] : 'Signed with SignaturePDF');

        $signatureInfo = array(
            'Name' => !empty($options['name']) ? $options['name'] : 'SignaturePDF',
            'Location' => !empty($options['location']) ? $options['location'] : 'Shared Hosting',
            'Reason' => !empty($options['reason']) ? $options['reason'] : 'Signed with SignaturePDF',
            'ContactInfo' => !empty($options['contact_info']) ? $options['contact_info'] : '',
        );

        $pdf->setSignature(
            $certificateFile,
            $privateKeyFile,
            isset($options['private_key_password']) ? $options['private_key_password'] : '',
            $extraCertificatesFile,
            2,
            $signatureInfo
        );

        $pageCount = $pdf->setSourceFile($inputPdf);
        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $template = $pdf->importPage($pageNumber);
            $templateSize = $pdf->getTemplateSize($template);
            $orientation = isset($templateSize['orientation'])
                ? $templateSize['orientation']
                : (($templateSize['width'] > $templateSize['height']) ? 'L' : 'P');
            $pdf->AddPage($orientation, array($templateSize['width'], $templateSize['height']));
            $pdf->useTemplate($template, 0, 0, $templateSize['width'], $templateSize['height'], true);
        }

        if ($pageCount > 0) {
            $pdf->setSignatureAppearance(0, 0, 0, 0, 1);
        }

        $pdf->Output($outputPdf, 'F');
    }

    public static function probeSignature($pdfPath)
    {
        if (!is_readable($pdfPath)) {
            return array();
        }

        $contents = @file_get_contents($pdfPath);
        if ($contents === false) {
            return array();
        }

        if (strpos($contents, '/ByteRange') === false || strpos($contents, '/Type /Sig') === false) {
            return array();
        }

        return array(
            'Signature 1' => array(
                'Backend' => 'OpenSSL/TCPDF',
                'Document signed' => 'yes',
            ),
        );
    }

    private static function loadLibraries()
    {
        $tcpdf = __DIR__ . '/../vendor/tecnickcom/TCPDF/tcpdf.php';
        $fpdiAutoload = __DIR__ . '/../vendor/setasign/fpdi/src/autoload.php';

        if (file_exists($tcpdf) && !class_exists('\TCPDF')) {
            require_once $tcpdf;
        }
        if (file_exists($fpdiAutoload) && !class_exists('\setasign\Fpdi\Tcpdf\Fpdi')) {
            require_once $fpdiAutoload;
        }
    }
}
