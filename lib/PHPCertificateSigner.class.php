<?php

/**
 * Pure PHP certificate signer (OpenSSL + TCPDF/FPDI) for shared hosting
 * where pdfsig/NSS binaries are not available.
 *
 * - isConfigured() parses certificate and key once and caches the result
 *   (per request and in a small stamp file keyed on path, size and mtime),
 *   so frequent page views do not load TCPDF or call OpenSSL again.
 * - sign() accepts a file path or the PDF content as string ('input_pdf_data')
 *   and can place a visible signature image ('signature_image' path or
 *   'signature_image_data' PNG binary) without temporary files.
 * - ensureSelfSignedCertificate() creates a self-signed RSA certificate when
 *   none is configured yet.
 */
class PHPCertificateSigner
{
    private static $checked = array();
    private static $supported = null;

    public static function isSupported()
    {
        if (self::$supported === null) {
            self::loadLibraries();
            self::$supported = function_exists('openssl_x509_read')
                && function_exists('openssl_pkey_get_private')
                && class_exists('\TCPDF')
                && class_exists('\setasign\Fpdi\Tcpdf\Fpdi');
        }

        return self::$supported;
    }

    public static function isConfigured($certificateFile, $privateKeyFile, $privateKeyPassword = null)
    {
        $certificateFile = (string) $certificateFile;
        $privateKeyFile = (string) $privateKeyFile;

        if ($certificateFile === '' || $privateKeyFile === '' || !is_readable($certificateFile) || !is_readable($privateKeyFile)) {
            return false;
        }

        $stamp = hash('sha256', implode('|', array(
            realpath($certificateFile), filesize($certificateFile), filemtime($certificateFile),
            realpath($privateKeyFile), filesize($privateKeyFile), filemtime($privateKeyFile),
            hash('sha256', (string) $privateKeyPassword),
        )));

        if (isset(self::$checked[$stamp])) {
            return self::$checked[$stamp];
        }

        $mark = self::stampFile($stamp);

        if ($mark !== '' && @file_get_contents($mark) === $stamp) {
            return self::$checked[$stamp] = true;
        }

        if (!function_exists('openssl_x509_read') || !function_exists('openssl_pkey_get_private')) {
            return self::$checked[$stamp] = false;
        }

        $certificateContent = (string) @file_get_contents($certificateFile);
        $certificate = @openssl_x509_read($certificateContent);
        $privateKey = @openssl_pkey_get_private((string) @file_get_contents($privateKeyFile), (string) $privateKeyPassword);

        if (!$privateKey && realpath($certificateFile) === realpath($privateKeyFile)) {
            $privateKey = @openssl_pkey_get_private($certificateContent, (string) $privateKeyPassword);
        }

        $ok = $certificate && $privateKey && openssl_x509_check_private_key($certificate, $privateKey);

        if ($ok && $mark !== '') {
            @file_put_contents($mark, $stamp);
        }

        return self::$checked[$stamp] = (bool) $ok;
    }

    public static function ensureSelfSignedCertificate($certificateFile, $privateKeyFile, $privateKeyPassword = null, array $subject = array())
    {
        if (self::isConfigured($certificateFile, $privateKeyFile, $privateKeyPassword)) {
            return true;
        }

        foreach (array('openssl_pkey_new', 'openssl_csr_new', 'openssl_csr_sign', 'openssl_x509_export', 'openssl_pkey_export') as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }

        foreach (array(dirname((string) $certificateFile), dirname((string) $privateKeyFile)) as $directory) {
            if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0700, true)) {
                return false;
            }
        }

        $config = array('digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
        $subject = array_filter(array_merge(array('commonName' => 'SignaturePDF', 'organizationName' => 'SignaturePDF'), $subject), 'strlen');
        $privateKey = openssl_pkey_new($config);
        $request = $privateKey ? openssl_csr_new($subject, $privateKey, $config) : false;
        $certificate = $request ? openssl_csr_sign($request, null, $privateKey, 3650, $config) : false;
        $certificateBody = '';
        $privateKeyBody = '';

        if (!$certificate
            || !openssl_x509_export($certificate, $certificateBody)
            || !openssl_pkey_export($privateKey, $privateKeyBody, $privateKeyPassword === null ? null : (string) $privateKeyPassword, $config)
            || file_put_contents($certificateFile, $certificateBody) === false
            || file_put_contents($privateKeyFile, $privateKeyBody) === false) {
            return false;
        }

        @chmod($certificateFile, 0644);
        @chmod($privateKeyFile, 0600);
        clearstatcache(true, $certificateFile);
        clearstatcache(true, $privateKeyFile);

        return self::isConfigured($certificateFile, $privateKeyFile, $privateKeyPassword);
    }

    public static function sign($inputPdf, $outputPdf, array $options = array())
    {
        if (!self::isSupported()) {
            throw new Exception('TCPDF/FPDI or OpenSSL is not available');
        }

        $certificateFile = isset($options['certificate_file']) ? (string) $options['certificate_file'] : '';
        $privateKeyFile = isset($options['private_key_file']) ? (string) $options['private_key_file'] : '';
        $privateKeyPassword = isset($options['private_key_password']) ? (string) $options['private_key_password'] : '';

        if (!self::isConfigured($certificateFile, $privateKeyFile, $privateKeyPassword)) {
            throw new Exception('OpenSSL certificate signer is not configured');
        }

        $extraCertificatesFile = '';
        if (!empty($options['extra_certificates_file']) && is_readable($options['extra_certificates_file'])) {
            $extraCertificatesFile = 'file://' . realpath($options['extra_certificates_file']);
        }

        $name = !empty($options['name']) ? (string) $options['name'] : 'SignaturePDF';
        $reason = !empty($options['reason']) ? (string) $options['reason'] : 'Signed with SignaturePDF';

        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCreator('SignaturePDF');
        $pdf->SetAuthor(!empty($options['author']) ? (string) $options['author'] : $name);
        $pdf->SetTitle(!empty($options['title']) ? (string) $options['title'] : basename((string) $inputPdf));
        $pdf->SetSubject($reason);
        $pdf->setSignature(
            'file://' . realpath($certificateFile),
            'file://' . realpath($privateKeyFile),
            $privateKeyPassword,
            $extraCertificatesFile,
            2,
            array(
                'Name' => $name,
                'Location' => !empty($options['location']) ? (string) $options['location'] : 'Shared Hosting',
                'Reason' => $reason,
                'ContactInfo' => !empty($options['contact_info']) ? (string) $options['contact_info'] : '',
            )
        );

        $source = isset($options['input_pdf_data']) && $options['input_pdf_data'] !== ''
            ? \setasign\Fpdi\PdfParser\StreamReader::createByString((string) $options['input_pdf_data'])
            : $inputPdf;
        $pageCount = $pdf->setSourceFile($source);
        $signaturePage = !empty($options['signature_page']) ? max(1, min($pageCount, (int) $options['signature_page'])) : $pageCount;
        $image = '';

        if (isset($options['signature_image_data']) && $options['signature_image_data'] !== '') {
            $image = '@' . $options['signature_image_data'];
        } elseif (!empty($options['signature_image']) && is_readable($options['signature_image'])) {
            $image = (string) $options['signature_image'];
        }

        $x = isset($options['signature_x']) ? (float) $options['signature_x'] : 20;
        $y = isset($options['signature_y']) ? (float) $options['signature_y'] : 245;
        $width = isset($options['signature_width']) ? (float) $options['signature_width'] : 52;
        $height = isset($options['signature_height']) ? (float) $options['signature_height'] : 18;
        $caption = isset($options['signature_caption']) ? (string) $options['signature_caption'] : '';
        $date = isset($options['signature_date']) ? (string) $options['signature_date'] : '';

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $template = $pdf->importPage($pageNumber);
            $size = $pdf->getTemplateSize($template);
            $orientation = isset($size['orientation']) ? $size['orientation'] : (($size['width'] > $size['height']) ? 'L' : 'P');
            $pdf->AddPage($orientation, array($size['width'], $size['height']));
            $pdf->useTemplate($template, 0, 0, $size['width'], $size['height'], true);

            if ($pageNumber !== $signaturePage || $image === '') {
                continue;
            }

            $pdf->Image($image, $x, $y, $width, $height, 'PNG', '', '', true, 300, '', false, false, 0, 'LB', false, false);
            $pdf->SetTextColor(51, 65, 85);
            $pdf->SetFont('helvetica', '', 8);

            if ($caption !== '') {
                $pdf->SetXY($x, $y + $height + 1.5);
                $pdf->Cell($width, 4, $caption, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            }

            if ($date !== '') {
                $pdf->SetXY($x, $y + $height + 5.5);
                $pdf->Cell($width + 30, 4, $date, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            }
        }

        if ($pageCount > 0) {
            if ($image !== '') {
                $pdf->setSignatureAppearance($x, $y, $width, $height, $signaturePage);
            } else {
                $pdf->setSignatureAppearance(0, 0, 0, 0, 1);
            }
        }

        $pdf->Output($outputPdf, 'F');

        return array('pageCount' => $pageCount, 'signaturePage' => $signaturePage);
    }

    public static function probeSignature($pdfPath)
    {
        if (!is_readable($pdfPath)) {
            return array();
        }

        $contents = @file_get_contents($pdfPath);
        if ($contents === false || strpos($contents, '/ByteRange') === false || strpos($contents, '/Type /Sig') === false) {
            return array();
        }

        return array(
            'Signature 1' => array(
                'Backend' => 'OpenSSL/TCPDF',
                'Document signed' => 'yes',
            ),
        );
    }

    private static function stampFile($stamp)
    {
        $directory = sys_get_temp_dir();

        if (!is_dir($directory) || !is_writable($directory)) {
            return '';
        }

        return rtrim($directory, '/\\') . '/signaturepdf-signer-' . substr($stamp, 0, 16) . '.ok';
    }

    private static function loadLibraries()
    {
        // TCPDF 6.11.4 announces its successor tc-lib-pdf on every run; the 6.x API is what FPDI needs
        if (!defined('TCPDF_SILENCE_DEPRECATION')) {
            define('TCPDF_SILENCE_DEPRECATION', true);
        }

        $tcpdf = __DIR__ . '/../vendor/tecnickcom/TCPDF/tcpdf.php';
        $fpdiAutoload = __DIR__ . '/../vendor/setasign/fpdi/src/autoload.php';

        if (!class_exists('\TCPDF', false) && file_exists($tcpdf)) {
            require_once $tcpdf;
        }
        if (!class_exists('\setasign\Fpdi\Tcpdf\Fpdi', false) && file_exists($fpdiAutoload)) {
            require_once $fpdiAutoload;
        }
    }
}
