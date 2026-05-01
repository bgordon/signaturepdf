<?php

class NSSCryptography
{
    private $nss_directory = null;
    private $nss_password = null;
    private $nss_nick = null;
    private $certificate_file = null;
    private $private_key_file = null;
    private $private_key_password = null;
    private $extra_certificates_file = null;
    private $signer_name = null;
    private $signer_location = null;
    private $contact_info = null;

    private static $instance = null;

    private function __construct(
        $dir,
        $pass,
        $nick,
        $certificateFile,
        $privateKeyFile,
        $privateKeyPassword,
        $extraCertificatesFile,
        $signerName,
        $signerLocation,
        $contactInfo
    ) {
        $this->nss_directory = $dir;
        $this->nss_password = $pass;
        $this->nss_nick = $nick;
        $this->certificate_file = $certificateFile;
        $this->private_key_file = $privateKeyFile;
        $this->private_key_password = $privateKeyPassword;
        $this->extra_certificates_file = $extraCertificatesFile;
        $this->signer_name = $signerName;
        $this->signer_location = $signerLocation;
        $this->contact_info = $contactInfo;
    }

    public static function getInstance(
        $dir = null,
        $pass = null,
        $nick = null,
        $certificateFile = null,
        $privateKeyFile = null,
        $privateKeyPassword = null,
        $extraCertificatesFile = null,
        $signerName = null,
        $signerLocation = null,
        $contactInfo = null
    ) {
        if (!self::$instance) {
            self::$instance = new NSSCryptography(
                $dir,
                $pass,
                $nick,
                $certificateFile,
                $privateKeyFile,
                $privateKeyPassword,
                $extraCertificatesFile,
                $signerName,
                $signerLocation,
                $contactInfo
            );
        }

        return self::$instance;
    }

    public function addSignature($pdfPath, $reason)
    {
        if ($this->canUseNssBackend()) {
            $this->addSignatureWithNss($pdfPath, $reason);
            return;
        }

        if ($this->canUsePhpBackend()) {
            $this->addSignatureWithPhp($pdfPath, $reason);
            return;
        }

        throw new Exception('No certificate-signing backend is configured');
    }

    public function verify($pdfPath)
    {
        if ($this->canUseNssBackend() && self::isPDFSigInstalled()) {
            $signatures = array();
            $output = array();
            $returnCode = 0;

            putenv('NSSPASS=' . $this->nss_password);
            exec(
                'pdfsig -nssdir ' . escapeshellarg($this->nss_directory)
                . ' -nss-pwd "$NSSPASS" '
                . escapeshellarg($pdfPath)
                . ' 2>&1',
                $output,
                $returnCode
            );

            if ($returnCode && (!isset($output[0]) || !preg_match('/does not contain any signatures/', $output[0]))) {
                throw new Exception('pdfsign error: ' . implode(' ', $output));
            }

            $index = null;
            foreach ($output as $line) {
                if (preg_match('/^(Signature[^:]*):/', $line, $matches)) {
                    $index = $matches[1];
                    $signatures[$index] = array();
                    continue;
                }
                if ($index === null) {
                    continue;
                }
                if (preg_match('/^  - ([^:]*):(.*)/', $line, $matches)) {
                    $signatures[$index][$matches[1]] = trim($matches[2]);
                } elseif (preg_match('/^  - (.*) (document signed)/', $line, $matches)) {
                    $signatures[$index]['Document signed'] = trim($matches[1]);
                }
            }

            return $signatures;
        }

        return PHPCertificateSigner::probeSignature($pdfPath);
    }

    public function isEnabled()
    {
        return $this->hasNssConfiguration() || $this->hasPhpConfiguration();
    }

    public function isPDFSigConfigured()
    {
        if ($this->canUseNssBackend()) {
            if (!self::isCertUtilInstalled() || !self::isPDFSigInstalled()) {
                return false;
            }

            $file = tempnam(sys_get_temp_dir(), 'certutil');
            $outputList = array();
            $outputKey = array();
            $returnCodeL = 0;
            $returnCodeK = 0;
            file_put_contents($file, $this->nss_password);
            exec(
                'certutil -f ' . escapeshellarg($file)
                . ' -d ' . escapeshellarg($this->nss_directory)
                . ' -L -n ' . escapeshellarg($this->nss_nick)
                . ' 2>&1',
                $outputList,
                $returnCodeL
            );
            exec(
                'certutil -f ' . escapeshellarg($file)
                . ' -d ' . escapeshellarg($this->nss_directory)
                . ' -K 2>&1',
                $outputKey,
                $returnCodeK
            );
            unlink($file);

            if ($returnCodeL === 0 && $returnCodeK === 0) {
                foreach ($outputKey as $line) {
                    if (strpos($line, ':' . $this->nss_nick) !== false || strpos($line, $this->nss_nick) !== false) {
                        return 'NSS/pdfsig';
                    }
                }
            }
        }

        if ($this->canUsePhpBackend()) {
            return 'OpenSSL/TCPDF';
        }

        return false;
    }

    public static function isCertUtilInstalled()
    {
        $output = array();
        $returnCode = 0;
        exec('certutil -V 2>&1', $output, $returnCode);
        if ($returnCode !== 0 && $returnCode !== 255) {
            return false;
        }

        return 'OK';
    }

    public static function isPDFSigInstalled()
    {
        $output = array();
        $returnCode = 0;
        exec('pdfsig -v 2>&1', $output, $returnCode);
        if ($returnCode !== 0 || empty($output[0])) {
            return false;
        }

        $matches = array();
        if (!preg_match('/([0-9]+(?:\.[0-9]+){0,2})/', $output[0], $matches)) {
            return 'OK';
        }

        return version_compare($matches[1], '21.0.0', '>=') ? $matches[1] : false;
    }

    public static function isPhpSignerAvailable()
    {
        return PHPCertificateSigner::isSupported() ? 'OpenSSL/TCPDF' : false;
    }

    private function hasNssConfiguration()
    {
        return (bool) ($this->nss_directory && $this->nss_nick);
    }

    private function hasPhpConfiguration()
    {
        return (bool) ($this->certificate_file && $this->private_key_file);
    }

    private function canUseNssBackend()
    {
        return $this->hasNssConfiguration()
            && self::isCertUtilInstalled()
            && self::isPDFSigInstalled();
    }

    private function canUsePhpBackend()
    {
        return $this->hasPhpConfiguration()
            && PHPCertificateSigner::isConfigured(
                $this->certificate_file,
                $this->private_key_file,
                $this->private_key_password
            );
    }

    private function addSignatureWithNss($pdfPath, $reason)
    {
        $output = array();
        $returnCode = 0;
        putenv('NSSPASS=' . $this->nss_password);
        exec(
            'pdfsig ' . escapeshellarg($pdfPath)
            . ' ' . escapeshellarg($pdfPath . '.signed.pdf')
            . ' -add-signature -nssdir ' . escapeshellarg($this->nss_directory)
            . ' -nss-pwd "$NSSPASS" -nick ' . escapeshellarg($this->nss_nick)
            . ' -reason ' . escapeshellarg($reason)
            . ' 2>&1',
            $output,
            $returnCode
        );
        if ($returnCode) {
            throw new Exception('pdfsign error: ' . implode(' ', $output));
        }
        rename($pdfPath . '.signed.pdf', $pdfPath);
    }

    private function addSignatureWithPhp($pdfPath, $reason)
    {
        $signedPdfPath = $pdfPath . '.signed.pdf';
        PHPCertificateSigner::sign(
            $pdfPath,
            $signedPdfPath,
            array(
                'certificate_file' => $this->certificate_file,
                'private_key_file' => $this->private_key_file,
                'private_key_password' => $this->private_key_password,
                'extra_certificates_file' => $this->extra_certificates_file,
                'name' => $this->signer_name,
                'location' => $this->signer_location,
                'contact_info' => $this->contact_info,
                'reason' => $reason,
            )
        );
        rename($signedPdfPath, $pdfPath);
    }
}
