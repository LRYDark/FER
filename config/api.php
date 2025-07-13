<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


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
if ($route==='import-excel' && $_SERVER['REQUEST_METHOD']==='POST'){
    requireRole(['admin']);
    if(!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK){
        http_response_code(400); echo json_encode(['error'=>'upload']); exit;
    }
    require 'vendor/autoload.php';
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['file']['tmp_name'])
             ->getActiveSheet()->toArray(null,true,true,true);

    $pdo->beginTransaction();
    $last = $pdo->query('SELECT MAX(inscription_no) FROM registrations')->fetchColumn() ?: 0;
    $added=0;
    foreach($sheet as $i=>$r){
        if($i===1 || !$r['A']) continue;
        $no=++$last;
        $pdo->prepare('INSERT INTO registrations
         (inscription_no,nom,prenom,tel,email,naissance,sexe,tshirt_size,
          ville,entreprise,origine,paiement_mode,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
          $no,$r['A'],$r['B'],$r['C'],$r['D'],
          $r['E']?:null,$r['F'],$r['G'],$r['H'],$r['I'],
          $r['J'],$r['K'],currentUserId()
        ]);
        $added++;
    }
    $pdo->commit();
    echo json_encode(['ok'=>true,'rows'=>$added]); exit;
}

http_response_code(404); 
echo json_encode(['error'=>'route inconnue']);