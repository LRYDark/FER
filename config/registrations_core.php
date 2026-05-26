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
 * `import-excel` et `registrations` de config/api.php. Si cette logique
 * évolue côté dashboard, penser à répercuter la modification ici.
 *
 * Toutes les fonctions sont préfixées `regcore_` pour éviter toute collision.
 */

require_once __DIR__ . '/config.php';          // $pdo, encrypt(), decrypt(), decryptRows()
require_once __DIR__ . '/../vendor/autoload.php';

/* ───────────────────────── Helpers utilitaires ───────────────────────── */

/**
 * Normalise un libellé de colonne Excel (accents → ASCII, minuscules, espaces).
 * Copie conforme de normaliseLabel() de config/api.php.
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
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($value));
    }
    $formats = ['d/m/Y H:i:s', 'd/m/Y', 'Y-m-d'];
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, (string) $value);
        if ($dt) return $dt->format('Y-m-d H:i:s');
    }
    return date('Y-m-d H:i:s');
}

/** Journalise une erreur d'import dans config/logs/import_errors.log. */
function regcore_logImportError(array $data, string $filename = 'import_errors.log'): void
{
    $safePath = __DIR__ . '/logs/' . basename($filename);
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
    $d['sexe']          = in_array($d['sexe'] ?? '', $allowedSexe, true) ? $d['sexe'] : 'H';
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
        $cols[] = 'paiement_mode'; $phs[] = '?'; $vals[] = $d['paiement_mode'] ?? null;
        $cols[] = 'montant_du';    $phs[] = '?'; $vals[] = $montantDu;
        $cols[] = 'created_by';    $phs[] = '?'; $vals[] = null; // créé via API : aucun utilisateur

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
            require_once __DIR__ . '/googleMail.php'; // charge le $data global (réglages complets)
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
 * Importe un fichier Excel d'inscrits, exactement comme l'import du dashboard :
 * mapping des colonnes via la table `import`, détection des doublons, tri
 * chronologique, chiffrement des données personnelles, puis envoi optionnel
 * des mails de confirmation (avec QR Code selon la configuration).
 *
 * @param PDO    $pdo
 * @param string $tmpFile      Chemin du fichier temporaire uploadé.
 * @param string $originalName Nom d'origine du fichier (pour valider l'extension).
 * @param bool   $sendMails    Envoyer les mails de confirmation aux nouveaux inscrits.
 * @return array  Résultat structuré (clé 'ok' à true/false).
 */
function regcore_importExcel(PDO $pdo, string $tmpFile, string $originalName, bool $sendMails = false): array
{
    /* Validation extension + type MIME (identique au dashboard) */
    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = @mime_content_type($tmpFile) ?: '';
    $allowedExts  = ['xlsx', 'xls'];
    $allowedMimes = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',
        'text/xml',
        'application/xml',
        'text/html',
    ];
    $sig      = (string) @file_get_contents($tmpFile, false, null, 0, 8);
    $isZip    = strncmp($sig, "PK\x03\x04", 4) === 0;
    $isOle    = strncmp($sig, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    $mimeOk   = in_array($mime, $allowedMimes, true) || $isZip || $isOle;

    if (!in_array($ext, $allowedExts, true) || !$mimeOk) {
        return ['ok' => false, 'error' => 'invalid_format',
                'message' => 'Format invalide. Utilisez un fichier Excel (.xlsx ou .xls).'];
    }

    try {
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile)
                     ->getActiveSheet()
                     ->toArray(null, true, true, true); // colonnes A, B, C...

        if (empty($sheet) || count($sheet) < 2) {
            return ['ok' => false, 'error' => 'empty_file',
                    'message' => 'Le fichier Excel semble vide.'];
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

        /* 3. Vérification des colonnes requises ('pays' et 'Montant dû' optionnelles) */
        $optionalLabels = [regcore_normaliseLabel('pays')];
        $montantLabel   = array_search('montant_du', $mapFields, true);
        if ($montantLabel !== false) {
            $optionalLabels[] = $montantLabel; // déjà normalisé
        }
        $required = array_diff(array_keys($mapFields), $optionalLabels);
        $missing  = array_diff($required, array_keys($headerMap));
        if ($missing) {
            regcore_logImportError([
                'type' => 'colonnes manquantes',
                'missing' => array_values($missing),
                'source' => 'API',
            ]);
            return ['ok' => false, 'error' => 'missing_columns',
                    'message' => 'Colonnes manquantes dans le fichier Excel.',
                    'missing' => array_values($missing)];
        }

        /* 4. Tickets déjà existants */
        $existingTickets = $pdo->query('SELECT inscription_no FROM registrations')
                               ->fetchAll(PDO::FETCH_COLUMN, 0);

        /* Tarif inscription pour pré-remplissage du montant dû lors de l'import */
        $registrationFee = (float) ($pdo->query('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);

        /* 5. Requête d'insertion */
        $insert = $pdo->prepare(
            'INSERT INTO registrations
             (inscription_no, nom, prenom, tel, email, naissance, sexe,
              tshirt_size, ville, entreprise, origine, paiement_mode,
              montant_du, created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

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
                    $value = (is_numeric($value) && $value >= 1900 && $value <= date('Y')) ? $value : null;
                } elseif ($bddField === 'created_at') {
                    $value = regcore_convertExcelDate($value);
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

            if (empty($values['inscription_no']) || empty($values['nom']) || empty($values['prenom'])) {
                $skipped++;
                $errors[] = ['ligne' => $idx, 'erreur' => 'Données manquantes'];
                continue;
            }
            if (in_array($values['inscription_no'], $existingTickets, true)) {
                $skipped++;
                $duplicates[] = ['ligne' => $idx, 'ticket' => $values['inscription_no']];
                continue;
            }
            $existingTickets[] = $values['inscription_no'];
            $parsedRows[] = ['ligne' => $idx, 'values' => $values];
        }

        /* 7. Tri chronologique (du plus ancien au plus récent) */
        usort($parsedRows, function ($a, $b) {
            return strcmp($a['values']['created_at'] ?? '9999-12-31',
                          $b['values']['created_at'] ?? '9999-12-31');
        });

        /* 8. Insertion */
        $pdo->beginTransaction();
        $added = 0;
        $newRegistrants = [];
        try {
            foreach ($parsedRows as $parsed) {
                $v = $parsed['values'];
                // Si la colonne « Montant dû » est présente dans l'Excel on l'utilise,
                // sinon on pré-remplit avec le tarif standard (existants → considérés payés).
                $montant = $v['montant_du'] ?? null;
                if ($montant === null) {
                    $montant = $registrationFee;
                }
                $insert->execute([
                    $v['inscription_no'], encrypt($v['nom']), encrypt($v['prenom']),
                    encrypt($v['tel']), encrypt($v['email']), encrypt($v['naissance']), $v['sexe'],
                    '-', encrypt($v['ville']), encrypt($v['entreprise']),
                    ($v['origine'] ?? null) ?: 'AssoConnect',
                    'en ligne (CB)', (float) $montant, $v['created_at'], null,
                ]);
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

        /* 9. Mails de confirmation (hors transaction) */
        $mailsSent = 0;
        $mailErrors = 0;
        if ($sendMails && !empty($newRegistrants)) {
            require_once __DIR__ . '/googleMail.php';
            foreach ($newRegistrants as $reg) {
                try {
                    sendMail(
                        $reg['email'],
                        'Inscription enregistrée - Forbach en Rose',
                        null, null,
                        $reg['nom'], $reg['prenom'],
                        'inscription', $reg['inscription_no']
                    );
                    $mailsSent++;
                } catch (\Throwable $mailErr) {
                    $mailErrors++;
                    error_log('[REGCORE][IMPORT][MAIL] ' . $reg['inscription_no'] . ' : ' . $mailErr->getMessage());
                }
            }
        }

        return [
            'ok'           => true,
            'added'        => $added,
            'skipped'      => $skipped,
            'duplicates'   => count($duplicates),
            'mails_sent'   => $mailsSent,
            'mail_errors'  => $mailErrors,
            'errors'       => array_slice($errors, 0, 50),
            'detail_duplicates' => array_slice($duplicates, 0, 50),
        ];

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        regcore_logImportError(['type' => 'exception', 'source' => 'API', 'message' => $e->getMessage()]);
        error_log('[REGCORE][IMPORT] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'import_error',
                'message' => 'Erreur lors de la lecture du fichier Excel.'];
    }
}
