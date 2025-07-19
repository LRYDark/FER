<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

$route = $_GET['route'] ?? '';

/* ───── LOGIN / LOGOUT ───────────────────────── */
if ($route==='login' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $st=$pdo->prepare('SELECT id,password_hash,role FROM users WHERE username=?');
    $st->execute([$d['username']]); $u=$st->fetch();
    if($u && password_verify($d['password'],$u['password_hash'])){
        $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role'];
        echo json_encode(['ok'=>true, 'role'=>$u['role']]); exit;  // ← Ajout du role
    }
    http_response_code(401); echo json_encode(['ok'=>false]); exit;
}
if ($route==='logout'){ session_destroy(); echo json_encode(['ok'=>true]); exit; }

/* ───── USERS (admin) ────────────────────────── */
if ($route === 'users') {
    requireRole(['admin']);

    // 🔁 POST : suppression d’un compte
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        $id = $_POST['id'] ?? null;
        $force = $_POST['force'] ?? false;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        // Vérifier si des inscriptions sont liées à ce compte
        $count = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE created_by = ?');
        $count->execute([$id]);
        $nb = $count->fetchColumn();

        if ($nb > 0 && !$force) {
            echo json_encode([
                'ok' => false,
                'warning' => "⚠️ Ce compte est lié à $nb inscription(s). Supprimer ce compte entraînera aussi la suppression des inscriptions associées.",
                'requiresForce' => true
            ]);
            exit;
        }

        try {
            $pdo->beginTransaction();
            if ($nb > 0) {
                $pdo->prepare('DELETE FROM registrations WHERE created_by = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("❌ Erreur SQL : " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
        }
        exit;
    }


    // ✅ POST : création d’un compte
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare(
            'INSERT INTO users(username,password_hash,role,organisation)
             VALUES(?,?,?,?)'
        );
        $stmt->execute([
            $d['username'],
            password_hash($d['password'], PASSWORD_DEFAULT),
            $d['role'],
            $d['organisation'] ?: null
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // GET : liste
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(
            $pdo->query('SELECT id,username,role,organisation,created_at FROM users')->fetchAll()
        );
        exit;
    }

    // PUT : modification
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        parse_str(file_get_contents('php://input'), $d);
        if (!isset($d['id']) || !$d['id']) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        $allowed = ['username', 'password', 'role', 'organisation'];
        $fields = [];
        $params = [];

        foreach ($allowed as $key) {
            if (isset($d[$key])) {
                if ($key === 'password') {
                    $fields[] = "password_hash = :password_hash";
                    $params['password_hash'] = password_hash($d['password'], PASSWORD_DEFAULT);
                } else {
                    $fields[] = "$key = :$key";
                    $params[$key] = $d[$key];
                }
            }
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'aucune donnée à modifier']);
            exit;
        }

        $params['id'] = $d['id'];
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }
}

/* ───── REGISTRATIONS ────────────────────────── */
if ($route==='registrations'){
    /* GET : tous rôles */
    if($_SERVER['REQUEST_METHOD']==='GET'){
        requireRole(['admin','user','viewer','saisie']);
        echo json_encode(
          $pdo->query('SELECT * FROM registrations ORDER BY inscription_no DESC')->fetchAll()
        ); exit;
    }

    /* POST : public OU user/admin */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $d = json_decode(file_get_contents('php://input'), true);

        /* numéro d’inscription suivant */
        $pdo->beginTransaction();
        $last = $pdo->query('SELECT MAX(inscription_no) FROM registrations')->fetchColumn() ?: 0;
        $no   = $last + 1;

        /* origine : orga de l’utilisateur connecté (si existe), sinon valeur front, sinon "en ligne"  */
        $myOrg = null;
        if (currentUserId()){
            $s=$pdo->prepare('SELECT organisation FROM users WHERE id=?');
            $s->execute([currentUserId()]);
            $myOrg=$s->fetchColumn() ?: null;
        }
        $origine = $myOrg ?: ($d['origine'] ?? 'en ligne');

        $st=$pdo->prepare('INSERT INTO registrations
          (inscription_no,nom,prenom,tel,email,naissance,sexe,tshirt_size,
           ville,entreprise,origine,paiement_mode,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
          $no,$d['nom'],$d['prenom'],$d['tel'],$d['email'],
          $d['naissance'] ?: null,
          $d['sexe'] ?? 'H',
          $d['tshirt_size'] ?? '',
          $d['ville'],$d['entreprise'],
          $origine,
          $d['paiement_mode'],
          currentUserId()
        ]);
        $pdo->commit();
        echo json_encode(['ok'=>true,'inscription_no'=>$no]); exit;
    }

    /* DELETE (admin) */
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        requireRole(['admin']);
        parse_str(file_get_contents('php://input'), $d);    // ← on lit ici, uniquement pour DELETE
        $pdo->prepare('DELETE FROM registrations WHERE id=?')->execute([$d['id']]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ---------- PUT (mise à jour) ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        requireRole(['admin']);

        /* 1. Récupérer le corps de requête (JSON ou x-www-form-urlencoded) */
        $raw = file_get_contents('php://input');
        $ct  = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($ct, 'application/json') === 0) {
            $d = json_decode($raw, true) ?: [];
        } else {
            parse_str($raw, $d);                         // compatibilité ancienne version
        }

        /* 2. Vérifier l’id */
        $d['id'] = isset($d['id']) ? (int)$d['id'] : 0;
        if (!$d['id']) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        /* 4. on garde seulement les champs autorisés ET réellement fournis */
        $allowed = ['nom','prenom','tel','email','naissance','sexe','tshirt_size',
                    'ville','entreprise','origine','paiement_mode'];
        $params  = array_intersect_key($d, array_flip($allowed));
        $params['id'] = $d['id'];          // on garde id séparément

        /* naissance vide -> NULL */
        if (isset($params['naissance']) && $params['naissance'] === '') {
            $params['naissance'] = null;
        }

        /* SET : uniquement pour les clés présentes */
        $setParts = [];
        foreach ($params as $k => $v) {
            if ($k !== 'id') $setParts[] = "$k = :$k";
        }
        $set = implode(',', $setParts);

        $pdo->prepare("UPDATE registrations SET $set WHERE id = :id")->execute($params);
        echo json_encode(['ok'=>true]);
        exit;
    }
}

/* ───── IMPORT EXCEL (admin) ─────────────────── */
if ($route === 'import-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin']);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erreur lors du téléchargement du fichier']);
        exit;
    }

    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['file']['tmp_name'])
                     ->getActiveSheet()
                     ->toArray(null, true, true, true); // A, B, C...

        if (empty($sheet) || count($sheet) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Le fichier Excel semble vide']);
            exit;
        }

        // 1. Récupération des correspondances depuis la BDD
        $mapFields = []; // ['numero billet' => 'inscription_no']
        $stmt = $pdo->query('SELECT fields_bdd, fields_excel FROM import');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mapFields[ normaliseLabel($row['fields_excel']) ] = $row['fields_bdd'];
        }

        // 2. Mapping des entêtes Excel
        $headerMap = []; // ['numero billet' => 'A']
        foreach ($sheet[1] as $col => $label) {
            if (!$label) continue;
            $headerMap[ normaliseLabel($label) ] = $col;
        }

        // 3. Vérification des colonnes requises
        $required = array_keys($mapFields);
        $missing = array_diff($required, array_keys($headerMap));

        // Log de debug
        file_put_contents('debug_import.log', json_encode([
            'required' => $required,
            'headerMap' => array_keys($headerMap),
            'missing' => array_values($missing)
        ], JSON_PRETTY_PRINT));

        if ($missing) {
            logImportError([
                'type' => 'colonnes manquantes',
                'missing' => array_values($missing),
                'headerMap' => array_keys($headerMap),
                'required' => $required
            ]);
            http_response_code(422);
            echo json_encode([
                'error'   => 'Colonnes manquantes',
                'missing' => array_values($missing)
            ]);
            exit;
        }

        // 4. Tickets déjà existants
        $existingTickets = $pdo->query('SELECT inscription_no FROM registrations')
                               ->fetchAll(PDO::FETCH_COLUMN, 0);

        // 5. Préparation de la requête
        $stmt = $pdo->prepare(
            'INSERT INTO registrations
             (inscription_no, nom, prenom, tel, email, naissance, sexe,
              tshirt_size, ville, entreprise, origine, paiement_mode,
              created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        // 6. Traitement des lignes
        $pdo->beginTransaction();
        $added = $skipped = 0;
        $duplicates = $errors = [];

        foreach ($sheet as $idx => $row) {
            if ($idx === 1) continue;

            $values = [];
            foreach ($mapFields as $excelLabel => $bddField) {
                $col = $headerMap[$excelLabel] ?? null;
                $value = $col ? trim($row[$col]) : null;

                if ($bddField === 'inscription_no') {
                    $value = (int)$value;
                } elseif ($bddField === 'naissance') {
                    $value = (is_numeric($value) && $value >= 1900 && $value <= date('Y')) ? $value : null;
                } elseif ($bddField === 'created_at') {
                    $value = convertExcelDate($value);
                } elseif ($bddField === 'sexe') {
                    $value = normaliseSexe($value);
                }

                $values[$bddField] = $value ?: null;
            }

            if (!$values['inscription_no'] || !$values['nom'] || !$values['prenom']) {
                $skipped++;
                $errors[] = ['ligne' => $idx, 'erreur' => 'Données manquantes'];
                logImportError([
                    'type' => 'ligne ignorée',
                    'ligne' => $idx,
                    'raison' => 'Données manquantes',
                    'valeurs' => $values
                ]);
                continue;
            }

            if (in_array($values['inscription_no'], $existingTickets, true)) {
                $skipped++;
                $duplicates[] = ['ligne' => $idx, 'ticket' => $values['inscription_no']];
                logImportError([
                    'type' => 'doublon',
                    'ligne' => $idx,
                    'ticket' => $values['inscription_no']
                ]);
                continue;
            }

            $stmt->execute([
                $values['inscription_no'], $values['nom'], $values['prenom'],
                $values['tel'], $values['email'], $values['naissance'], $values['sexe'],
                '-', $values['ville'], $values['entreprise'], 'AssoConnect',
                'en ligne (CB)', $values['created_at'], currentUserId()
            ]);

            $existingTickets[] = $values['inscription_no'];
            $added++;
        }

        $pdo->commit();

        echo json_encode([
            'ok'            => true,
            'rows_added'    => $added,
            'rows_skipped'  => $skipped,
            'duplicates'    => $duplicates,
            'errors'        => $errors
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logImportError([
            'type' => 'exception',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        error_log('Import Excel : '.$e->getMessage());
        http_response_code(500);
        echo json_encode([
            'ok'     => false,
            'error'  => 'import_error',
            'detail' => $e->getMessage()
        ]);
        exit;
    }
}

/* ---------- Petites fonctions utilitaires ---------- */
function normaliseLabel(string $label): string {
    $label = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
    $label = preg_replace('/[^a-zA-Z0-9 ]/', '', $label);
    return strtolower(trim(preg_replace('/\s+/', ' ', $label)));
}

function normaliseSexe(?string $val): ?string {
    $v = strtoupper(trim($val ?? ''));
    return match ($v) {
        'H', 'M', 'HOMME', 'MALE'  => 'H',
        'F', 'FEMME', 'FEMALE'     => 'F',
        ''                         => null,
        default                    => 'Autre'
    };
}

function convertExcelDate($value): ?string {
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($value));
    } else {
        $formats = ['d/m/Y H:i:s', 'd/m/Y', 'Y-m-d'];
        foreach ($formats as $f) {
            $dt = DateTime::createFromFormat($f, $value);
            if ($dt) return $dt->format('Y-m-d H:i:s');
        }
    }
    return date('Y-m-d H:i:s');
}

function logImportError(array $data, string $filename = 'import_errors.log') {
    $entry = date('Y-m-d H:i:s') . " | " . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($filename, $entry, FILE_APPEND);
}


/* ───── EXPORT EXCEL (admin) ─────────────────── */
if ($route === 'export-excel' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole(['admin','user']);

    require_once __DIR__.'/../vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    /* 1. Entêtes */
    $headers = ['No', 'Nom', 'Prénom', 'Tel', 'Email', 'Naissance',
                'Sexe', 'T-shirt', 'Ville', 'Entreprise', 'Origine',
                'Paiement', 'Créé le', 'Par'];
    $sheet->fromArray($headers, null, 'A1');

    /* 2. Données */
    $rows = $pdo->query(
        'SELECT inscription_no, nom, prenom, tel, email, naissance,
                sexe, tshirt_size, ville, entreprise, origine,
                paiement_mode, created_at, created_by
         FROM registrations
         ORDER BY inscription_no'
    )->fetchAll(PDO::FETCH_NUM);

    $sheet->fromArray($rows, null, 'A2');

    /* 3. Style minimal */
    $sheet->getStyle('A1:N1')->getFont()->setBold(true);
    foreach (range('A', 'N') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

    /* 4. Téléchargement */
    $filename = 'inscriptions_'.date('Ymd_His').'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

/* ───── ARCHIVE CURRENT YEAR (admin) ─────────────────── */
if ($route === 'archive-current' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin']);

    $year         = (int) date('Y');                // année en cours
    $tableArchive = "registrations_$year";

    /* 0) s'il n'y a rien à archiver, on sort proprement */
    $nbActives = $pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
    if (!$nbActives) { echo json_encode(['ok'=>true,'archived'=>0]); exit; }

    /* 1) Créer la table archive si nécessaire */
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$tableArchive` LIKE registrations");

    /* 2) Copier toutes les lignes */
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO `$tableArchive` SELECT * FROM registrations");

    /* 3) Statistiques de base */
    $s = $pdo->query("
        SELECT COUNT(*)                           AS total,
               SUM(tshirt_size='XS')              AS xs,
               SUM(tshirt_size='S')               AS s,
               SUM(tshirt_size='M')               AS m,
               SUM(tshirt_size='L')               AS l,
               SUM(tshirt_size='XL')              AS xl,
               SUM(tshirt_size='XXL')             AS xxl,
               AVG(YEAR(NOW()) - naissance)       AS age_moyen
        FROM `$tableArchive`
    ")->fetch(PDO::FETCH_ASSOC);

    foreach (['xs','s','m','l','xl','xxl'] as $k) $s[$k] = (int)($s[$k] ?? 0);

    /* 4) Ville la plus représentée */
    $villeTop = $pdo->query("
        SELECT ville, COUNT(*) as nb 
        FROM `$tableArchive` 
        WHERE ville IS NOT NULL AND ville != '' 
        GROUP BY ville 
        ORDER BY nb DESC 
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $ville_top = $villeTop ? $villeTop['ville'] : null;

    /* 5) Entreprise la plus représentée */
    $entrepriseTop = $pdo->query("
        SELECT entreprise, COUNT(*) as nb 
        FROM `$tableArchive` 
        WHERE entreprise IS NOT NULL AND entreprise != '' 
        GROUP BY entreprise 
        ORDER BY nb DESC 
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $entreprise_top = $entrepriseTop ? $entrepriseTop['entreprise'] : null;

    /* 6) Plus vieille personne masculine */
    $plusVieuxH = $pdo->query("
        SELECT CONCAT(prenom, ' ', nom) as nom_complet, 
               (YEAR(NOW()) - naissance) as age
        FROM `$tableArchive` 
        WHERE sexe = 'H' AND naissance IS NOT NULL 
        ORDER BY naissance ASC 
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $plus_vieux_h = $plusVieuxH ? $plusVieuxH['nom_complet'] : null;

    /* 7) Plus vieille personne féminine */
    $plusVieilleF = $pdo->query("
        SELECT CONCAT(prenom, ' ', nom) as nom_complet, 
               (YEAR(NOW()) - naissance) as age
        FROM `$tableArchive` 
        WHERE sexe = 'F' AND naissance IS NOT NULL 
        ORDER BY naissance ASC 
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $plus_vieille_f = $plusVieilleF ? $plusVieilleF['nom_complet'] : null;

    /* 8) Insérer/Mettre à jour les statistiques */
    $pdo->prepare("
        INSERT INTO registrations_stats
          (year, total_inscrits, tshirt_xs, tshirt_s, tshirt_m,
           tshirt_l, tshirt_xl, tshirt_xxl, age_moyen, table_name,
           ville_top, entreprise_top, plus_vieux_h, plus_vieille_f)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
           total_inscrits = VALUES(total_inscrits),
           tshirt_xs      = VALUES(tshirt_xs),
           tshirt_s       = VALUES(tshirt_s),
           tshirt_m       = VALUES(tshirt_m),
           tshirt_l       = VALUES(tshirt_l),
           tshirt_xl      = VALUES(tshirt_xl),
           tshirt_xxl     = VALUES(tshirt_xxl),
           age_moyen      = VALUES(age_moyen),
           table_name     = VALUES(table_name),
           ville_top      = VALUES(ville_top),
           entreprise_top = VALUES(entreprise_top),
           plus_vieux_h   = VALUES(plus_vieux_h),
           plus_vieille_f = VALUES(plus_vieille_f)
    ")->execute([
        $year, $s['total'], $s['xs'], $s['s'], $s['m'], $s['l'], $s['xl'], $s['xxl'],
        $s['age_moyen'], $tableArchive, $ville_top, $entreprise_top, 
        $plus_vieux_h, $plus_vieille_f
    ]);

    /* 9) On vide la table active pour la nouvelle saison */
    $pdo->exec('TRUNCATE TABLE registrations');
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'archived' => $s['total'],
        'year' => $year,
        'table_name' => $tableArchive
    ]);
    exit;
}

// Dans votre api.php, section registrations-archive
if ($route === 'registrations-archive') {
    requireRole(['admin', 'viewer']);
    
    $year = (int) ($_GET['year'] ?? date('Y'));
    $tableName = $_GET['table_name'] ?? '';
    
    // Utilise table_name si disponible, sinon fallback sur le format standard
    if (!empty($tableName)) {
        $tableArchive = $tableName;
    } else {
        $tableArchive = "registrations_$year";
    }
    
    try {
        // Vérifie si la table existe
        $checkTable = $pdo->query("SHOW TABLES LIKE '$tableArchive'")->rowCount();
        if (!$checkTable) {
            echo json_encode([]);
            exit;
        }
        
        $registrations = $pdo->query(
            "SELECT inscription_no,nom,prenom,tel,email,naissance,sexe,ville,tshirt_size 
             FROM `$tableArchive` 
             ORDER BY inscription_no DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($registrations);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// Gestion des QR Codes
if ($route === 'qrcodes') {
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Récupération des QR codes - avec gestion d'erreurs
        try {
            $stmt = $pdo->prepare('SELECT * FROM qrcodes ORDER BY created_at DESC');
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('Erreur lors de la récupération des QR codes: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la récupération des données']);
        }
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Création d'un nouveau QR code
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        // Fallback si JSON decode échoue
        if (!$data) {
            $data = $_POST;
            error_log('Fallback vers $_POST: ' . print_r($data, true));
        }
        
        // Validation
        if (empty($data['organisation']) || empty($data['base_url'])) {
            error_log('Données manquantes - Organisation: ' . ($data['organisation'] ?? 'vide') . ', Base URL: ' . ($data['base_url'] ?? 'vide'));
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Organisation et URL requis']);
            exit;
        }
        
        // Génération d'un token unique
        $maxAttempts = 10;
        $attempt = 0;
        do {
            $attempt++;
            $token = bin2hex(random_bytes(32));
            
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM qrcodes WHERE token = ?');
                $stmt->execute([$token]);
                $exists = $stmt->fetchColumn() > 0;
            } catch (Exception $e) {
                error_log('Erreur lors de la vérification du token: ' . $e->getMessage());
                $exists = false; // Continue avec ce token
            }
            
            if ($attempt >= $maxAttempts) {
                error_log('Impossible de générer un token unique après ' . $maxAttempts . ' tentatives');
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Erreur lors de la génération du token']);
                exit;
            }
            
        } while ($exists);
        
        // Construction de l'URL finale
        $separator = strpos($data['base_url'], '?') !== false ? '&' : '?';
        $qr_url = $data['base_url'] . $separator . 'token=' . $token;
        
        try {
            // Vérification que la table existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'qrcodes'")->rowCount();
            if ($checkTable == 0) {
                error_log('Table qrcodes n\'existe pas');
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Table qrcodes non trouvée']);
                exit;
            }
            
            $stmt = $pdo->prepare(
                'INSERT INTO qrcodes (organisation, token, qr_url, description, created_by) 
                 VALUES (?, ?, ?, ?, ?)'
            );
            
            $result = $stmt->execute([
                $data['organisation'],
                $token,
                $qr_url,
                $data['description'] ?? null,
                currentUserId() // Ajout de l'utilisateur créateur
            ]);
            
            if ($result) {
                $insertId = $pdo->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'id' => $insertId,
                    'token' => $token,
                    'qr_url' => $qr_url,
                    'message' => 'QR Code créé avec succès'
                ]);
            } else {
                error_log('Échec de l\'insertion en base');
                echo json_encode(['success' => false, 'message' => 'Échec de l\'insertion']);
            }
            
        } catch (Exception $e) {
            error_log('Erreur lors de la création du QR code: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur base de données: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        // Modification d'un QR code
        parse_str(file_get_contents('php://input'), $data);
        
        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID requis']);
            exit;
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
        }
        
        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
        }
        
        if (!empty($updates)) {
            $params[] = $data['id'];
            $sql = 'UPDATE qrcodes SET ' . implode(', ', $updates) . ' WHERE id = ?';
            
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log('Erreur lors de la mise à jour du QR code: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Aucune donnée à modifier']);
        }
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Suppression d'un QR code (admin seulement)
        requireRole(['admin']);
        
        parse_str(file_get_contents('php://input'), $data);
        
        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID requis']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare('DELETE FROM qrcodes WHERE id = ?');
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('Erreur lors de la suppression du QR code: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Fonction pour valider un token QR code
if ($route === 'validate-qr-token') {
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        echo json_encode(['valid' => false, 'message' => 'Token manquant']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare(
            'SELECT organisation, description, is_active, created_at 
             FROM qrcodes 
             WHERE token = ? AND is_active = 1'
        );
        $stmt->execute([$token]);
        $qrData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($qrData) {
            echo json_encode([
                'valid' => true,
                'organisation' => $qrData['organisation'],
                'description' => $qrData['description']
            ]);
        } else {
            echo json_encode(['valid' => false, 'message' => 'Token invalide ou inactif']);
        }
    } catch (Exception $e) {
        error_log('Erreur lors de la validation du token: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['valid' => false, 'message' => 'Erreur serveur']);
    }
    exit;
}

http_response_code(404); 
echo json_encode(['error'=>'route inconnue']);