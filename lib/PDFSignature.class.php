<?php

class PDFSignature
{
    const SHARE_STATE_FILE = '/share.json';

    protected $symmetricKey = null;
    protected $pathHash = null;
    protected $hash = null;
    protected $gpg = null;
    protected $toClean = [];
    protected $lockFile = null;
    protected $cacheDecryptFiles = [];

    public function __construct($pathHash, $symmetricKey = null) {
        $this->symmetricKey = $symmetricKey;
        $this->pathHash = $pathHash;
        $this->hash = basename($this->pathHash);
        $this->gpg = new GPGCryptography($symmetricKey, $pathHash);
        $this->lockFile = $this->pathHash.'/.lock';
}

    public function createShare($originalFile, $originFileBaseName, $duration) {
        if (!is_dir($this->pathHash)) {
            mkdir($this->pathHash);
        }
        $expireFile = $this->pathHash.".expire";
        file_put_contents($expireFile, $duration);
        touch($expireFile, date_format(date_modify(date_create(), file_get_contents($expireFile)), 'U'));
        rename($originalFile, $this->pathHash.'/original.pdf');
        file_put_contents($this->pathHash.'/filename.txt', $originFileBaseName);
        copy($this->pathHash.'/original.pdf', $this->pathHash.'/final.pdf');
        $this->saveShareState(array(
            'signature_count' => 0,
        ));
        if($this->symmetricKey) {
            $this->gpg->encrypt();
        }
    }

    public function createAdminKey()
    {
        $link = $this->gpg->createSymmetricKey(20);
        file_put_contents($this->pathHash.'.admin', $link);

        return $link;
    }

    public function verifyEncryption() {
        if(!$this->isEncrypted()) {

            return true;
        }

        return $this->gpg->decryptToString(preg_replace("/\.(gpg|enc)$/", "", $this->pathHash."/filename.txt")) !== false;
    }

    public function isEncrypted() {
        return $this->gpg->isEncrypted();
    }

    public function getDecryptFile($file) {
        if(!$this->isEncrypted()) {

            return $file;
        }
        $file = preg_replace("/\.(gpg|enc)$/", "", $file);
        if(file_exists($file)) {
            return $file;
        }

        if(array_key_exists($file, $this->cacheDecryptFiles)) {
            return $this->cacheDecryptFiles[$file];
        }
        $decryptFile = $this->gpg->decryptFile($file);
        $this->toClean[] = $decryptFile;
        $this->cacheDecryptFiles[$file] = $decryptFile;

        return $decryptFile;
    }

    public function getPDF() {
        if (file_exists($this->pathHash.'/final.pdf') || file_exists($this->pathHash.'/final.pdf'.GPGCryptography::ENCRYPTED_EXTENSION)) {
            return $this->getDecryptFile($this->pathHash.'/final.pdf');
        }
        $this->compile();
        return $this->getDecryptFile($this->pathHash.'/final.pdf');
    }

    public function needToCompile() {
        if ($this->getSignatureCount() !== null) {
            return false;
        }
        $needToCompile = false;
        foreach($this->getLayers() as $layerFile) {
            if(!file_exists(str_replace('.svg.pdf', '.sign.pdf', $layerFile))) {
                $needToCompile = true;
            }
        }
        if(!$this->isEncrypted() && !file_exists($this->pathHash.'/final.pdf')) {
            $needToCompile = true;
        }
        if($this->isEncrypted() && !file_exists($this->pathHash.'/final.pdf.gpg')) {
            $needToCompile = true;
        }

        return $needToCompile;
    }

    protected function isCompileLock() {
        // a lock older than 30 seconds belongs to a crashed request
        if(file_exists($this->lockFile) && filemtime($this->lockFile) < time() - 30) {
            unlink($this->lockFile);
        }

        return file_exists($this->lockFile);
    }

    protected function lockCompile() {
        touch($this->lockFile);
    }

    protected function unlockCompile() {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    public function compile() {
        if(!$this->needToCompile()) {
            return;
        }

        if($this->isCompileLock()) {
            // wait for the other request instead of spinning on the CPU
            usleep(100000);
            clearstatcache(true, $this->lockFile);
            return $this->compile();
        }

        $this->lockCompile();

        $layers = $this->getLayers($this->pathHash);
        $currentSignedFile = $this->pathHash.'/original.pdf';
        $signedFileToCopy = [];
        foreach($layers as $layerFile) {
            $signedFile = str_replace('.svg.pdf', '.sign.pdf', $layerFile);
            if(!file_exists($signedFile)) {
                $signedFile = preg_replace("/\.gpg$/", '', $signedFile);
                self::addSvgToPDF($this->getDecryptFile($currentSignedFile), $this->getDecryptFile($layerFile), $signedFile, false);
            }
            $currentSignedFile = $signedFile;
        }
        copy($this->getDecryptFile($currentSignedFile), $this->pathHash.'/final.pdf');

        if($this->isEncrypted()) {
            $this->gpg->encrypt();
        }

        $this->unlockCompile();
    }

    protected function getShareStateFile() {
        return $this->pathHash.self::SHARE_STATE_FILE;
    }

    protected function getShareState() {
        $contents = $this->gpg->decryptToString($this->getShareStateFile());
        if ($contents === false) {
            return null;
        }
        $state = json_decode($contents, true);
        if (!is_array($state)) {
            return null;
        }

        return $state;
    }

    protected function saveShareState(array $state) {
        file_put_contents($this->getShareStateFile(), json_encode($state));
    }

    public function getSignatureCount() {
        $state = $this->getShareState();
        if (!is_array($state) || !array_key_exists('signature_count', $state)) {
            return null;
        }

        return (int) $state['signature_count'];
    }

    public function setSignatureCount($count) {
        $state = $this->getShareState();
        if (!is_array($state)) {
            $state = array();
        }
        $state['signature_count'] = max(0, (int) $count);
        $this->saveShareState($state);
        if ($this->isEncrypted()) {
            $this->gpg->encrypt();
        }
    }

    public function addSignedPdf($signedPdfFile, $incrementCount = true, $setCount = null) {
        $expireFile = $this->pathHash.".expire";
        touch($expireFile, date_format(date_modify(date_create(), file_get_contents($expireFile)), 'U'));

        $finalPdf = $this->pathHash.'/final.pdf';
        if (file_exists($finalPdf)) {
            unlink($finalPdf);
        }
        if (file_exists($finalPdf.GPGCryptography::ENCRYPTED_EXTENSION)) {
            unlink($finalPdf.GPGCryptography::ENCRYPTED_EXTENSION);
        }

        rename($signedPdfFile, $finalPdf);

        if (NSSCryptography::getInstance()->isEnabled()) {
            try {
                NSSCryptography::getInstance()->addSignature($finalPdf, 'Signed with SignaturePDF');
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }

        $signatureCount = $this->getSignatureCount();
        if ($signatureCount === null) {
            $signatureCount = count($this->getLayers());
        }
        if ($setCount !== null) {
            $signatureCount = (int) $setCount;
        } elseif ($incrementCount) {
            $signatureCount++;
        }
        $this->saveShareState(array(
            'signature_count' => max(0, (int) $signatureCount),
        ));

        if ($this->isEncrypted()) {
            $this->gpg->encrypt();
        }
    }

    public function signFinalPdf($reason) {
        $finalPdf = $this->pathHash.'/final.pdf';
        if ($this->isEncrypted()) {
            $decryptFile = $this->getDecryptFile($finalPdf);
            if (!$decryptFile || !file_exists($decryptFile)) {
                throw new Exception('Unable to decrypt final pdf for signing');
            }
            copy($decryptFile, $finalPdf);
        }

        NSSCryptography::getInstance()->addSignature($finalPdf, $reason);

        if ($this->isEncrypted()) {
            $this->gpg->encrypt();
        }
    }

    public function getPublicFilename() {
        $filename = $this->hash.'.pdf';

        $contents = $this->gpg->decryptToString($this->pathHash."/filename.txt");

        if($contents !== false) {
            $filename = $contents;
        }

        $signatureCount = $this->getSignatureCount();
        if ($signatureCount === null) {
            $signatureCount = count($this->getLayers());
        }

        $filename = str_replace('.pdf', '_signe-'.$signatureCount.'x.pdf', $filename);

        return $filename;
    }

    public function getLayers($pathHash = null) {
        if(is_null($pathHash)) {
            $pathHash = $this->pathHash;
        }
        if(!file_exists($pathHash)) {
            return [];
        }
        $files = scandir($pathHash);
        $layers = [];
        foreach($files as $file) {
            if(strpos($file, '.svg.pdf') !== false) {
                $layers[] = $pathHash.'/'.$file;
            }
        }
        return $layers;
    }

    public function addSignature(array $svgFiles) {
        $expireFile = $this->pathHash.".expire";
        touch($expireFile, date_format(date_modify(date_create(), file_get_contents($expireFile)), 'U'));

        do {
            if(isset($svgPDFFile)) { usleep(1); }
            $svgPDFFile = $this->pathHash."/".(new DateTime())->format('YmdHisu').'.svg.pdf';
        } while (file_exists($svgPDFFile));

        self::createPDFFromSvg($svgFiles, $svgPDFFile);

        if($this->isEncrypted()) {
            $this->gpg->encrypt();
        }
        $this->toClean = array_merge($this->toClean, $svgFiles);
        $this->compile();
    }

    public static function createPDFFromSvg(array $svgFiles, $outputPdfFile) {
        shell_exec(sprintf("rsvg-convert -f pdf -o %s %s", $outputPdfFile, implode(" ", $svgFiles)));
    }

    public static function addSvgToPDF($pdfOrigin, $pdfSvg, $pdfOutput, $digitalSignature = true) {
        shell_exec(sprintf("pdftk %s multistamp %s output %s", $pdfOrigin, $pdfSvg, $pdfOutput));
        if (NSSCryptography::getInstance()->isEnabled() && $digitalSignature) {
            try {
                NSSCryptography::getInstance()->addSignature($pdfOutput, 'Signed with SignaturePDF');
            } catch(Exception $e) {
                error_log($e->getMessage());
            }
        }
    }

    public static function flatten($pdf)
    {
        // check version of imagick
        $command = ToolCheck::run('command -v magick', null) === false ? 'convert' : 'magick';

        shell_exec(sprintf(
            '%s -density 200 -units PixelsPerInch %s_signe.pdf -compress zip %s_signe.pdf'
        , $command, escapeshellarg($pdf), escapeshellarg($pdf)));
    }

    public function clean() {
        foreach($this->toClean as $path) {
            if(strpos($path, $this->pathHash) !== false) {
                continue;
            }
            GPGCryptography::hardUnlink($path);
        }
    }

    public static function isrsvgConvertInstalled() {
        return ToolCheck::run('rsvg-convert --version', null) ?: array(false);
    }

    public static function ispdftkInstalled() {
        return ToolCheck::run('pdftk --version', null) ?: array(false);
    }

}
