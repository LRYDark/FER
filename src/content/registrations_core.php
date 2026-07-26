<?php
/**
 * registrations_core.php — Noyau métier partagé des inscriptions.
 *
 * Ce fichier regroupe la logique « import Excel » et « nouvel inscrit » afin
 * qu'elle puisse être réutilisée par l'API externe (api.php) avec EXACTEMENT
 * le même comportement que le dashboard (gestion des doublons, chiffrement
 * des données personnelles, envoi des mails de confirmation avec QR Code
 * selon la configuration).
 *
 * IMPORTANT : ce fichier reproduit fidèlement la logique des routes
 * `import-excel` et `registrations` de admin-api.php. Si cette logique
 * évolue côté dashboard, penser à répercuter la modification ici.
 *
 * Toutes les fonctions sont préfixées `regcore_` pour éviter toute collision.
 */

require_once __DIR__ . '/../core/config.php';   // $pdo, encrypt(), decrypt(), decryptRows()
require_once __DIR__ . '/../../vendor/autoload.php';

/* ───────────────────────── Helpers utilitaires ───────────────────────── */

/**
 * Normalise un libellé de colonne Excel (accents → ASCII, minuscules, espaces).
 * Copie conforme de normaliseLabel() de admin-api.php.
 */
function regcore_normaliseLabel(string $label): string
{
    $accents = [
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a','ą'=>'a',
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ę'=>'e',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o',
        'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N',
        'ý'=>'y','ÿ'=>'y','Ý'=>'Y','š'=>'s','ž'=>'z',
    ];
    $label = strtr($label, $accents);
    $label = preg_replace('/[^a-zA-Z0-9 ]/', '', $label);
    return strtolower(trim(preg_replace('/\s+/', ' ', $label)));
}

/**
 * Catégorie d'inscrit (« prestation ») déduite du mode de paiement.
 * Copie conforme de prestationFromPaiement() de admin-api.php :
 *   gratuit → enfant_gratuit ; enfant_tshirt → enfant_tshirt ; sinon → tarif_unique.
 */
function regcore_prestationFromPaiement(?string $paiementMode): string
{
    $p = strtolower(trim((string) $paiementMode));
    if ($p === 'gratuit')       return 'enfant_gratuit';
    if ($p === 'enfant_tshirt') return 'enfant_tshirt';
    return 'tarif_unique';
}

/**
 * Mode de paiement à STOCKER à partir du choix. Copie conforme de storedPaiementMode().
 * « enfant_tshirt » (a payé) → « en ligne (CB) » ; « gratuit » et moyens réels conservés.
 */
function regcore_storedPaiementMode(?string $choice): ?string
{
    return strtolower(trim((string) $choice)) === 'enfant_tshirt' ? 'en ligne (CB)' : $choice;
}

/**
 * Normalise le « Moyen de paiement » Excel (AssoConnect) vers la valeur stockée.
 * Copie conforme de normalisePaiementMode() de admin-api.php : un import
 * AssoConnect = paiement en ligne, donc carte → 'en ligne (CB)'. Conserve le
 * vrai moyen (cheque/espece/gratuit) ; repli 'en ligne (CB)'.
 */
function regcore_normalisePaiementMode(?string $val): string
{
    $v = strtolower(trim($val ?? ''));
    if ($v === '') return 'en ligne (CB)';
    if (str_contains($v, 'gratuit'))                              return 'gratuit';
    if (str_contains($v, 'cheque') || str_contains($v, 'chèque')) return 'cheque';
    if (str_contains($v, 'espece') || str_contains($v, 'espèce')) return 'espece';
    if (str_contains($v, 'carte') || str_contains($v, 'cb') || str_contains($v, 'bancaire')) return 'en ligne (CB)';
    return 'en ligne (CB)';
}

/** Normalise une valeur de sexe. Copie conforme de normaliseSexe(). */
function regcore_normaliseSexe(?string $val): ?string
{
    $v = strtoupper(trim($val ?? ''));
    return match ($v) {
        'H', 'M', 'HOMME', 'MALE'  => 'H',
        'F', 'FEMME', 'FEMALE'     => 'F',
        ''                         => null,
        default                    => 'Autre'
    };
}

/** Convertit une date Excel (numérique ou texte) en 'Y-m-d H:i:s'. */
function regcore_convertExcelDate($value): ?string
{
    if ($value === null || $value === '') {
        return date('Y-m-d H:i:s');
    }
    // Numéro de série Excel : conversion NON ambiguë (seul ce cas l'est vraiment).
    // gmdate (et non date) : excelToTimestamp interprète la série en UTC ; gmdate
    // restitue donc la valeur faciale exacte, sans décalage selon le fuseau PHP.
    if (is_numeric($value)) {
        return gmdate('Y-m-d H:i:s', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float) $value));
    }
    // Repli TEXTE : interprété en d/m/Y (français). ⚠ Une date fournie en texte au
    // format US (mm/dd) RESTE ambiguë et peut être inversée — contrairement à la
    // branche numérique ci-dessus. Le « ! » remet l'heure à 00:00:00 et tout
    // débordement (ex. mois 13 d'une date US mal lue) est rejeté plutôt que de
    // basculer silencieusement la date dans le futur.
    $value = trim((string) $value);
    error_log('[IMPORT][DATE-TEXTE] date non numérique interprétée en d/m/Y : ' . substr($value, 0, 40));
    $formats = ['d/m/Y H:i:s', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d'];
    foreach ($formats as $f) {
        $dt   = DateTime::createFromFormat('!' . $f, $value);
        $errs = DateTime::getLastErrors();
        $hasErr = is_array($errs) && (($errs['warning_count'] ?? 0) > 0 || ($errs['error_count'] ?? 0) > 0);
        if ($dt && !$hasErr) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return date('Y-m-d H:i:s');
}

/**
 * Calcule l'âge (en années révolues) à partir d'une valeur `naissance`.
 * Miroir PHP de ageFromBirth() de js/inscription-form.js. Gère le NOUVEAU modèle
 * (âge déjà stocké : 1 à 3 chiffres ≤ 120 → renvoyé tel quel) ET les données
 * héritées / archives :
 *   - une année seule « AAAA »                  → âge = (année réf) − année
 *   - une date « JJ/MM/AAAA » ou « AAAA-MM-JJ » → âge ajusté selon l'anniversaire
 * Retourne null si la valeur est vide ou non interprétable.
 *
 * @param int|null $refYear Année de référence pour les valeurs au format année/date
 *        (archives) : l'âge d'une archive 2023 se calcule sur 2023, pas sur l'année
 *        courante. Défaut : année courante.
 */
function regcore_ageFromNaissance(?string $naissance, ?int $refYear = null): ?int
{
    $b = trim((string) $naissance);
    if ($b === '') return null;

    $now    = new DateTime('today');
    $nowY   = (int) $now->format('Y');
    $ref    = ($refYear !== null && $refYear > 1900) ? $refYear : $nowY;

    // Retire une éventuelle heure en suffixe (ex. « 2000-05-09 00:00:00 » d'un client
    // API) pour que la date brute matche le format attendu.
    $b = preg_replace('/[T ]\d{2}:\d{2}(:\d{2})?$/', '', $b);

    // Âge déjà stocké (nouveau modèle) : 1 à 3 chiffres ≤ 120.
    if (preg_match('/^\d{1,3}$/', $b)) {
        $a = (int) $b;
        return ($a >= 0 && $a <= 120) ? $a : null;
    }

    // Données héritées / archives : année ou date.
    $y = null; $m = 1; $d = 1;
    if (preg_match('/^\d{4}$/', $b)) {
        $y = (int) $b;
        if ($y < 1900 || $y > $nowY) return null;
        return $ref - $y;
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $b, $p)) {
        $y = (int) $p[1]; $m = (int) $p[2]; $d = (int) $p[3];
    } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $b, $p)) {
        $d = (int) $p[1]; $m = (int) $p[2]; $y = (int) $p[3];
    } else {
        return null;
    }
    if ($y < 1900 || $y > $nowY) return null;
    if ($m < 1 || $m > 12) { $m = 1; }
    if ($d < 1 || $d > 31) { $d = 1; }

    // Année courante : âge exact ajusté à l'anniversaire. Archive : approximation.
    if ($ref === $nowY) {
        $birth = DateTime::createFromFormat('!Y-n-j', sprintf('%04d-%d-%d', $y, $m, $d));
        if (!$birth) return null;
        return (int) $now->diff($birth)->y;
    }
    return $ref - $y;
}

/**
 * Convertit une valeur « naissance » brute d'import (âge, année, ou date complète
 * type « 09/05/2000 ») en ÂGE stocké (chaîne d'entier), ou null si illisible.
 * Utilisé par les imports Excel : on ne conserve QUE l'âge, jamais la date.
 */
function regcore_naissanceToAge($value): ?string
{
    $age = regcore_ageFromNaissance(is_string($value) ? $value : (string) $value);
    return $age === null ? null : (string) $age;
}

/* ────────────────────── Date de naissance (lot 1) ─────────────────────────── */

/**
 * Interprète une valeur brute de `naissance` et en déduit l'année de naissance.
 *
 * ORDRE D'ÉVALUATION IMPOSÉ (ne pas réordonner) :
 *   1. date complète  (JJ/MM/AAAA, AAAA-MM-JJ, JJ-MM-AAAA, JJ.MM.AAAA)
 *   2. année sur 4 chiffres (1900 → année de l'édition)
 *   3. valeur de 1 ou 2 chiffres
 *   4. âge sur 3 chiffres (≤ 120)
 *   5. inconnu
 *
 * ⚠️ L'âge se calcule sur l'année de l'ÉDITION DE LA LIGNE, jamais sur l'année
 * courante : « 42 ans » sur une archive 2023 donne 1981, pas 1984. Se tromper
 * ici décale silencieusement tout l'historique des catégories d'âge.
 *
 * ⚠️ Cas des valeurs de 1 ou 2 chiffres. « 85 » peut être un âge (né en 1941)
 * comme une année abrégée (1985). Le site ne stockant QUE des âges depuis
 * regcore_naissanceToAge(), le comportement retenu est de les interpréter comme
 * un âge — mais la ligne est systématiquement marquée `age_court` dans le
 * rapport pour rester vérifiable. $shortAsAge = false rétablit la prudence
 * maximale (valeur classée « inconnu », listée comme ambiguë).
 *
 * @param  string|null $raw          valeur brute de `naissance` (déjà déchiffrée)
 * @param  int         $editionYear  année de l'édition de la ligne traitée
 * @param  bool        $shortAsAge   interpréter 1-2 chiffres comme un âge
 * @return array{annee: ?int, date: ?string, source: string, note: string}
 *         source ∈ date|annee|age|inconnu ; note ∈ ''|age_court|ambigu|hors_plage|vide
 */
function regcore_parseNaissance(?string $raw, int $editionYear, bool $shortAsAge = true): array
{
    $none = ['annee' => null, 'date' => null, 'source' => 'inconnu', 'note' => ''];

    $v = trim((string) $raw);
    if ($v === '') return ['annee' => null, 'date' => null, 'source' => 'inconnu', 'note' => 'vide'];

    // Retire une heure en suffixe (« 2000-05-09 00:00:00 » venant d'un client API)
    $v = preg_replace('/[T ]\d{1,2}:\d{2}(:\d{2})?$/', '', $v);
    $v = trim($v);

    $minYear = 1900;
    $maxYear = $editionYear;

    // ── 1. Date complète ────────────────────────────────────────────────────
    $d = $m = $y = null;
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $v, $p)) {
        $y = (int) $p[1]; $m = (int) $p[2]; $d = (int) $p[3];        // AAAA-MM-JJ
    } elseif (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $v, $p)) {
        $d = (int) $p[1]; $m = (int) $p[2]; $y = (int) $p[3];        // JJ/MM/AAAA
    }
    if ($y !== null) {
        if ($y < $minYear || $y > $maxYear || !checkdate($m, $d, $y)) {
            return ['annee' => null, 'date' => null, 'source' => 'inconnu', 'note' => 'hors_plage'];
        }
        return [
            'annee'  => $y,
            'date'   => sprintf('%04d-%02d-%02d', $y, $m, $d),
            'source' => 'date',
            'note'   => '',
        ];
    }

    // ── 2. Année seule sur 4 chiffres ───────────────────────────────────────
    if (preg_match('/^\d{4}$/', $v)) {
        $y = (int) $v;
        if ($y < $minYear || $y > $maxYear) {
            return ['annee' => null, 'date' => null, 'source' => 'inconnu', 'note' => 'hors_plage'];
        }
        return ['annee' => $y, 'date' => null, 'source' => 'annee', 'note' => ''];
    }

    // ── 3. Valeur de 1 ou 2 chiffres (âge court, potentiellement ambigu) ────
    if (preg_match('/^\d{1,2}$/', $v)) {
        $age = (int) $v;
        if (!$shortAsAge) {
            return ['annee' => null, 'date' => null, 'source' => 'inconnu', 'note' => 'ambigu'];
        }
        return ['annee' => $editionYear - $age, 'date' => null, 'source' => 'age', 'note' => 'age_court'];
    }

    // ── 4. Âge sur 3 chiffres ───────────────────────────────────────────────
    if (preg_match('/^\d{3}$/', $v)) {
        $age = (int) $v;
        if ($age > 120) {
            return ['annee' => null, 'date' => null, 'source' => 'inconnu', 'note' => 'hors_plage'];
        }
        return ['annee' => $editionYear - $age, 'date' => null, 'source' => 'age', 'note' => ''];
    }

    // ── 5. Inconnu ──────────────────────────────────────────────────────────
    return $none;
}

/**
 * Colonnes de naissance dérivées d'une valeur brute, prêtes à être insérées.
 *
 * Appelée à CHAQUE création d'inscription : sans elle, toute nouvelle inscription
 * arriverait avec `annee_naissance` vide et `naissance_source = 'inconnu'`, et il
 * faudrait relancer l'outil de normalisation après chaque vague d'inscriptions.
 *
 * La valeur passée est la valeur BRUTE saisie (âge, année ou date complète), pas
 * celle déjà convertie en âge : une vraie date de naissance renseigne alors aussi
 * `date_naissance`.
 *
 * @return array<string,mixed> tableau colonne => valeur, vide si la migration du
 *         lot 1 n'est pas encore passée (le code peut être déployé avant update.php)
 */
function regcore_naissanceColumns(PDO $pdo, $brut, ?int $editionId = null): array
{
    if (!fer_hasColumn($pdo, 'registrations', 'annee_naissance')) return [];

    $p = regcore_parseNaissance((string) $brut, fer_editionYear($pdo, $editionId), true);
    return [
        'annee_naissance'  => $p['annee'],
        'date_naissance'   => $p['date'],
        'naissance_source' => $p['source'],
    ];
}

/** Journalise une erreur d'import dans storage/logs/import_errors.log. */
function regcore_logImportError(array $data, string $filename = 'import_errors.log'): void
{
    $safePath = dirname(__DIR__, 2) . '/storage/logs/' . basename($filename);
    $entry = date('Y-m-d H:i:s') . ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($safePath, $entry, FILE_APPEND);
}

/* ──────────────────────── Nouvel inscrit (Phase 2) ────────────────────── */

/**
 * Crée un seul inscrit, exactement comme le bouton « Nouvel inscrit » du
 * dashboard : numéro d'inscription atomique, champs dynamiques du formulaire,
 * chiffrement des données personnelles, puis mail de confirmation optionnel
 * (avec QR Code selon la configuration `qrcode_mail_mode`).
 *
 * @param PDO         $pdo
 * @param array       $d        Données de l'inscrit (nom, prenom, email, ...).
 * @param bool        $sendMail Envoyer le mail de confirmation si un email est fourni.
 * @param string|null $origine  Origine à enregistrer (défaut : 'API').
 * @return array  ['ok'=>true,'inscription_no'=>'S123','mail_sent'=>bool,'qrcode_included'=>bool]
 * @throws Throwable en cas d'erreur SQL (la transaction est annulée).
 */
function regcore_createRegistration(PDO $pdo, array $d, bool $sendMail = true, ?string $origine = null): array
{
    require_once __DIR__ . '/form_fields.php';

    /* Validation / assainissement (identique au dashboard) */
    $allowedSexe = ['H', 'F', 'Autre'];
    $d['sexe']          = in_array($d['sexe'] ?? '', $allowedSexe, true) ? $d['sexe'] : 'Autre';
    $d['nom']           = mb_substr(trim($d['nom'] ?? ''), 0, 255);
    $d['prenom']        = mb_substr(trim($d['prenom'] ?? ''), 0, 255);
    $d['tel']           = mb_substr(trim($d['tel'] ?? ''), 0, 50);
    $d['ville']         = mb_substr(trim($d['ville'] ?? ''), 0, 255);
    $d['entreprise']    = mb_substr(trim($d['entreprise'] ?? ''), 0, 255);
    $d['paiement_mode'] = mb_substr(trim($d['paiement_mode'] ?? ''), 0, 50);
    $allowedTshirt = ['-', 'XS', 'S', 'M', 'L', 'XL', 'XXL'];
    $d['tshirt_size'] = in_array($d['tshirt_size'] ?? '', $allowedTshirt, true) ? $d['tshirt_size'] : '-';

    /* Champs obligatoires minimaux */
    if ($d['nom'] === '' || $d['prenom'] === '') {
        return ['ok' => false, 'error' => 'missing_fields',
                'message' => 'Les champs « nom » et « prenom » sont obligatoires.'];
    }

    /* Numéro d'inscription suivant — compteur atomique */
    $counterExists = false;
    try {
        $pdo->query('SELECT next_no FROM inscription_counter LIMIT 0');
        $counterExists = true;
    } catch (PDOException $e) { /* compteur absent : fallback ci-dessous */ }

    $pdo->beginTransaction();
    try {
        if ($counterExists) {
            $pdo->exec('UPDATE inscription_counter SET next_no = LAST_INSERT_ID(next_no + 1) WHERE id = 1');
            $no = 'S' . (int) $pdo->lastInsertId();
        } else {
            $no = 'S' . ((int) ($pdo->query(
                "SELECT MAX(CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED)) FROM registrations"
            )->fetchColumn() ?: 0) + 1);
        }

        /* Construction dynamique de l'INSERT à partir de la table forms */
        $fieldCols = getAllActiveFieldColumns($pdo);

        $cols = ['inscription_no'];
        $phs  = ['?'];
        $vals = [$no];

        foreach ($fieldCols as $col => $meta) {
            $raw = $d[$col] ?? '';
            // Champ « naissance » : on ne stocke QUE l'âge (une date/année reçue via
            // l'API externe est convertie en âge, cohérent avec les formulaires).
            if ($col === 'naissance' && $raw !== '') {
                $age = regcore_naissanceToAge((string) $raw);
                $raw = ($age !== null) ? $age : '';
            }
            $cols[] = "`{$col}`";
            $phs[]  = '?';
            $vals[] = $meta['encrypted'] ? encrypt($raw !== '' ? $raw : '') : ($raw !== '' ? $raw : '');
        }

        /* Calcul du montant dû : valeur explicite, sinon 0 pour gratuit, sinon le tarif standard */
        $paiement = $d['paiement_mode'] ?? '';
        if (array_key_exists('montant_du', $d) && is_numeric($d['montant_du'])) {
            $montantDu = (float) $d['montant_du'];
        } elseif (strcasecmp((string) $paiement, 'gratuit') === 0) {
            $montantDu = 0.0;
        } else {
            $registrationFee = (float) ($pdo->query('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);
            $montantDu = $registrationFee;
        }

        /* Champs système */
        $cols[] = 'origine';       $phs[] = '?'; $vals[] = $origine ?: ($d['origine'] ?? 'API');
        $cols[] = 'paiement_mode'; $phs[] = '?'; $vals[] = regcore_storedPaiementMode($d['paiement_mode'] ?? null);
        $cols[] = 'prestation';    $phs[] = '?'; $vals[] = regcore_prestationFromPaiement($d['paiement_mode'] ?? null);
        $cols[] = 'montant_du';    $phs[] = '?'; $vals[] = $montantDu;
        $cols[] = 'created_by';    $phs[] = '?'; $vals[] = null; // créé via API : aucun utilisateur

        /* Lot 1 — rattachement de l'inscription
         * `edition_id`      : sans lui, l'unicité (edition_id, inscription_no) est
         *                     inopérante et l'inscription n'apparaît dans aucune édition.
         * `email_normalise` : empreinte HMAC de l'adresse (cf. fer_emailHash), clé de
         *                     rattachement au compte coureur. `email` étant chiffré, elle
         *                     ne peut pas être recalculée en SQL : on l'écrit ici.
         * Colonnes ajoutées seulement si la migration du lot 1 est passée. */
        $editionId = fer_activeEditionId($pdo);
        if ($editionId !== null) {
            $cols[] = 'edition_id'; $phs[] = '?'; $vals[] = $editionId;
        }
        if (fer_hasColumn($pdo, 'registrations', 'email_normalise')) {
            $cols[] = 'email_normalise'; $phs[] = '?'; $vals[] = fer_emailHash($d['email'] ?? null);
        }
        // Année de naissance déduite de la valeur BRUTE saisie (avant conversion en
        // âge) : un âge se périme, une année de naissance non.
        foreach (regcore_naissanceColumns($pdo, $d['naissance'] ?? '', $editionId) as $c => $v) {
            $cols[] = $c; $phs[] = '?'; $vals[] = $v;
        }

        // Date d'inscription : si fournie (date réelle), on l'enregistre (antidatage possible
        // côté API) ; sinon DEFAULT du jour. created_at (date d'ajout) reste auto.
        $rawDateInsc = trim((string) ($d['date_inscription'] ?? ''));
        if ($rawDateInsc !== '') {
            $cols[] = 'date_inscription'; $phs[] = '?'; $vals[] = regcore_convertExcelDate($rawDateInsc);
        }

        $st = $pdo->prepare('INSERT INTO registrations (' . implode(',', $cols) . ') VALUES (' . implode(',', $phs) . ')');
        $st->execute($vals);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    /* Mail de confirmation (hors transaction, identique au dashboard) */
    $mailSent = false;
    $qrIncluded = false;
    $inscEmail = trim($d['email'] ?? '');
    if ($sendMail && $inscEmail !== '') {
        try {
            require_once __DIR__ . '/../mail/googleMail.php'; // charge le $data global (réglages complets)
            if (function_exists('shouldIncludeQrCode')) {
                $qrIncluded = shouldIncludeQrCode($no);
            }
            sendMail(
                $inscEmail,
                'Inscription enregistrée - Forbach en Rose',
                null, null,
                $d['nom'] ?? '', $d['prenom'] ?? '',
                'inscription', $no
            );
            $mailSent = true;
        } catch (\Throwable $e) {
            error_log('[REGCORE][MAIL] Inscription ' . $no . ' : ' . $e->getMessage());
            // L'échec du mail ne bloque jamais l'inscription.
        }
    }

    return [
        'ok'              => true,
        'inscription_no'  => $no,
        'mail_sent'       => $mailSent,
        'qrcode_included' => $qrIncluded,
    ];
}

/* ───────────────────────── Import Excel (Phase 1) ─────────────────────── */

/**
 * Fonction CANONIQUE d'import des inscrits, partagée par TOUS les chemins :
 *   - l'import manuel du dashboard (admin-api.php route `import-excel`),
 *   - l'API externe (api.php),
 *   - l'import automatique AssoConnect (src/content/sync_assoconnect.php → sync_run_import).
 *
 * Comportement strictement identique à l'import manuel : mapping des colonnes
 * via la table `import`, détection des doublons, tri chronologique, chiffrement
 * des données personnelles, montant pré-rempli au tarif si absent, puis envoi
 * optionnel des mails de confirmation. Le QR Code suit le réglage GLOBAL
 * `qrcode_mail_mode` (via shouldIncludeQrCode), exactement comme en manuel — ce
 * n'est PAS une option d'import.
 *
 * @param PDO           $pdo
 * @param string        $filePath     Chemin du fichier (uploadé ou local).
 * @param string        $originalName Nom d'origine (validation d'extension).
 * @param array         $options      ['send_mail'=>bool, 'created_by'=>?int, 'origine'=>?string]
 * @param callable|null $emit         Callback de progression optionnel (streaming NDJSON
 *                                    du dashboard). Reçoit un tableau d'événement à émettre.
 *                                    Null pour l'API externe / l'import auto (pas de streaming).
 * @return array  Sur succès : ['ok'=>true,'rows'=>int,'added'=>int,'skipped'=>int,
 *                'duplicates'=>int,'mails_sent'=>int,'mail_errors'=>int,'message'=>string,...].
 *                Sur échec   : ['ok'=>false,'http'=>int,'error'=>code,'message'=>texte,'missing'?=>[]].
 */
function importInscritsExcel(PDO $pdo, string $filePath, string $originalName, array $options = [], ?callable $emit = null): array
{
    $sendMail      = !empty($options['send_mail']);
    $createdBy     = $options['created_by'] ?? null;
    $defaultOrig   = $options['origine'] ?? 'AssoConnect';
    $emitFn        = is_callable($emit) ? $emit : null;
    $streamStarted = false;

    /* Validation extension + signature (identique au dashboard) */
    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = @mime_content_type($filePath) ?: '';
    $allowedExts  = ['xlsx', 'xls'];
    $allowedMimes = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',
        'text/xml',
        'application/xml',
        'text/html',
    ];
    $sig    = (string) @file_get_contents($filePath, false, null, 0, 8);
    $isZip  = strncmp($sig, "PK\x03\x04", 4) === 0;
    $isOle  = strncmp($sig, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    $mimeOk = in_array($mime, $allowedMimes, true) || $isZip || $isOle;

    if (!in_array($ext, $allowedExts, true) || !$mimeOk) {
        return ['ok' => false, 'http' => 400, 'error' => 'invalid_format',
                'message' => 'Format invalide. Utilisez un fichier Excel (.xlsx ou .xls)'];
    }

    try {
        $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath)->getActiveSheet();
        $sheet    = $ws->toArray(null, true, true,  true); // valeurs formatées (A, B, C...)
        // Lecture BRUTE en parallèle : les dates restent des numéros de série Excel,
        // ce qui rend leur conversion indépendante du format d'affichage de la cellule
        // (un export au format US « mm/dd/yyyy » ne provoque plus d'inversion jour/mois).
        $sheetRaw = $ws->toArray(null, true, false, true);

        if (empty($sheet) || count($sheet) < 2) {
            return ['ok' => false, 'http' => 400, 'error' => 'empty_file',
                    'message' => 'Le fichier Excel semble vide'];
        }

        /* 1. Correspondances depuis la table `import` */
        $mapFields = [];
        $stmt = $pdo->query('SELECT fields_bdd, fields_excel FROM import');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mapFields[regcore_normaliseLabel($row['fields_excel'])] = $row['fields_bdd'];
        }

        /* 2. Mapping des entêtes du fichier */
        $headerMap = [];
        foreach ($sheet[1] as $col => $label) {
            if (!$label) continue;
            $headerMap[regcore_normaliseLabel($label)] = $col;
        }

        /* 3. Vérification des colonnes requises ('pays', 'Montant dû', 'Prestations' optionnelles) */
        $optionalLabels = [regcore_normaliseLabel('pays')];
        $montantLabel   = array_search('montant_du', $mapFields, true);
        if ($montantLabel !== false) {
            $optionalLabels[] = $montantLabel; // déjà normalisé
        }
        $prestationLabel = array_search('prestation', $mapFields, true);
        if ($prestationLabel !== false) {
            $optionalLabels[] = $prestationLabel;
        }
        $required = array_diff(array_keys($mapFields), $optionalLabels);
        $missing  = array_diff($required, array_keys($headerMap));
        if ($missing) {
            regcore_logImportError([
                'type' => 'colonnes manquantes',
                'missing' => array_values($missing),
            ]);
            return ['ok' => false, 'http' => 422, 'error' => 'missing_columns',
                    'message' => 'Colonnes manquantes dans le fichier Excel.',
                    'missing' => array_values($missing)];
        }

        /* 4. Tickets déjà existants */
        $existingTickets = $pdo->query('SELECT inscription_no FROM registrations')
                               ->fetchAll(PDO::FETCH_COLUMN, 0);

        /* Tarif inscription pour pré-remplissage du montant dû lors de l'import */
        $registrationFee = (float) ($pdo->query('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);

        /* 5. Requête d'insertion */
        // created_at (date d'AJOUT) n'est volontairement PAS listé → DEFAULT = NOW()
        // (instant de l'import). La date du fichier AssoConnect alimente date_inscription
        // (date réelle d'inscription, qui pilote le classement QR).
        //
        // Lot 1 : `edition_id` et `email_normalise` (empreinte HMAC de l'adresse)
        // sont ajoutés à la volée, uniquement si la migration est passée — l'import
        // doit continuer à fonctionner sur une base pas encore migrée.
        $importCols = ['inscription_no', 'nom', 'prenom', 'tel', 'email', 'naissance', 'sexe',
                       'tshirt_size', 'ville', 'entreprise', 'origine', 'paiement_mode',
                       'prestation', 'montant_du', 'date_inscription', 'created_by'];
        $importEditionId    = fer_activeEditionId($pdo);
        $importHasEmailNorm = fer_hasColumn($pdo, 'registrations', 'email_normalise');
        $importHasNaissance = fer_hasColumn($pdo, 'registrations', 'annee_naissance');
        if ($importEditionId !== null)  $importCols[] = 'edition_id';
        if ($importHasEmailNorm)        $importCols[] = 'email_normalise';
        if ($importHasNaissance)        array_push($importCols, 'annee_naissance', 'date_naissance', 'naissance_source');

        $insert = $pdo->prepare(
            'INSERT INTO registrations (' . implode(', ', $importCols) . ') VALUES ('
            . implode(',', array_fill(0, count($importCols), '?')) . ')'
        );

        /* Colonne « Prestations » (catégorie AssoConnect), lue par en-tête. */
        $prestaCol = $headerMap[regcore_normaliseLabel('Prestations')] ?? null;

        /* 6. Parsing des lignes */
        $parsedRows = [];
        $skipped = 0;
        $duplicates = [];
        $errors = [];

        foreach ($sheet as $idx => $row) {
            if ($idx === 1) continue;

            $values = [];
            foreach ($mapFields as $excelLabel => $bddField) {
                $col   = $headerMap[$excelLabel] ?? null;
                $value = $col ? trim((string) $row[$col]) : null;

                if ($bddField === 'inscription_no') {
                    $value = 'E' . trim((string) $value);
                } elseif ($bddField === 'naissance') {
                    // On ne stocke QUE l'âge. Une vraie date de naissance (« 09/05/2000 »),
                    // une année ou un âge sont tous convertis en âge. Une cellule AFFICHÉE
                    // comme une date (contient « / » ou « - ») est lue en BRUT (série Excel)
                    // → « Y-m-d » non ambigu avant d'en déduire l'âge (évite l'inversion
                    // jour/mois d'un export US). Un simple nombre (âge ou année) est converti
                    // directement.
                    $rawBirth  = ($col !== null) ? ($sheetRaw[$idx][$col] ?? null) : null;
                    $looksDate = (is_string($value) && preg_match('#[/\-]#', $value));
                    if ($looksDate && is_numeric($rawBirth)) {
                        $ymd   = substr((string) regcore_convertExcelDate($rawBirth), 0, 10);
                        $value = regcore_naissanceToAge($ymd);
                    } else {
                        $value = regcore_naissanceToAge((string) $value);
                    }
                } elseif ($bddField === 'date_inscription') {
                    // Valeur BRUTE (série Excel) plutôt que la valeur formatée :
                    // évite toute ambiguïté de format (US mm/dd vs EU dd/mm).
                    $rawDate = ($col !== null) ? ($sheetRaw[$idx][$col] ?? null) : null;
                    $value = regcore_convertExcelDate($rawDate);
                } elseif ($bddField === 'sexe') {
                    $value = regcore_normaliseSexe($value);
                } elseif ($bddField === 'montant_du') {
                    // Accepte "15", "15.50", "15,50", "15 €". 0 si vide / illisible.
                    $clean = preg_replace('/[^0-9.,\-]/', '', (string) $value);
                    $clean = str_replace(',', '.', $clean);
                    $value = is_numeric($clean) ? (float) $clean : null;
                }
                $values[$bddField] = ($bddField === 'montant_du')
                    ? ($value === null ? null : (float) $value)
                    : ($value ?: null);
            }

            // Détection « Enfant -12 ans avec t-shirt » via la colonne Prestations.
            $prestaRaw = (string) ($values['prestation'] ?? '');
            if ($prestaRaw === '' && $prestaCol) {
                $prestaRaw = trim((string) ($row[$prestaCol] ?? ''));
            }
            $values['_enfant_tshirt'] = ($prestaRaw !== '' && str_contains(regcore_normaliseLabel($prestaRaw), 'tshirt'));

            if (empty($values['inscription_no']) || empty($values['nom']) || empty($values['prenom'])) {
                $skipped++;
                $errors[] = ['ligne' => $idx, 'erreur' => 'Données manquantes'];
                regcore_logImportError(['type' => 'ligne ignorée', 'ligne' => $idx,
                    'raison' => 'Données manquantes', 'valeurs' => $values]);
                continue;
            }
            if (in_array($values['inscription_no'], $existingTickets, true)) {
                $skipped++;
                $duplicates[] = ['ligne' => $idx, 'ticket' => $values['inscription_no']];
                regcore_logImportError(['type' => 'doublon', 'ligne' => $idx,
                    'ticket' => $values['inscription_no']]);
                continue;
            }
            $existingTickets[] = $values['inscription_no'];
            $parsedRows[] = ['ligne' => $idx, 'values' => $values];
        }

        /* 7. Tri chronologique par date d'inscription (du plus ancien au plus récent) */
        usort($parsedRows, function ($a, $b) {
            return strcmp($a['values']['date_inscription'] ?? '9999-12-31',
                          $b['values']['date_inscription'] ?? '9999-12-31');
        });

        /* 8. Insertion */
        $pdo->beginTransaction();
        $added = 0;
        $newRegistrants = [];
        try {
            foreach ($parsedRows as $parsed) {
                $v = $parsed['values'];
                // Montant : valeur du fichier si présente, sinon tarif standard.
                $montant = $v['montant_du'] ?? null;
                if ($montant === null) {
                    $montant = $registrationFee;
                }
                // Mode de paiement = moyen RÉEL du fichier (comme l'import manuel) ;
                // la catégorie (prestation) discrimine :
                //  - « avec t-shirt » → enfant_tshirt (payant), moyen réel conservé ;
                //  - montant ≤ 0 → vraiment gratuit (paiement_mode 'gratuit') ;
                //  - sinon tarif_unique (moyen réel conservé).
                $paiementMode = regcore_normalisePaiementMode($v['paiement_mode'] ?? null);
                if (!empty($v['_enfant_tshirt'])) {
                    $prestation = 'enfant_tshirt';
                } elseif ((float) $montant <= 0) {
                    $paiementMode = 'gratuit';
                    $prestation   = 'enfant_gratuit';
                } else {
                    $prestation = 'tarif_unique';
                }
                $insertVals = [
                    $v['inscription_no'], encrypt($v['nom']), encrypt($v['prenom']),
                    encrypt($v['tel']), encrypt($v['email']), encrypt($v['naissance']), $v['sexe'],
                    '-', encrypt($v['ville']), encrypt($v['entreprise']),
                    ($v['origine'] ?? null) ?: $defaultOrig,
                    $paiementMode, $prestation, (float) $montant, $v['date_inscription'], $createdBy,
                ];
                // Mêmes colonnes optionnelles, dans le même ordre que $importCols.
                if ($importEditionId !== null)  $insertVals[] = $importEditionId;
                if ($importHasEmailNorm)        $insertVals[] = fer_emailHash($v['email'] ?? null);
                if ($importHasNaissance) {
                    // $v['naissance'] contient déjà l'âge (converti au parsing du fichier) ;
                    // il est rapporté à l'année de l'édition cible, pas à l'année courante.
                    $nc = regcore_naissanceColumns($pdo, $v['naissance'] ?? '', $importEditionId);
                    array_push($insertVals, $nc['annee_naissance'] ?? null,
                               $nc['date_naissance'] ?? null, $nc['naissance_source'] ?? 'inconnu');
                }
                $insert->execute($insertVals);
                $added++;
                if (!empty($v['email'])) {
                    $newRegistrants[] = [
                        'email'          => $v['email'],
                        'nom'            => $v['nom'],
                        'prenom'         => $v['prenom'],
                        'inscription_no' => $v['inscription_no'],
                    ];
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        /* Streaming post-commit (dashboard) : événements identiques à l'historique. */
        $streamStarted = true;
        if ($emitFn) {
            $emitFn(['type' => 'import_ok', 'count' => $added]);
            if ($skipped > 0) {
                $emitFn(['type' => 'import_skip', 'count' => $skipped, 'duplicates' => count($duplicates)]);
            }
        }

        /* 9. Mails de confirmation (hors transaction). QR via réglage global. */
        $mailsSent  = 0;
        $mailErrors = 0;
        if ($sendMail && !empty($newRegistrants)) {
            require_once __DIR__ . '/../mail/googleMail.php';
            foreach ($newRegistrants as $reg) {
                try {
                    $hasQr = function_exists('shouldIncludeQrCode') ? shouldIncludeQrCode($reg['inscription_no']) : false;
                    sendMail(
                        $reg['email'],
                        'Inscription enregistrée - Forbach en Rose',
                        null, null,
                        $reg['nom'], $reg['prenom'],
                        'inscription', $reg['inscription_no']
                    );
                    $mailsSent++;
                    if ($emitFn) $emitFn(['type' => 'mail_sent', 'inscription_no' => $reg['inscription_no'], 'qrcode' => $hasQr]);
                } catch (\Throwable $mailErr) {
                    $mailErrors++;
                    error_log('[IMPORT][MAIL] ' . $reg['inscription_no'] . ' : ' . $mailErr->getMessage());
                    if ($emitFn) $emitFn(['type' => 'mail_error', 'inscription_no' => $reg['inscription_no'], 'error' => $mailErr->getMessage()]);
                }
            }
        } elseif (!$sendMail && $emitFn) {
            $emitFn(['type' => 'mail_skip']);
        }

        if ($emitFn) {
            $emitFn([
                'type'         => 'done',
                'rows_added'   => $added,
                'rows_skipped' => $skipped,
                'mails_sent'   => $mailsSent,
                'duplicates'   => count($duplicates),
            ]);
        }

        return [
            'ok'                => true,
            'rows'              => $added,
            'added'             => $added,
            'skipped'           => $skipped,
            'duplicates'        => count($duplicates),
            'mails_sent'        => $mailsSent,
            'mail_errors'       => $mailErrors,
            'errors'            => array_slice($errors, 0, 50),
            'detail_duplicates' => array_slice($duplicates, 0, 50),
            'message'           => "Import terminé : {$added} inscrit(s) ajouté(s).",
        ];

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        regcore_logImportError(['type' => 'exception', 'message' => $e->getMessage()]);
        error_log('[IMPORT] ' . $e->getMessage());
        // Si le streaming avait déjà commencé, on signale l'erreur dans le flux ;
        // sinon le caller renverra une réponse JSON d'erreur.
        if ($emitFn && $streamStarted) {
            $emitFn(['type' => 'error', 'message' => "Erreur lors de l'import."]);
        }
        return ['ok' => false, 'http' => 500, 'error' => 'import_error',
                'message' => 'Erreur lors de la lecture du fichier Excel.'];
    }
}

/**
 * Wrapper de compatibilité pour l'API externe (api.php). Délègue à la fonction
 * canonique importInscritsExcel(). Conserve la signature historique.
 */
function regcore_importExcel(PDO $pdo, string $tmpFile, string $originalName, bool $sendMails = false): array
{
    return importInscritsExcel($pdo, $tmpFile, $originalName, [
        'send_mail'  => $sendMails,
        'created_by' => null,
        'origine'    => 'AssoConnect',
    ]);
}

/* ──────────────── Réparation des dates « date d'ajout » (created_at) ──────────────── */

/**
 * Recorrige les `created_at` corrompus par l'ancien bug d'inversion jour/mois
 * (date « Date de création » relue au format US mm/dd puis interprétée en d/m/Y).
 *
 * La VRAIE date est relue depuis le fichier source via la valeur BRUTE (numéro de
 * série Excel) — non ambiguë, indépendante du format d'affichage. Seules les
 * inscriptions dont la DATE (jour/mois/année) diffère réellement sont corrigées :
 * l'opération est donc idempotente (relancer ne change plus rien).
 *
 * @param PDO    $pdo
 * @param string $tmpFile      Chemin du fichier temporaire uploadé.
 * @param string $originalName Nom d'origine (validation d'extension).
 * @param bool   $apply        false = aperçu (dry-run, n'écrit rien) ; true = applique.
 * @return array  ['ok'=>bool, 'source_count'=>int, 'fixes'=>[...],
 *                 'future_unmatched'=>[...], 'applied'=>int, 'dry_run'=>bool, ...]
 */
function regcore_repairCreatedAtDates(PDO $pdo, string $tmpFile, string $originalName, bool $apply = false): array
{
    /* Validation extension + signature (mêmes garde-fous que l'import) */
    $ext   = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $sig   = (string) @file_get_contents($tmpFile, false, null, 0, 8);
    $isZip = strncmp($sig, "PK\x03\x04", 4) === 0;
    $isOle = strncmp($sig, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    if (!in_array($ext, ['xlsx', 'xls'], true) || !($isZip || $isOle)) {
        return ['ok' => false, 'error' => 'invalid_format',
                'message' => 'Format invalide. Utilisez un fichier Excel (.xlsx ou .xls).'];
    }

    try {
        /* 1. Mapping colonnes via la table `import` (mêmes libellés que l'import) */
        $mapFields = [];
        foreach ($pdo->query('SELECT fields_bdd, fields_excel FROM import') as $row) {
            $mapFields[regcore_normaliseLabel($row['fields_excel'])] = $row['fields_bdd'];
        }
        $labelBillet  = array_search('inscription_no', $mapFields, true) ?: regcore_normaliseLabel('Numéro billet');
        // L'alias d'import pointe désormais sur 'date_inscription' (la colonne « date de
        // création » AssoConnect = date réelle d'inscription). Repli sur le libellé connu.
        $labelCreated = array_search('date_inscription', $mapFields, true)
                     ?: (array_search('created_at', $mapFields, true) ?: regcore_normaliseLabel('Date de création'));

        /* 2. Lecture BRUTE du fichier (formatData=false → dates = séries Excel) */
        $ws   = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile)->getActiveSheet();
        $rows = $ws->toArray(null, true, false, true);
        if (count($rows) < 2) {
            return ['ok' => false, 'error' => 'empty_file', 'message' => 'Le fichier Excel semble vide.'];
        }

        /* 3. Repérage des colonnes par en-tête */
        $colBillet = $colCreated = null;
        foreach ($rows[1] as $col => $label) {
            if ($label === null || $label === '') continue;
            $norm = regcore_normaliseLabel((string) $label);
            if ($norm === $labelBillet)  $colBillet  = $col;
            if ($norm === $labelCreated) $colCreated = $col;
        }
        if ($colBillet === null || $colCreated === null) {
            return ['ok' => false, 'error' => 'missing_columns',
                    'message' => 'Colonnes « Numéro billet » et/ou « Date de création » introuvables dans le fichier.'];
        }

        /* 4. Timestamp correct par numéro d'inscription.
         *    On ne fait CONFIANCE qu'aux dates fournies en numéro de série Excel
         *    (non ambiguës). Une cellule vide ou une date en TEXTE (format US
         *    possible → ambiguë) est ignorée : la réparation ne pourra JAMAIS
         *    écraser une date correcte par une valeur fausse ou par « aujourd'hui ».
         *    On conserve l'heure réelle (pas de troncature à minuit). */
        $correctByNo = [];
        foreach ($rows as $idx => $r) {
            if ($idx === 1) continue;
            $billet = trim((string) ($r[$colBillet] ?? ''));
            if ($billet === '') continue;
            $raw = $r[$colCreated] ?? null;
            if (!is_numeric($raw)) continue;                  // ni vide ni texte ambigu
            $correctByNo['E' . $billet] = regcore_convertExcelDate($raw); // 'Y-m-d H:i:s'
        }

        /* 5. Comparaison avec la base. On décide sur la DATE seule (idempotent),
         *    mais on écrit le timestamp complet (heure réelle préservée).
         *    $today est lu côté connexion (CURDATE()) pour rester cohérent avec le
         *    fuseau de created_at (la connexion est en +02:00, cf. config.php). */
        $dbRows = $pdo->query('SELECT id, inscription_no, created_at FROM registrations')
                      ->fetchAll(PDO::FETCH_ASSOC);
        $today           = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
        $fixes           = [];
        $futureUnmatched = [];
        foreach ($dbRows as $r) {
            $no  = $r['inscription_no'];
            $cur = substr((string) $r['created_at'], 0, 10);
            if (isset($correctByNo[$no])) {
                $correctDate = substr($correctByNo[$no], 0, 10);
                if ($correctDate !== $cur) {
                    $fixes[] = [
                        'id'  => $r['id'],
                        'no'  => $no,
                        'old' => $r['created_at'],
                        'new' => $correctByNo[$no],   // timestamp complet (heure réelle)
                    ];
                }
            } elseif ($cur > $today) {
                // Date dans le futur mais inscription absente du fichier fourni :
                // non réparable automatiquement, on la signale.
                $futureUnmatched[] = ['no' => $no, 'created_at' => $r['created_at']];
            }
        }

        /* 6. Application éventuelle (transaction).
         *    On corrige les DEUX colonnes avec la même date corrigée :
         *    - created_at      (date d'ajout — corrompue par l'ancien bug d'inversion)
         *    - date_inscription (backfillée depuis created_at, donc même corruption)
         *    → garantit qu'aucune incohérence ne subsiste entre les deux après réparation. */
        $applied = 0;
        if ($apply && $fixes) {
            $upd = $pdo->prepare('UPDATE registrations SET created_at = ?, date_inscription = ? WHERE id = ?');
            $pdo->beginTransaction();
            try {
                foreach ($fixes as $f) {
                    $upd->execute([$f['new'], $f['new'], $f['id']]);
                    $applied++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[REGCORE][REPAIR] ' . $e->getMessage());
                return ['ok' => false, 'error' => 'db_error',
                        'message' => 'Erreur lors de l\'écriture en base (transaction annulée).'];
            }
        }

        return [
            'ok'               => true,
            'source_count'     => count($correctByNo),
            'fixes'            => $fixes,
            'future_unmatched' => $futureUnmatched,
            'applied'          => $applied,
            'dry_run'          => !$apply,
        ];

    } catch (\Throwable $e) {
        error_log('[REGCORE][REPAIR] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'exception',
                'message' => 'Erreur lors de la lecture du fichier Excel.'];
    }
}
