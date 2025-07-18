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
        echo json_encode(['ok'=>true]); exit;
    }
    http_response_code(401); echo json_encode(['ok'=>false]); exit;
}
if ($route==='logout'){ session_destroy(); echo json_encode(['ok'=>true]); exit; }

/* ───── USERS (admin) ────────────────────────── */
if ($route === 'users') {
    requireRole(['admin']);

    // 🔁 POST : suppression d’un compte
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        error_log("🔧 Bloc suppression atteint");
        error_log("POST reçu : " . print_r($_POST, true));

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
        requireRole(['admin','user','viewer']);
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

    /* ---------- validation upload ---------- */
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erreur lors du téléchargement du fichier']);
        exit;
    }

    try {
        /* ---------- Autoload Composer ---------- */
        require_once __DIR__ . '/../vendor/autoload.php';    // chemin absolu
        // (pas de “use” en plein milieu de code)

        /* ---------- lecture brute ---------- */
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['file']['tmp_name'])
                     ->getActiveSheet()
                     ->toArray(null, true, true, true);      // clés = A, B, C…

        if (empty($sheet) || count($sheet) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Le fichier Excel semble vide']);
            exit;
        }

        /* ---------- 1) map intitulé → lettre ---------- */
        $headerMap = [];                    // ex. ['numero billet' => 'A']
        foreach ($sheet[1] as $col => $label) {
            if (!$label) continue;
            $headerMap[ normaliseLabel($label) ] = $col;
        }

        /* ---------- 2) colonnes requises ---------- */
        $required = [
            'numero billet', 'prenom participant', 'nom participant',
            'telephone mobile', 'adresse email', 'annee de naissance',
            'sexe', 'ville', 'nom de l\'equipe', 'pays', 'date de creation'
        ];
        $missing = array_diff($required, array_keys($headerMap));
        if ($missing) {
            http_response_code(422);
            echo json_encode([
                'error'   => 'Colonnes manquantes',
                'missing' => array_values($missing)
            ]);
            exit;
        }

        /* ---------- 3) numéros déjà en base ---------- */
        $existingTickets = $pdo->query('SELECT inscription_no FROM registrations')
                               ->fetchAll(PDO::FETCH_COLUMN, 0);

        /* ---------- 4) requête préparée ---------- */
        $stmt = $pdo->prepare(
            'INSERT INTO registrations
             (inscription_no, nom, prenom, tel, email, naissance, sexe,
              tshirt_size, ville, entreprise, origine, paiement_mode,
              created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        /* ---------- 5) parcours lignes ---------- */
        $pdo->beginTransaction();
        $added = $skipped = 0;
        $duplicates = $errors = [];

        foreach ($sheet as $idx => $row) {
            if ($idx === 1) continue;                       // saute l’entête

            $inscriptionNo = (int)$row[ $headerMap['numero billet'] ];
            $nom           = trim($row[ $headerMap['nom participant'] ] ?? '');
            $prenom        = trim($row[ $headerMap['prenom participant'] ] ?? '');

            /* -- données minimales ? -- */
            if (!$inscriptionNo || !$nom || !$prenom) {
                $skipped++;  $errors[] = ['ligne'=>$idx,'erreur'=>'Données manquantes'];
                continue;
            }

            /* -- doublon ? -- */
            if (in_array($inscriptionNo, $existingTickets, true)) {
                $skipped++;  $duplicates[] = ['ligne'=>$idx,'ticket'=>$inscriptionNo];
                continue;
            }

            /* -- année de naissance -- */
            $naissance = null;
            $nRaw = $row[ $headerMap['annee de naissance'] ] ?? null;
            if (is_numeric($nRaw) && $nRaw >= 1900 && $nRaw <= date('Y')) $naissance = $nRaw;

            /* -- date de création (Excel ou texte) -- */
            $createdRaw = $row[ $headerMap['date de creation'] ] ?? '';
            $createdAt  = null;
            if ($createdRaw !== '') {
                if (is_numeric($createdRaw)) {                   // série Excel
                    $createdAt = date(
                        'Y-m-d H:i:s',
                        \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($createdRaw)
                    );
                } else {                                         // texte
                    $fmt = ['d/m/Y H:i:s','d/m/Y','Y-m-d'];
                    foreach ($fmt as $f) {
                        $dt = DateTime::createFromFormat($f, $createdRaw);
                        if ($dt) { $createdAt = $dt->format('Y-m-d H:i:s'); break; }
                    }
                }
            }
            $createdAt ??= date('Y-m-d H:i:s');                  // fallback

            /* -- insertion -- */
            $stmt->execute([
                $inscriptionNo, $nom, $prenom,
                $row[ $headerMap['telephone mobile'] ] ?: null,
                $row[ $headerMap['adresse email'] ]    ?: null,
                $naissance,
                normaliseSexe($row[ $headerMap['sexe'] ] ?? null),
                '-',                                    // tshirt_size fixe
                $row[ $headerMap['ville'] ]             ?: null,
                $row[ $headerMap['nom de l\'equipe'] ]  ?: null,
                $row[ $headerMap['pays'] ]              ?: null,
                'en ligne (CB)',                        // paiement_mode fixe
                $createdAt,
                currentUserId()
            ]);

            $existingTickets[] = $inscriptionNo;
            $added++;
        }
        $pdo->commit();

        /* ---------- 6) réponse ---------- */
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

/** Normalise l'intitulé d'une colonne : minuscules, accents retirés, espaces simples */
function normaliseLabel(string $label): string {
    // supprime les accents, remplace \s+ par un espace, trim, lower
    $noAccents = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
    return strtolower(trim(preg_replace('/\s+/', ' ', $noAccents)));
}

/** Convertit des libellés divers en valeurs ENUM acceptées */
function normaliseSexe(?string $val): ?string {
    $v = strtoupper(trim($val ?? ''));
    return match ($v) {
        'H', 'M', 'HOMME', 'MALE'  => 'H',
        'F', 'FEMME', 'FEMALE'     => 'F',
        ''                         => null,
        default                    => 'Autre'
    };
}

http_response_code(404); 
echo json_encode(['error'=>'route inconnue']);