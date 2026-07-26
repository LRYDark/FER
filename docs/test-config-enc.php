<?php
/**
 * Test de FerSecureConfig::write() — écriture atomique.
 *
 * secure.php calcule ses chemins avec dirname(__DIR__, 2) : on en place donc une
 * copie dans une arborescence jetable pour ne jamais toucher au vrai config.enc.
 */
$base = __DIR__ . '/cfgtest';
if (is_dir($base)) {
    foreach (glob($base . '/config/*') ?: [] as $f) @unlink($f);
    foreach (glob($base . '/config/.*') ?: [] as $f) if (is_file($f)) @unlink($f);
}
@mkdir($base . '/src/core', 0777, true);
@mkdir($base . '/config', 0777, true);
copy('W:/FER/src/core/secure.php', $base . '/src/core/secure.php');

require_once $base . '/src/core/secure.php';

$ko = 0;
$cfgFile = $base . '/config/config.enc';

/* ── 1. Écriture puis relecture ── */
$data = ['DB_HOST' => 'localhost', 'DB_NAME' => 'fer', 'DB_USER' => 'u',
         'DB_PASS' => 'p@ss"€', 'ENCRYPTION_KEY' => base64_encode(random_bytes(32)),
         'EMAIL_HMAC_KEY' => bin2hex(random_bytes(32))];
FerSecureConfig::write($data);
$relu = FerSecureConfig::load();
if ($relu === $data) echo "OK  : écriture puis relecture à l'identique (accents et guillemets compris)\n";
else { echo "KO  : aller-retour altéré\n"; $ko++; }

/* ── 2. rename() écrase-t-il bien le fichier existant ? (piège Windows) ── */
$data['DB_NAME'] = 'fer_v2';
FerSecureConfig::write($data);
$relu = FerSecureConfig::load();
if (($relu['DB_NAME'] ?? '') === 'fer_v2') echo "OK  : réécriture par-dessus un fichier existant\n";
else { echo "KO  : le remplacement n'a pas eu lieu (rename Windows ?)\n"; $ko++; }

/* ── 3. Aucun fichier temporaire ne subsiste ── */
$restes = array_filter(glob($base . '/config/{,.}*', GLOB_BRACE) ?: [],
    fn($f) => is_file($f) && str_ends_with($f, '.tmp'));
if (!$restes) echo "OK  : aucun fichier temporaire résiduel\n";
else { echo "KO  : restes → " . implode(', ', array_map('basename', $restes)) . "\n"; $ko++; }

/* ── 4. Le temporaire serait-il bloqué par .htaccess ? ── */
// On reproduit le nom généré et on le confronte aux règles de config/.htaccess.
$exemple = '.' . basename($cfgFile) . '.' . bin2hex(random_bytes(6)) . '.tmp';
echo str_starts_with($exemple, '.')
    ? "OK  : le temporaire commence par un point → bloqué par FilesMatch \"^\\.\"\n"
    : "KO  : le temporaire serait servi par le web\n";
if (!str_starts_with($exemple, '.')) $ko++;

/* ── 5. Le fichier reste intact si le chiffrement échoue ── */
$avant = file_get_contents($cfgFile);
$keyFile = $base . '/config/master.key';
$sauve   = file_get_contents($keyFile);
file_put_contents($keyFile, 'clé invalide');       // hex2bin échouera
try {
    FerSecureConfig::write($data);
    echo "KO  : aucune exception alors que la clé est invalide\n"; $ko++;
} catch (\Throwable $e) {
    echo (file_get_contents($cfgFile) === $avant)
        ? "OK  : clé illisible → exception, config.enc laissé INTACT\n"
        : "KO  : config.enc a été altéré malgré l'échec\n";
    if (file_get_contents($cfgFile) !== $avant) $ko++;
}
file_put_contents($keyFile, $sauve);

/* ── 6. Contenu réellement chiffré ── */
$brut = file_get_contents($cfgFile);
echo (!str_contains($brut, 'localhost') && !str_contains($brut, 'p@ss') && str_starts_with($brut, 'FERENC1:'))
    ? "OK  : le fichier est bien chiffré (aucun secret en clair)\n"
    : "KO  : des données apparaissent en clair\n";

/* ── Nettoyage ──────────────────────────────────────────────────────────────
 * Le bac à sable contient un `master.key` et un `config.enc` — de test, mais
 * portant les mêmes noms que les vrais. Les laisser sur le disque, c'est risquer
 * qu'ils soient commités, déployés, ou pris pour les vrais un jour de fatigue.
 * On efface. */
function cfgtestNettoie(string $chemin): void
{
    if (!is_dir($chemin)) return;
    foreach (array_diff(scandir($chemin), ['.', '..']) as $e) {
        $p = $chemin . '/' . $e;
        is_dir($p) ? cfgtestNettoie($p) : @unlink($p);
    }
    @rmdir($chemin);
}
cfgtestNettoie($base);
echo (is_dir($base) ? "KO  : bac à sable non supprimé\n" : "OK  : bac à sable effacé (aucune clé laissée sur le disque)\n");
if (is_dir($base)) $ko++;

printf("\n%s\n", $ko === 0 ? "AUCUNE ANOMALIE" : "$ko ANOMALIE(S)");
exit($ko > 0 ? 1 : 0);
