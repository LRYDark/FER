<?php
/**
 * Configuration chiffrée — Forbach en Rose v2.0.0
 *
 * Les secrets (identifiants BDD, ENCRYPTION_KEY…) ne sont plus stockés en
 * clair dans config/.env mais chiffrés dans config/config.enc, déchiffrable
 * uniquement avec config/master.key. Même principe que MERIDIAN : les deux
 * fichiers sont illisibles séparément et bloqués côté web (config/.htaccess).
 *
 * Chiffrement : AES-256-GCM (OpenSSL, déjà prérequis obligatoire du site).
 * Le chemin du fichier de clé peut être déplacé hors du webroot via la
 * variable d'environnement FER_KEY_FILE.
 */

final class FerSecureConfig
{
    /** Préfixe de format du blob chiffré (permet une évolution future). */
    private const PREFIX = 'FERENC1:';

    /** Clés obligatoires pour considérer l'installation comme complète. */
    public const REQUIRED_KEYS = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ENCRYPTION_KEY'];

    public static function configFile(): string
    {
        return dirname(__DIR__, 2) . '/config/config.enc';
    }

    public static function keyFile(): string
    {
        return $_SERVER['FER_KEY_FILE']
            ?? (getenv('FER_KEY_FILE') ?: dirname(__DIR__, 2) . '/config/master.key');
    }

    public static function exists(): bool
    {
        return is_file(self::configFile());
    }

    /** Crée master.key (32 octets aléatoires, hex) s'il n'existe pas déjà. */
    public static function ensureKey(): void
    {
        $file = self::keyFile();
        if (is_file($file)) {
            return;
        }
        // Création EXCLUSIVE ('x') : si deux requêtes simultanées arrivent ici,
        // une seule écrit la clé, l'autre réutilise le fichier existant.
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            if (is_file($file)) {
                return; // créé entre-temps par une autre requête
            }
            throw new \RuntimeException('Impossible d\'écrire le fichier de clé (config/master.key).');
        }
        fwrite($fh, bin2hex(random_bytes(32)));
        fclose($fh);
        @chmod($file, 0600);
    }

    private static function key(): string
    {
        $hex = @file_get_contents(self::keyFile());
        if ($hex === false) {
            throw new \RuntimeException('Fichier de clé introuvable (config/master.key).');
        }
        $bin = @hex2bin(trim($hex));
        if ($bin === false || strlen($bin) !== 32) {
            throw new \RuntimeException('Fichier de clé invalide (config/master.key).');
        }
        return $bin;
    }

    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12); // 96 bits (GCM)
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Échec du chiffrement de la configuration.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $blob): string
    {
        $blob = trim($blob);
        if (strncmp($blob, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            throw new \RuntimeException('Format de config.enc inconnu.');
        }
        $raw = base64_decode(substr($blob, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Fichier config.enc corrompu.');
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Déchiffrement de config.enc impossible (mauvaise master.key ?).');
        }
        return $plain;
    }

    /**
     * Charge et déchiffre config.enc.
     * @return array<string, string> paires clé => valeur (mêmes clés que l'ancien .env)
     */
    public static function load(): array
    {
        $blob = @file_get_contents(self::configFile());
        if ($blob === false) {
            throw new \RuntimeException('Fichier config.enc introuvable.');
        }
        $data = json_decode(self::decrypt($blob), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Contenu de config.enc invalide.');
        }
        return $data;
    }

    /**
     * Écrit config.enc de façon ATOMIQUE.
     *
     * POURQUOI : ce fichier porte les identifiants de la base et la clé de
     * chiffrement. Une écriture directe interrompue (disque plein, coupure,
     * quota) le laisserait tronqué — et un config.enc tronqué, c'est le site
     * entier inaccessible, sans possibilité de le régénérer.
     *
     * On écrit donc dans un fichier temporaire, puis on le déplace par rename(),
     * opération atomique au sein d'un même système de fichiers : à aucun instant
     * config.enc n'existe à l'état incomplet. En cas d'échec, l'ancien fichier
     * est intact et le temporaire est retiré.
     *
     * Le nom du temporaire commence par un point : il tombe ainsi sous la règle
     * `FilesMatch "^\."` de config/.htaccess et n'est jamais servi par le web,
     * même pendant le bref instant où il existe.
     *
     * @param array<string, string> $data
     */
    public static function write(array $data): void
    {
        self::ensureKey();
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Chiffrement AVANT toute écriture : si la clé est illisible ou le
        // chiffrement échoue, l'exception part sans avoir touché au fichier.
        $blob = self::encrypt((string) $json);

        $file = self::configFile();
        $tmp  = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $ecrits = @file_put_contents($tmp, $blob, LOCK_EX);
        if ($ecrits === false || $ecrits !== strlen($blob)) {
            @unlink($tmp);   // écriture partielle : on ne garde rien
            throw new \RuntimeException('Impossible d\'écrire config/config.enc (écriture temporaire incomplète).');
        }
        @chmod($tmp, 0600);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Impossible de remplacer config/config.enc (droits du dossier config/ ?).');
        }
        @chmod($file, 0600);
    }

    /** @return bool true si toutes les clés obligatoires sont présentes et non vides */
    public static function isComplete(array $data): bool
    {
        foreach (self::REQUIRED_KEYS as $k) {
            if (!isset($data[$k]) || $data[$k] === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Parse un fichier .env (KEY=VALEUR, lignes # ignorées).
     * @return array<string, string>
     */
    public static function parseEnvFile(string $path): array
    {
        $data  = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            // Retirer d'éventuels guillemets englobants ("valeur" ou 'valeur')
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '') {
                $data[$key] = $val;
            }
        }
        return $data;
    }

    /**
     * Migre un ancien config/.env vers config.enc + master.key.
     * Le .env n'est PAS supprimé ici (c'est update.php qui s'en charge, après
     * vérification) : en cas de problème, l'original reste intact.
     *
     * @return array<string, string>|null la config migrée, ou null si .env absent/incomplet
     */
    public static function migrateFromEnv(string $envPath): ?array
    {
        if (!is_file($envPath)) {
            return null;
        }
        $data = self::parseEnvFile($envPath);
        if (!self::isComplete($data)) {
            return null;
        }
        self::write($data);
        // Relire pour garantir que le round-trip chiffrement/déchiffrement fonctionne
        $check = self::load();
        if (!self::isComplete($check)) {
            throw new \RuntimeException('Vérification post-migration échouée.');
        }
        return $check;
    }

    /**
     * Injecte la configuration dans $_ENV (et putenv) pour compatibilité avec
     * tout le code existant qui lit $_ENV['DB_HOST'], $_ENV['ENCRYPTION_KEY'],
     * $_ENV['TRUSTED_PROXIES'], $_ENV['SYNC_WORKER_TOKEN']…
     * @param array<string, string> $data
     */
    public static function exportToEnv(array $data): void
    {
        foreach ($data as $k => $v) {
            if (!is_string($k) || $k === '' || !is_scalar($v)) {
                continue;
            }
            $_ENV[$k] = (string) $v;
            @putenv($k . '=' . $v);
        }
    }
}
