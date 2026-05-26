<?php

namespace App;

class PKIManager {
    private string $pkiDir = '/data/pki';

    public function caExists(): bool {
        return file_exists($this->pkiDir . '/ca.crt')
            && file_exists($this->pkiDir . '/ca.key');
    }

    public function wildcardExists(): bool {
        return file_exists($this->pkiDir . '/wildcard.crt')
            && file_exists($this->pkiDir . '/wildcard.key');
    }

    public function getWildcardDomain(): ?string {
        return $this->readMeta()['wildcard_domain'] ?? null;
    }

    public function getStatus(): array {
        return [
            'ca'       => $this->parseCert($this->pkiDir . '/ca.crt'),
            'wildcard' => $this->parseWildcard(),
        ];
    }

    public function generateCA(): void {
        $this->ensureDir();
        $cfg = $this->tempConfig($this->caConfigContent());
        try {
            $key = openssl_pkey_new([
                'private_key_bits' => 4096,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config'           => $cfg,
            ]);
            if (!$key) throw new \RuntimeException('CA key generation failed: ' . openssl_error_string());

            $csr = openssl_csr_new(
                ['CN' => 'Lightbox Local CA', 'O' => 'Lightbox Server'],
                $key,
                ['config' => $cfg, 'digest_alg' => 'sha256']
            );
            if (!$csr) throw new \RuntimeException('CA CSR failed: ' . openssl_error_string());

            $cert = openssl_csr_sign($csr, null, $key, 3650, [
                'config'          => $cfg,
                'x509_extensions' => 'v3_ca',
                'digest_alg'      => 'sha256',
            ], random_int(1, PHP_INT_MAX));
            if (!$cert) throw new \RuntimeException('CA signing failed: ' . openssl_error_string());

            $keyPem = '';
            openssl_pkey_export($key, $keyPem, null, ['config' => $cfg]);
            $certPem = '';
            openssl_x509_export($cert, $certPem);

            file_put_contents($this->pkiDir . '/ca.key', $keyPem);
            chmod($this->pkiDir . '/ca.key', 0600);
            file_put_contents($this->pkiDir . '/ca.crt', $certPem);
        } finally {
            @unlink($cfg);
        }
    }

    public function generateWildcard(string $domain): void {
        if (!$this->caExists()) {
            throw new \RuntimeException('CA must exist before generating a wildcard certificate.');
        }
        $this->ensureDir();
        $cfg = $this->tempConfig($this->wildcardConfigContent($domain));
        try {
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config'           => $cfg,
            ]);
            if (!$key) throw new \RuntimeException('Wildcard key generation failed: ' . openssl_error_string());

            $csr = openssl_csr_new(
                ['CN' => '*.' . $domain],
                $key,
                ['config' => $cfg, 'digest_alg' => 'sha256', 'req_extensions' => 'v3_req']
            );
            if (!$csr) throw new \RuntimeException('Wildcard CSR failed: ' . openssl_error_string());

            $caCert = openssl_x509_read(file_get_contents($this->pkiDir . '/ca.crt'));
            $caKey  = openssl_pkey_get_private(file_get_contents($this->pkiDir . '/ca.key'));

            $cert = openssl_csr_sign($csr, $caCert, $caKey, 825, [
                'config'          => $cfg,
                'x509_extensions' => 'v3_server',
                'digest_alg'      => 'sha256',
            ], random_int(1, PHP_INT_MAX));
            if (!$cert) throw new \RuntimeException('Wildcard signing failed: ' . openssl_error_string());

            $keyPem = '';
            openssl_pkey_export($key, $keyPem, null, ['config' => $cfg]);
            $certPem = '';
            openssl_x509_export($cert, $certPem);

            file_put_contents($this->pkiDir . '/wildcard.key', $keyPem);
            chmod($this->pkiDir . '/wildcard.key', 0600);
            file_put_contents($this->pkiDir . '/wildcard.crt', $certPem);

            $this->writeMeta(['wildcard_domain' => $domain]);
        } finally {
            @unlink($cfg);
        }
    }

    public function generateAll(string $domain): void {
        $this->generateCA();
        $this->generateWildcard($domain);
    }

    private function parseCert(string $path): ?array {
        if (!file_exists($path)) return null;
        $cert = @openssl_x509_read(file_get_contents($path));
        if (!$cert) return null;
        $info = openssl_x509_parse($cert);
        return [
            'subject'    => $info['subject']['CN'] ?? 'Unknown',
            'valid_from' => date('Y-m-d', $info['validFrom_time_t']),
            'valid_to'   => date('Y-m-d', $info['validTo_time_t']),
            'expired'    => $info['validTo_time_t'] < time(),
            'issuer'     => $info['issuer']['CN'] ?? 'Unknown',
        ];
    }

    private function parseWildcard(): ?array {
        $info = $this->parseCert($this->pkiDir . '/wildcard.crt');
        if ($info === null) return null;
        $cert = openssl_x509_read(file_get_contents($this->pkiDir . '/wildcard.crt'));
        $raw  = openssl_x509_parse($cert);
        $sans = [];
        foreach (explode(', ', $raw['extensions']['subjectAltName'] ?? '') as $s) {
            if (str_starts_with($s, 'DNS:')) $sans[] = substr($s, 4);
        }
        $info['domain'] = $this->getWildcardDomain();
        $info['sans']   = $sans;
        return $info;
    }

    private function ensureDir(): void {
        if (!file_exists($this->pkiDir)) {
            mkdir($this->pkiDir, 0700, true);
        }
    }

    private function tempConfig(string $content): string {
        $path = tempnam(sys_get_temp_dir(), 'pki_');
        file_put_contents($path, $content);
        return $path;
    }

    private function readMeta(): array {
        $path = $this->pkiDir . '/meta.json';
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function writeMeta(array $data): void {
        file_put_contents($this->pkiDir . '/meta.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    private function caConfigContent(): string {
        return <<<'CONF'
[req]
default_bits       = 4096
default_md         = sha256
distinguished_name = req_distinguished_name
x509_extensions    = v3_ca
prompt             = no

[req_distinguished_name]
CN = Lightbox Local CA
O  = Lightbox Server

[v3_ca]
subjectKeyIdentifier = hash
basicConstraints     = critical,CA:true
keyUsage             = critical,keyCertSign,cRLSign
CONF;
    }

    private function wildcardConfigContent(string $domain): string {
        return <<<CONF
[req]
default_bits       = 2048
default_md         = sha256
distinguished_name = req_distinguished_name
req_extensions     = v3_req
prompt             = no

[req_distinguished_name]
CN = *.$domain

[v3_req]
subjectAltName = @alt_names

[v3_server]
subjectKeyIdentifier   = hash
authorityKeyIdentifier = keyid
basicConstraints       = CA:false
keyUsage               = critical,digitalSignature,keyEncipherment
extendedKeyUsage       = serverAuth
subjectAltName         = @alt_names

[alt_names]
DNS.1 = *.$domain
DNS.2 = $domain
CONF;
    }
}
