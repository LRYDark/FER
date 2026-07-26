<?php
/**
 * registrations_resolver.php — Aiguillage vers la bonne table d'inscriptions.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POURQUOI CE FICHIER EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 * Le site archive chaque année : la route `archive-current` de admin-api.php
 * crée `registrations_<année>`, y recopie les lignes, puis vide `registrations`.
 * Les `id` techniques changent donc de table tous les ans — une clé étrangère
 * vers `registrations.id` casserait à chaque archivage.
 *
 * Les tables du lot 1 désignent donc un coureur par sa CLÉ MÉTIER, le couple
 * `(annee, inscription_no)` : « l'inscrit n°142 de l'édition 2026 » désigne la
 * même personne, que sa ligne vive dans `registrations` ou dans
 * `registrations_2026`. Ce fichier est la seule couche qui sait dans quelle
 * table aller chercher.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RÈGLES
 * ─────────────────────────────────────────────────────────────────────────────
 *  • LECTURE SEULE sur les tables d'archive. Toute écriture d'inscription se
 *    fait exclusivement dans `registrations` (édition en cours).
 *  • Jamais de `SELECT *` sur une archive : elles ont été créées par
 *    `CREATE TABLE … LIKE registrations` à des dates différentes, leurs colonnes
 *    ne sont pas identiques (la route d'archivage rajoute d'ailleurs déjà à la
 *    main `prestation` et `date_inscription`). On se limite au noyau de colonnes
 *    et on renvoie null pour celles qui manquent.
 *  • Le nom de table n'est JAMAIS concaténé depuis une entrée utilisateur : il
 *    provient d'INFORMATION_SCHEMA et est revalidé par expression régulière
 *    avant toute interpolation — un nom de table ne peut pas être un paramètre
 *    lié en SQL.
 *
 * Toutes les fonctions sont préfixées `regres_`.
 */

require_once __DIR__ . '/config.php';   // $pdo, decrypt(), decryptRow()

/**
 * Noyau de colonnes présent dans toutes les tables d'inscriptions, y compris
 * les archives les plus anciennes. Toute colonne hors de cette liste doit être
 * testée avant usage (cf. regres_tableColumns).
 */
const REGRES_CORE_COLUMNS = [
    'id', 'inscription_no', 'nom', 'prenom', 'email',
    'naissance', 'sexe', 'ville', 'group_id',
];

/** Colonnes utiles mais absentes de certaines archives — lues si présentes. */
const REGRES_EXTRA_COLUMNS = [
    'tel', 'entreprise', 'tshirt_size', 'origine', 'paiement_mode',
    'prestation', 'montant_du', 'created_at', 'date_inscription', 'created_by',
];

/** Un nom de table d'inscriptions est-il syntaxiquement légitime ? */
function regres_isValidTable(string $table): bool
{
    return $table === 'registrations' || (bool) preg_match('/^registrations_\d{4}$/', $table);
}

/**
 * Année de l'édition active (`editions.is_active = 1`).
 * Repli sur l'édition la plus récente, puis sur l'année courante : cette valeur
 * sert de clé métier à toute écriture, elle ne doit jamais être vide.
 */
function regres_activeYear(PDO $pdo, bool $refresh = false): int
{
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    $cache = (int) date('Y');
    try {
        $v = $pdo->query('SELECT annee FROM editions ORDER BY is_active DESC, annee DESC, id DESC LIMIT 1')
                 ->fetchColumn();
        if ($v !== false && (int) $v > 1900) $cache = (int) $v;
    } catch (\Throwable $e) {
        // Table `editions` absente (migration pas encore jouée) : année courante.
    }
    return $cache;
}

/**
 * Recense les tables d'inscriptions disponibles, la plus récente en premier.
 *
 * @return array<int, array{annee:int, table:string, active:bool}>
 */
function regres_listTables(PDO $pdo, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    $anneeActive = regres_activeYear($pdo, $refresh);
    $out = [['annee' => $anneeActive, 'table' => 'registrations', 'active' => true]];

    try {
        $rows = $pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '^registrations_[0-9]{4}$'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($rows as $t) {
            if (!regres_isValidTable($t)) continue;             // ceinture et bretelles
            $annee = (int) substr($t, -4);
            if ($annee < 1900 || $annee > 2200) continue;
            if ($annee === $anneeActive) continue;              // déjà couverte par `registrations`
            $out[] = ['annee' => $annee, 'table' => $t, 'active' => false];
        }
    } catch (\Throwable $e) {
        // INFORMATION_SCHEMA inaccessible : on se contente de l'édition en cours.
    }

    usort($out, fn($a, $b) => $b['annee'] <=> $a['annee']);
    return $cache = $out;
}

/** Nom de la table portant une année donnée, ou null si cette année n'existe pas. */
function regres_tableForYear(PDO $pdo, int $annee): ?string
{
    foreach (regres_listTables($pdo) as $t) {
        if ($t['annee'] === $annee) return $t['table'];
    }
    return null;
}

/**
 * Colonnes réellement présentes dans une table (mises en cache).
 * @return string[]
 */
function regres_tableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    if (!regres_isValidTable($table)) return $cache[$table] = [];

    try {
        $st = $pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $st->execute([$table]);
        return $cache[$table] = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return $cache[$table] = [];
    }
}

/**
 * Liste de colonnes à sélectionner dans une table donnée : noyau + extras, mais
 * uniquement celles qui existent réellement. Les absentes sont renvoyées à NULL
 * par regres_normalizeRow() plutôt que de faire échouer la requête.
 * @return string[]
 */
function regres_selectableColumns(PDO $pdo, string $table): array
{
    $presentes = regres_tableColumns($pdo, $table);
    $voulues   = array_merge(REGRES_CORE_COLUMNS, REGRES_EXTRA_COLUMNS);
    return array_values(array_intersect($voulues, $presentes));
}

/**
 * Complète une ligne avec les colonnes manquantes (à null), déchiffre les
 * données personnelles et ajoute la clé métier.
 */
function regres_normalizeRow(array $row, int $annee, string $table): array
{
    foreach (array_merge(REGRES_CORE_COLUMNS, REGRES_EXTRA_COLUMNS) as $c) {
        if (!array_key_exists($c, $row)) $row[$c] = null;
    }
    $row = decryptRow($row);           // nom, prenom, email, naissance, ville, tel, entreprise
    $row['annee']  = $annee;           // clé métier — c'est elle qui identifie, pas `id`
    $row['_table'] = $table;           // provenance, utile au diagnostic
    return $row;
}

/**
 * Résout une inscription par sa clé métier.
 * @return array|null la ligne déchiffrée et normalisée, ou null si introuvable
 */
function regres_find(PDO $pdo, int $annee, string $inscriptionNo): ?array
{
    $table = regres_tableForYear($pdo, $annee);
    if ($table === null) return null;

    $cols = regres_selectableColumns($pdo, $table);
    if (!in_array('inscription_no', $cols, true)) return null;

    // $table vient d'INFORMATION_SCHEMA et est revalidé par regres_isValidTable :
    // un nom de table ne peut pas être un paramètre lié. La valeur cherchée, elle,
    // est bien passée en paramètre.
    $sql = 'SELECT `' . implode('`,`', $cols) . '` FROM `' . $table . '` WHERE inscription_no = ? LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$inscriptionNo]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[REGRES] find ' . $table . ' : ' . $e->getMessage());
        return null;
    }
    return $row ? regres_normalizeRow($row, $annee, $table) : null;
}

/**
 * Toutes les inscriptions d'une adresse email, toutes éditions confondues.
 *
 * ⚠️ LA COMPARAISON SE FAIT EN PHP, PAS EN SQL. `registrations.email` est
 * chiffré (AES-256-GCM avec vecteur d'initialisation aléatoire) : un
 * `WHERE LOWER(TRIM(email)) = :email` comparerait le paramètre à du chiffré et
 * ne renverrait jamais rien. On déchiffre donc ligne par ligne, comme le font
 * déjà public/accueil.php et src/content/chatbot-engine.php.
 *
 * Le coût reste négligeable : l'archivage annuel garde chaque table à une seule
 * année, soit quelques centaines de lignes. Ce qui posait problème pour les clés
 * étrangères rend ici service.
 *
 * @param  string $emailNormalise adresse déjà en minuscules et sans espaces
 * @return array<int, array> lignes normalisées, éditions récentes en premier
 */
function regres_findByEmail(PDO $pdo, string $emailNormalise): array
{
    $cible = regres_normalizeEmailValue($emailNormalise);
    if ($cible === '') return [];

    $out = [];
    foreach (regres_listTables($pdo) as $t) {
        $cols = regres_selectableColumns($pdo, $t['table']);
        if (!in_array('email', $cols, true) || !in_array('inscription_no', $cols, true)) {
            continue;   // archive trop ancienne pour être exploitable
        }
        $sql = 'SELECT `' . implode('`,`', $cols) . '` FROM `' . $t['table'] . '`';
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[REGRES] findByEmail ' . $t['table'] . ' : ' . $e->getMessage());
            continue;
        }
        foreach ($rows as $row) {
            if (regres_normalizeEmailValue((string) decrypt($row['email'])) !== $cible) continue;
            if (trim((string) $row['inscription_no']) === '') continue;   // non revendicable
            $out[] = regres_normalizeRow($row, $t['annee'], $t['table']);
        }
    }
    return $out;
}

/** Forme canonique d'une adresse : minuscules + espaces retirés. */
function regres_normalizeEmailValue(?string $email): string
{
    return mb_strtolower(trim((string) $email), 'UTF-8');
}

/**
 * Âge d'un inscrit pour l'édition à laquelle il appartient.
 *
 * S'appuie sur regcore_ageFromNaissance(), qui porte déjà les conventions de
 * saisie réellement rencontrées sur ce site (âge stocké, année seule, date
 * complète) et sait qu'une archive 2023 se calcule sur 2023 et non sur l'année
 * courante. Aucune migration de la colonne `naissance` n'est nécessaire : le
 * calcul se fait à la lecture.
 *
 * @param array $row ligne issue de regres_find() / regres_findByEmail()
 */
function regres_age(array $row): ?int
{
    require_once __DIR__ . '/../content/registrations_core.php';
    return regcore_ageFromNaissance(
        $row['naissance'] === null ? null : (string) $row['naissance'],
        isset($row['annee']) ? (int) $row['annee'] : null
    );
}

/**
 * Rapport de dérive de schéma entre les tables d'inscriptions.
 * Utilisé par update.php?tool=check-integrity.
 *
 * @return array<int, array{table:string, annee:int, manquantes:string[]}>
 */
function regres_schemaDrift(PDO $pdo): array
{
    $ref = array_merge(REGRES_CORE_COLUMNS, REGRES_EXTRA_COLUMNS);
    $out = [];
    foreach (regres_listTables($pdo) as $t) {
        $presentes  = regres_tableColumns($pdo, $t['table']);
        $manquantes = array_values(array_diff($ref, $presentes));
        $out[] = ['table' => $t['table'], 'annee' => $t['annee'], 'manquantes' => $manquantes];
    }
    return $out;
}
