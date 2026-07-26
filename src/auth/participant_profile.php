<?php
/**
 * participant_profile.php — Modifications que le coureur fait LUI-MÊME.
 *
 * Trois choses, et trois seulement :
 *   • son adresse email        (paramètres — vérifiée par code à 6 chiffres)
 *   • son nom et son prénom    (paramètres)
 *   • son sexe et son âge      (détail d'une inscription)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POURQUOI CE FICHIER EXISTE, ET PAS DEUX FOIS LE MÊME CODE
 * L'espace web et l'API mobile offrent exactement les mêmes modifications. Si
 * chacun portait ses propres contrôles, il suffirait d'en oublier un d'un côté
 * pour que l'API devienne le chemin qui contourne les règles. Ici, les deux
 * appellent les MÊMES fonctions : ce qui est interdit l'est partout.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LES ARCHIVES SONT EN LECTURE SEULE — CE N'EST PAS NÉGOCIABLE
 * Le site archive chaque édition dans `registrations_AAAA`. Ces tables sont la
 * mémoire de l'association : elles ne bougent plus, jamais. Toute écriture ici
 * vise donc UNIQUEMENT la table vivante `registrations`, et une demande portant
 * sur une édition archivée est refusée avec un message clair — pas ignorée en
 * silence, ce qui laisserait croire que la modification a été prise en compte.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ AUCUN ALTER TABLE ICI. Ce module fait des UPDATE sur des colonnes qui
 * existent déjà (`nom`, `prenom`, `email`, `sexe`, `naissance`). La structure de
 * `registrations` reste rigoureusement celle du site.
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/registrations_resolver.php';
require_once __DIR__ . '/../content/registrations_core.php';

/** Valeurs de `sexe` acceptées — identiques au formulaire d'inscription. */
const PPROF_SEXES = ['H', 'F', 'Autre'];

/* ══════════════════════ Journal des modifications ═══════════════════════ */

/**
 * Trace toute modification faite par un coureur.
 *
 * CE N'EST PAS DU CONFORT NON PLUS. Le jour où quelqu'un conteste son classement
 * (« je n'ai jamais changé mon âge »), c'est la seule pièce au dossier. Deux
 * destinations volontairement : le journal fichier, consultable même si la base
 * est en vrac, et `content_logs`, visible depuis l'administration.
 */
function pprofile_journal(PDO $pdo, int $participantId, string $texte): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents(
        $dir . '/logs_espace_coureur.log',
        '[' . date('Y-m-d H:i:s') . '] ' . fer_client_ip() . ' compte#' . $participantId . ' — ' . $texte . "\n",
        FILE_APPEND
    );

    // « (coureur) » dans le libellé : content_logs a des colonnes user_id /
    // user_email prévues pour un ADMINISTRATEUR. Dans une session coureur elles
    // sont vides — sans cette mention, la ligne semblerait venir de nulle part.
    try {
        require_once __DIR__ . '/../content/content-log.php';
        logContentAction($pdo, 'compte_coureur', 'update', $participantId,
            '(coureur) ' . $texte, 'participant');
    } catch (\Throwable $e) { /* jamais bloquant */ }
}

/* ═══════════════════════ Table visée par une écriture ═══════════════════ */

/**
 * Table à modifier pour une année donnée, ou null si l'écriture est interdite.
 * Seule `registrations` — l'édition en cours — est modifiable.
 */
function pprofile_tableModifiable(PDO $pdo, int $annee): ?string
{
    $table = regres_tableForYear($pdo, $annee);
    return $table === 'registrations' ? $table : null;
}

/** La table `registrations` possède-t-elle cette colonne ? */
function pprofile_colonneExiste(PDO $pdo, string $colonne): bool
{
    return in_array($colonne, regres_tableColumns($pdo, 'registrations'), true);
}

/**
 * Les inscriptions du compte pour l'édition en cours, sous forme de numéros.
 * @return string[]
 */
function pprofile_inscriptionsVivantes(PDO $pdo, int $participantId): array
{
    $annee = regres_activeYear($pdo);
    $st = $pdo->prepare('SELECT inscription_no FROM participant_registrations
                          WHERE participant_id = ? AND annee = ?');
    $st->execute([$participantId, $annee]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/* ════════════════════════ Nom et prénom ═════════════════════════════════ */

/**
 * Met à jour l'identité du compte, et la répercute sur l'inscription en cours.
 *
 * La répercussion n'est pas un bonus : si elle n'avait pas lieu, un coureur qui
 * corrige une faute dans son nom la verrait changer dans l'application… et
 * retrouverait la faute sur la liste de départ et sur son dossard.
 *
 * @return array{ok: bool, message?: string, erreur?: string}
 */
function pprofile_majIdentite(PDO $pdo, int $participantId, ?string $nom, ?string $prenom): array
{
    $nom    = mb_substr(trim((string) $nom), 0, 100);
    $prenom = mb_substr(trim((string) $prenom), 0, 100);

    if ($nom === '' || $prenom === '') {
        return ['ok' => false, 'erreur' => 'Le nom et le prénom sont obligatoires.'];
    }
    // Un nom n'est pas un champ libre : lettres, espaces, apostrophes, traits
    // d'union. Le reste n'est pas un nom, c'est une tentative d'injection.
    if (preg_match('/[<>{}\\\\\/\x00-\x1f]/u', $nom . $prenom)) {
        return ['ok' => false, 'erreur' => 'Nom ou prénom contient des caractères non autorisés.'];
    }

    $st = $pdo->prepare('SELECT nom, prenom FROM participants WHERE id = ?');
    $st->execute([$participantId]);
    $avant = $st->fetch(PDO::FETCH_ASSOC) ?: ['nom' => '', 'prenom' => ''];

    $pdo->prepare('UPDATE participants SET nom = ?, prenom = ? WHERE id = ?')
        ->execute([$nom, $prenom, $participantId]);

    // Répercussion sur l'édition EN COURS uniquement. Les champs sont chiffrés
    // dans `registrations` : on écrit du encrypt(), comme le fait le dashboard.
    $touchees = 0;
    $vivantes = pprofile_inscriptionsVivantes($pdo, $participantId);
    if ($vivantes && pprofile_tableModifiable($pdo, regres_activeYear($pdo)) !== null) {
        $up = $pdo->prepare('UPDATE `registrations` SET nom = ?, prenom = ? WHERE inscription_no = ?');
        foreach ($vivantes as $no) {
            $up->execute([encrypt($nom), encrypt($prenom), $no]);
            $touchees += $up->rowCount();
        }
    }

    pprofile_journal($pdo, $participantId, sprintf(
        'Identité modifiée : « %s %s » → « %s %s » (%d inscription(s) mise(s) à jour)',
        $avant['prenom'] ?? '', $avant['nom'] ?? '', $prenom, $nom, $touchees
    ));

    return ['ok' => true, 'message' => $touchees > 0
        ? 'Identité enregistrée, y compris sur votre inscription en cours.'
        : 'Identité enregistrée.'];
}

/* ════════════════════ Sexe et âge d'une inscription ═════════════════════ */

/**
 * La course de cette édition a-t-elle déjà démarré ?
 *
 * ⏱️ Évalué EN SQL. `heure_depart` est stockée en UTC : comparer en PHP
 * obligerait le fuseau de PHP et celui de MySQL à coïncider — vrai aujourd'hui,
 * mais un décalage futur autoriserait des modifications pendant la course sans
 * le moindre message d'erreur.
 */
function pprofile_courseDemarree(PDO $pdo, int $annee): bool
{
    try {
        $st = $pdo->prepare('SELECT (heure_depart IS NOT NULL AND heure_depart < NOW())
                               FROM editions WHERE annee = ? LIMIT 1');
        $st->execute([$annee]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) {
        return false;   // édition inconnue : on ne bloque pas
    }
}

/**
 * Met à jour le sexe et/ou l'âge d'une inscription.
 *
 * @param string|null $sexe H | F | Autre, ou null pour ne pas y toucher
 * @param string|null $age  âge, année de naissance ou date — SEUL L'ÂGE est
 *                          conservé, conformément au modèle du site
 * @return array{ok: bool, message?: string, erreur?: string}
 */
function pprofile_majInscription(
    PDO $pdo, int $participantId, int $annee, string $inscriptionNo,
    ?string $sexe = null, ?string $age = null
): array {
    require_once __DIR__ . '/participant_auth.php';

    if (!pauth_owns($pdo, $participantId, $annee, $inscriptionNo)) {
        return ['ok' => false, 'erreur' => "Cette inscription n'est pas rattachée à votre compte."];
    }
    if (pprofile_tableModifiable($pdo, $annee) === null) {
        return ['ok' => false, 'erreur' =>
            "L'édition $annee est archivée : ses inscriptions ne sont plus modifiables."];
    }
    if (pprofile_courseDemarree($pdo, $annee)) {
        // Sexe et âge déterminent la catégorie de classement. Une fois le départ
        // donné, les changer reviendrait à changer de catégorie en pleine course.
        return ['ok' => false, 'erreur' =>
            'La course a démarré : le sexe et l\'âge ne sont plus modifiables. '
          . 'Contactez l\'organisation pour toute correction.'];
    }

    $avant = regres_find($pdo, $annee, $inscriptionNo);
    if ($avant === null) {
        return ['ok' => false, 'erreur' => 'Inscription introuvable.'];
    }

    $sets = [];
    $args = [];
    $trace = [];

    if ($sexe !== null && trim($sexe) !== '') {
        // ⚠️ NE PAS se reposer sur regcore_normaliseSexe() ici : sa clause
        // `default` renvoie « Autre » pour N'IMPORTE QUELLE valeur. C'est ce
        // qu'il faut pour un import Excel — mieux vaut « Autre » qu'un rejet de
        // ligne — mais côté saisie, cela avalerait silencieusement une valeur
        // fausse au lieu de la signaler. On valide donc la saisie brute.
        $s = match (strtoupper(trim($sexe))) {
            'H', 'M', 'HOMME'  => 'H',
            'F', 'FEMME'       => 'F',
            'AUTRE'            => 'Autre',
            default            => null,
        };
        if ($s === null) {
            return ['ok' => false, 'erreur' => 'Sexe invalide (H, F ou Autre).'];
        }
        if ($s !== ($avant['sexe'] ?? null)) {
            $sets[]  = 'sexe = ?';
            $args[]  = $s;
            $trace[] = 'sexe ' . ($avant['sexe'] ?? '—') . ' → ' . $s;
        }
    }

    if ($age !== null && trim($age) !== '') {
        // Une saisie d'âge, d'année ou de date donne le même résultat : un âge.
        // On ne conserve JAMAIS la date de naissance — c'est le modèle du site,
        // et une donnée personnelle de moins à protéger.
        $calcule = regcore_ageFromNaissance(trim($age), $annee);
        if ($calcule === null || $calcule < 3 || $calcule > 110) {
            return ['ok' => false, 'erreur' => 'Âge invalide (entre 3 et 110 ans).'];
        }
        $ageAvant = regres_age($avant);
        if ($calcule !== $ageAvant) {
            $sets[]  = 'naissance = ?';
            $args[]  = encrypt((string) $calcule);   // `naissance` est une donnée chiffrée
            $trace[] = 'âge ' . ($ageAvant ?? '—') . ' → ' . $calcule;
        }
    }

    if (!$sets) {
        return ['ok' => true, 'message' => 'Aucun changement.'];
    }

    $args[] = $inscriptionNo;
    $pdo->prepare('UPDATE `registrations` SET ' . implode(', ', $sets) . ' WHERE inscription_no = ?')
        ->execute($args);

    pprofile_journal($pdo, $participantId,
        "Inscription $annee/$inscriptionNo — " . implode(', ', $trace));

    return ['ok' => true, 'message' => 'Inscription mise à jour.'];
}

/* ═══════════════════════ Changement d'adresse email ═════════════════════ */

/**
 * Étape 1 — envoie un code de confirmation à la NOUVELLE adresse.
 *
 * DEUX PREUVES SONT EXIGÉES, ET LES DEUX SONT NÉCESSAIRES :
 *   1. être déjà connecté sur le compte  → prouve qu'on en est le titulaire ;
 *   2. saisir le code reçu à la nouvelle → prouve qu'on possède cette boîte.
 * Sans la seconde, une faute de frappe suffirait à s'enfermer dehors : l'adresse
 * du compte est le seul moyen de s'y reconnecter. Et n'importe qui pourrait
 * rattacher un compte à une adresse qu'il ne contrôle pas.
 *
 * @return array{ok: bool, message?: string, erreur?: string}
 */
function pprofile_demanderEmail(PDO $pdo, int $participantId, string $nouveau, string $canal = 'web'): array
{
    require_once __DIR__ . '/participant_auth.php';

    $nouveau = fer_normalizeEmail($nouveau);
    if ($nouveau === '' || !filter_var($nouveau, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erreur' => 'Adresse email invalide.'];
    }

    $st = $pdo->prepare('SELECT email_chiffre, email_hmac FROM participants WHERE id = ?');
    $st->execute([$participantId]);
    $moi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$moi) return ['ok' => false, 'erreur' => 'Compte introuvable.'];

    if (fer_emailHmac($nouveau) === $moi['email_hmac']) {
        return ['ok' => false, 'erreur' => 'C\'est déjà votre adresse actuelle.'];
    }

    // Adresse déjà rattachée à un autre compte : refus. Message volontairement
    // net — l'adresse est saisie par son propriétaire présumé, il n'y a rien à
    // dissimuler ici, et un refus vague enverrait l'utilisateur dans le mur.
    $st = $pdo->prepare('SELECT id FROM participants WHERE email_hmac = ? AND id <> ? LIMIT 1');
    $st->execute([fer_emailHmac($nouveau), $participantId]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'erreur' =>
            'Un compte coureur utilise déjà cette adresse. Connectez-vous avec celle-ci.'];
    }

    pauth_purgeCodes($pdo);
    if (!pauth_rateLimitOk($pdo, $nouveau, fer_client_ip())) {
        return ['ok' => false, 'erreur' => 'Trop de demandes. Réessayez dans quelques minutes.'];
    }

    $code = pauth_issueCode($pdo, $nouveau, in_array($canal, ['web', 'app'], true) ? $canal : 'web', fer_client_ip());
    if (!pprofile_mailChangement($pdo, $nouveau, $code)) {
        return ['ok' => false, 'erreur' => "L'envoi du code a échoué. Réessayez dans un instant."];
    }

    pprofile_journal($pdo, $participantId, 'Changement d\'adresse demandé (code envoyé à la nouvelle adresse)');
    return ['ok' => true, 'message' =>
        'Un code de confirmation vient d\'être envoyé à ' . $nouveau . '. Saisissez-le pour valider.'];
}

/**
 * Étape 2 — vérifie le code et applique le changement.
 *
 * @return array{ok: bool, message?: string, erreur?: string}
 */
function pprofile_confirmerEmail(PDO $pdo, int $participantId, string $nouveau, string $code): array
{
    require_once __DIR__ . '/participant_auth.php';

    $nouveau = fer_normalizeEmail($nouveau);
    if ($nouveau === '' || !filter_var($nouveau, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erreur' => 'Adresse email invalide.'];
    }

    $st = $pdo->prepare('SELECT email_chiffre, email_hmac FROM participants WHERE id = ?');
    $st->execute([$participantId]);
    $moi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$moi) return ['ok' => false, 'erreur' => 'Compte introuvable.'];

    $ancienne = (string) decrypt($moi['email_chiffre']);

    // Le contrôle d'unicité est REFAIT ici : entre la demande et la confirmation,
    // un autre compte a pu prendre l'adresse. La contrainte UNIQUE l'attraperait
    // de toute façon, mais avec un message d'erreur incompréhensible.
    $st = $pdo->prepare('SELECT id FROM participants WHERE email_hmac = ? AND id <> ? LIMIT 1');
    $st->execute([fer_emailHmac($nouveau), $participantId]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'erreur' => 'Un compte coureur utilise déjà cette adresse.'];
    }

    $v = pauth_verifyCode($pdo, $nouveau, $code);
    if (!$v['ok']) {
        return ['ok' => false, 'erreur' => match ($v['raison']) {
            'expire'             => 'Ce code a expiré. Demandez-en un nouveau.',
            'trop_de_tentatives' => 'Trop de tentatives. Demandez un nouveau code.',
            'aucun'              => 'Aucun code en attente pour cette adresse.',
            default              => 'Code incorrect.',
        }];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE participants SET email_chiffre = ?, email_hmac = ? WHERE id = ?')
            ->execute([encrypt($nouveau), fer_emailHmac($nouveau), $participantId]);

        // L'inscription en cours porte l'adresse à laquelle l'organisation écrit
        // (dossard, informations de course). Sans cette ligne, le coureur change
        // d'adresse et continue de ne rien recevoir. Archives non touchées.
        $touchees = 0;
        $vivantes = pprofile_inscriptionsVivantes($pdo, $participantId);
        if ($vivantes && pprofile_tableModifiable($pdo, regres_activeYear($pdo)) !== null) {
            $up = $pdo->prepare('UPDATE `registrations` SET email = ? WHERE inscription_no = ?');
            foreach ($vivantes as $no) {
                $up->execute([encrypt($nouveau), $no]);
                $touchees += $up->rowCount();
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('[PPROF] changement email : ' . $e->getMessage());
        return ['ok' => false, 'erreur' => "Le changement n'a pas pu être enregistré."];
    }

    // Les appareils connectés ne sont PAS révoqués : le compte n'a pas changé de
    // titulaire, et déconnecter le téléphone d'un coureur la veille de la course
    // ferait plus de dégâts que le risque évité. En revanche, l'ancienne adresse
    // est prévenue : si le changement n'était pas de son fait, elle l'apprend.
    pprofile_mailAncienneAdresse($ancienne, $nouveau);

    pprofile_journal($pdo, $participantId, 'Adresse email modifiée (ancienne adresse notifiée)');
    return ['ok' => true, 'message' => 'Votre adresse email a été mise à jour.'];
}

/* ══════════════════════════════ Mails ═══════════════════════════════════ */

/** Code de confirmation envoyé à la NOUVELLE adresse. */
function pprofile_mailChangement(PDO $pdo, string $email, string $code): bool
{
    if (!function_exists('sendMail')) require_once __DIR__ . '/../mail/googleMail.php';
    if (!function_exists('sendMail')) return false;

    require_once __DIR__ . '/participant_auth.php';
    $ttl = (int) pauth_settings($pdo)['participant_code_ttl_min'];
    $h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    // Objet et texte DIFFÉRENTS du mail de connexion, volontairement : si
    // quelqu'un tentait de faire rattacher un compte à l'adresse d'un tiers, ce
    // tiers doit comprendre au premier coup d'œil que ce n'est pas une connexion.
    $corps = '<p>Vous avez demandé à utiliser cette adresse pour votre espace coureur '
        . 'Forbach en Rose. Voici votre code de confirmation :</p>'
        . '<p style="font-size:32px;font-weight:700;letter-spacing:8px;text-align:center;'
        . 'color:#F42182;margin:20px 0">' . $h($code) . '</p>'
        . '<p>Ce code est valable <strong>' . $ttl . ' minutes</strong> et ne sert qu\'une fois.</p>'
        . '<p style="color:#64748b;font-size:13px">Si vous n\'êtes pas à l\'origine de cette demande, '
        . 'ignorez ce message : sans ce code, cette adresse ne sera rattachée à aucun compte.</p>';

    try {
        return (bool) sendMail($email, 'Confirmez votre nouvelle adresse – Forbach en Rose',
            'Changement d\'adresse email', $corps, null, null, 'info', null, 'code');
    } catch (\Throwable $e) {
        error_log('[PPROF] mail changement : ' . $e->getMessage());
        return false;
    }
}

/** Avertissement envoyé à l'ANCIENNE adresse, après coup. */
function pprofile_mailAncienneAdresse(string $ancienne, string $nouvelle): bool
{
    if ($ancienne === '' || !filter_var($ancienne, FILTER_VALIDATE_EMAIL)) return false;
    if (!function_exists('sendMail')) require_once __DIR__ . '/../mail/googleMail.php';
    if (!function_exists('sendMail')) return false;

    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    // La nouvelle adresse est partiellement masquée : ce message part vers une
    // boîte qui n'est peut-être plus celle du titulaire.
    $masque = preg_replace('/^(.).*(.@)/u', '$1***$2', $nouvelle);

    $corps = '<p>L\'adresse email de votre espace coureur Forbach en Rose vient d\'être '
        . 'remplacée par <strong>' . $h($masque) . '</strong>.</p>'
        . '<p>Ce message est le dernier envoyé à cette adresse.</p>'
        . '<p style="color:#64748b;font-size:13px">Si vous n\'êtes pas à l\'origine de ce changement, '
        . 'contactez l\'organisation sans attendre.</p>';

    try {
        return (bool) sendMail($ancienne, 'Votre adresse email a été modifiée – Forbach en Rose',
            'Changement d\'adresse email', $corps, null, null, 'info');
    } catch (\Throwable $e) {
        error_log('[PPROF] mail ancienne adresse : ' . $e->getMessage());
        return false;
    }
}
