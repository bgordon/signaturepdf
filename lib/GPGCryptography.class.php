<?php

class GPGCryptography
{
    const ENCRYPTED_EXTENSION = '.enc';
    const LEGACY_ENCRYPTED_EXTENSION = '.gpg';
    const OPENSSL_CIPHER = 'aes-256-gcm';
    const OPENSSL_FALLBACK_CIPHER = 'aes-256-cbc';
    const MAGIC_GCM = 'SPENC02';
    const MAGIC_CBC = 'SPENC01';

    private $symmetricKey = null;
    private $pathHash = null;

    function __construct($key, $pathHash) {
        $this->symmetricKey = $key;
        $this->pathHash = $pathHash;
    }

    private function getFiles($encrypted) {
        $suffix = '';
        if ($encrypted) {
            $suffix = self::ENCRYPTED_EXTENSION;
        }
        $filesTab = glob($this->pathHash.'/*.pdf'.$suffix);

        foreach (array('filename.txt', 'share.json') as $metadataFile) {
            if (file_exists($this->pathHash.'/'.$metadataFile.$suffix)) {
                $filesTab[] = $this->pathHash.'/'.$metadataFile.$suffix;
            }
        }

        return $filesTab;
    }

    private static function getBinaryKey($key) {
        return hash('sha256', (string) $key, true);
    }

    private static function encryptContents($contents, $key) {
        $binaryKey = self::getBinaryKey($key);

        if (in_array(self::OPENSSL_CIPHER, openssl_get_cipher_methods(), true)) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($contents, self::OPENSSL_CIPHER, $binaryKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($ciphertext === false || strlen($tag) !== 16) {
                return false;
            }

            return self::MAGIC_GCM.$iv.$tag.$ciphertext;
        }

        $ivLength = openssl_cipher_iv_length(self::OPENSSL_FALLBACK_CIPHER);
        if (!$ivLength) {
            return false;
        }
        $iv = random_bytes($ivLength);
        $ciphertext = openssl_encrypt($contents, self::OPENSSL_FALLBACK_CIPHER, $binaryKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return false;
        }
        $hmac = hash_hmac('sha256', $iv.$ciphertext, $binaryKey, true);

        return self::MAGIC_CBC.$iv.$hmac.$ciphertext;
    }

    private static function decryptContents($payload, $key) {
        $binaryKey = self::getBinaryKey($key);

        if (strpos($payload, self::MAGIC_GCM) === 0) {
            $offset = strlen(self::MAGIC_GCM);
            $iv = substr($payload, $offset, 12);
            $tag = substr($payload, $offset + 12, 16);
            $ciphertext = substr($payload, $offset + 28);

            return openssl_decrypt($ciphertext, self::OPENSSL_CIPHER, $binaryKey, OPENSSL_RAW_DATA, $iv, $tag);
        }

        if (strpos($payload, self::MAGIC_CBC) === 0) {
            $offset = strlen(self::MAGIC_CBC);
            $ivLength = openssl_cipher_iv_length(self::OPENSSL_FALLBACK_CIPHER);
            $iv = substr($payload, $offset, $ivLength);
            $hmac = substr($payload, $offset + $ivLength, 32);
            $ciphertext = substr($payload, $offset + $ivLength + 32);
            $expectedHmac = hash_hmac('sha256', $iv.$ciphertext, $binaryKey, true);

            if (!hash_equals($expectedHmac, $hmac)) {
                return false;
            }

            return openssl_decrypt($ciphertext, self::OPENSSL_FALLBACK_CIPHER, $binaryKey, OPENSSL_RAW_DATA, $iv);
        }

        return false;
    }

    public function encrypt() {
        foreach ($this->getFiles(false) as $file) {
            $outputFile = $file.self::ENCRYPTED_EXTENSION;
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }

            $encryptedContents = self::encryptContents(file_get_contents($file), $this->symmetricKey);
            if ($encryptedContents === false) {
                return false;
            }

            file_put_contents($outputFile, $encryptedContents);
            self::hardUnlink($file);
        }

        return true;
    }

    public function decryptFile($file) {
        if (file_exists($file.self::ENCRYPTED_EXTENSION) === false && file_exists($file.self::LEGACY_ENCRYPTED_EXTENSION) === false) {
            return $file;
        }
        if (!$this->symmetricKey) {
            return false;
        }
        $decryptTmpFile = sys_get_temp_dir()."/".uniqid('pdfsignature.decrypted.'.getmypid().md5($file), true).'_'.basename($file);

        $encryptedFile = $file.self::ENCRYPTED_EXTENSION;
        if (!file_exists($encryptedFile)) {
            $encryptedFile = $file.self::LEGACY_ENCRYPTED_EXTENSION;
        }

        $this->runDecryptFile($encryptedFile, $decryptTmpFile);

        if (!file_exists($decryptTmpFile)) {
            return false;
        }

        return $decryptTmpFile;
    }

    public function runDecryptFile($file, $outputFile) {
        $payload = file_get_contents($file);
        $contents = self::decryptContents($payload, $this->symmetricKey);
        if ($contents === false) {
            return false;
        }

        return file_put_contents($outputFile, $contents);
    }

    public function isEncrypted() {
        return self::isPathEncrypted($this->pathHash);
    }

    public static function isPathEncrypted($pathHash) {
        return file_exists($pathHash.'/filename.txt'.self::ENCRYPTED_EXTENSION)
            || file_exists($pathHash.'/filename.txt'.self::LEGACY_ENCRYPTED_EXTENSION);
    }

    public static function hardUnlink($element) {
        if (file_exists($element) === false) {
            return;
        }
        if (is_dir($element)) {
            foreach (glob($element.'/*') as $file) {
                self::hardUnlink($file);
            }
            rmdir($element);
            return;
        }
        $eraser = str_repeat(0, strlen(file_get_contents($element)));
        file_put_contents($element, $eraser);
        unlink($element);
    }

    public static function protectSymmetricKey($key) {
        return preg_replace('/[^0-9a-zA-Z]*/', '', $key);
    }

    public static function createSymmetricKey($length = 15) {
        $keySpace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $pieces = array();
        $max = mb_strlen($keySpace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $pieces []= $keySpace[random_int(0, $max)];
        }

        return implode('', $pieces);
    }

    public static function isGpgInstalled() {
        if (!function_exists('openssl_encrypt')) {
            return array(false);
        }

        return array('OpenSSL '.OPENSSL_VERSION_TEXT);
    }
}
