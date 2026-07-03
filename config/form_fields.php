<?php
/**
 * Fonctions utilitaires pour le rendu dynamique des champs formulaire.
 * Chargé par register.php, saisie.php, dashboard.php.
 */

/**
 * Charge les champs actifs pour un contexte donné.
 * @param PDO $pdo
 * @param string $context 'public' | 'admin' | 'saisie' | 'qr'
 * @return array
 */
function getActiveFields(PDO $pdo, string $context = 'public'): array
{
    $col = match ($context) {
        'admin'  => 'visible_admin',
        'saisie' => 'visible_saisie',
        'bulk'   => 'visible_saisie_multiple',
        default  => 'visible_qr',
    };

    $stmt = $pdo->prepare(
        "SELECT * FROM forms WHERE active = 1 AND `{$col}` = 1 ORDER BY sort_order ASC"
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Rend un champ personnalisé « autorisation parentale » (à insérer DANS la carte du
 * bloc responsable légal). Pas de colonne BDD : la valeur est injectée dans le
 * commentaire par js/inscription-form.js (data-guardian="custom").
 */
function renderGuardianExtraField(array $field, bool $forceOptional = false): string
{
    $type  = $field['field_type'] ?? 'text';
    $key   = htmlspecialchars($field['label'] ?? 'Champ');
    $req   = $forceOptional ? 0 : (int) ($field['required'] ?? 0);
    $star  = $req ? ' <span style="color:#ef4444">*</span>' : '';
    $attrs = ' data-guardian="custom" data-guardian-key="' . $key . '" data-guardian-req="' . $req . '"';
    if ($type === 'select') {
        $opts  = array_map('trim', explode(',', $field['options_list'] ?? ''));
        $inner = '<select class="form-select"' . $attrs . '>';
        foreach ($opts as $o) { $oe = htmlspecialchars($o); $inner .= '<option value="' . $oe . '">' . $oe . '</option>'; }
        $inner .= '</select>';
        $colClass = 'col-md-6';
    } elseif ($type === 'textarea') {
        $inner    = '<textarea class="form-control" rows="2"' . $attrs . '></textarea>';
        $colClass = 'col-12';
    } else {
        $t        = in_array($type, ['number', 'email', 'date', 'tel'], true) ? $type : 'text';
        $inner    = '<input type="' . $t . '" class="form-control"' . $attrs . '>';
        $colClass = 'col-md-6';
    }
    return '<div class="' . $colClass . '">'
         . '<label class="form-label">' . $key . $star . '</label>'
         . $inner . '</div>';
}

/**
 * Rend un champ HTML pour un formulaire.
 * @param array $field  Ligne de la table forms
 * @param string $value Valeur actuelle (pour l'édition)
 * @param bool   $forceOptional Force le champ en non-obligatoire quel que soit
 *               le réglage `required` de la table forms (ex. modal admin où
 *               l'email reste facultatif même s'il est requis ailleurs).
 * @return string HTML
 */
function renderFormField(array $field, string $value = '', bool $forceOptional = false, string $context = 'public'): string
{
    $type     = $field['field_type'] ?? 'text';

    // Champ personnalisé « autorisation parentale » : il n'est PAS rendu tout seul ici
    // — il est rendu à l'intérieur de la carte du bloc responsable légal (juste après
    // Nom/Prénom), par le bloc `guardian` ci-dessous via renderGuardianExtraField().
    if (!empty($field['guardian_section'])) {
        return '';
    }

    // Bloc spécial « Autorisation parentale (mineur) » : ce n'est PAS une colonne
    // BDD (bdd_column NULL). Les nom/prénom du responsable saisis ici sont injectés
    // dans le champ `commentaire` par js/inscription-form.js. Le bloc est affiché ou
    // masqué selon l'âge calculé, et entièrement paramétrable (actif / requis / âge /
    // visibilité par contexte) depuis « Gestion des champs du formulaire ».
    if ($type === 'guardian') {
        $age = (int) ($field['options_list'] ?? 18);
        if ($age < 1 || $age > 120) $age = 18;
        $req = (int) ($field['required'] ?? 0);
        // Étoile rouge sur les libellés quand le responsable est obligatoire
        // (même convention que les autres champs requis du formulaire).
        $gStar = $req ? ' <span style="color:#ef4444">*</span>' : '';
        $lbl = htmlspecialchars($field['label'] ?? 'Autorisation parentale (mineur)');
        // Texte de consentement (paramétrable dans « Gestion des champs »). Affiché
        // sous les champs du responsable légal, sur tous les formulaires où le bloc
        // apparaît (public / admin / saisie).
        $consent = trim((string) ($field['help_text'] ?? ''));
        $consentHtml = $consent !== ''
            ? '<div class="mt-2" style="font-size:12px;color:#9a3412;line-height:1.4">'
              . '<i class="bi bi-info-circle me-1"></i>' . nl2br(htmlspecialchars($consent)) . '</div>'
            : '';

        // Champs personnalisés rattachés à ce bloc : rendus À L'INTÉRIEUR de la carte,
        // juste après Nom/Prénom, filtrés par le contexte du formulaire courant.
        $extraHtml = '';
        global $pdo;
        if (isset($pdo) && $pdo) {
            // Contexte du formulaire courant (passé explicitement par l'appelant) →
            // même colonne de visibilité que le reste du formulaire.
            $visCol = match ($context) {
                'admin'  => 'visible_admin',
                'saisie' => 'visible_saisie',
                'bulk'   => 'visible_saisie_multiple',
                default  => 'visible_qr',
            };
            try {
                $exStmt = $pdo->query("SELECT * FROM forms WHERE active = 1 AND guardian_section = 1 AND `{$visCol}` = 1 ORDER BY sort_order ASC");
                foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $ef) {
                    $extraHtml .= renderGuardianExtraField($ef, $forceOptional);
                }
            } catch (\Throwable $e) { /* colonne guardian_section absente (migration) → ignore */ }
        }

        return '<div class="col-12 guardian-block" data-guardian-block'
             . ' data-minor-age="' . $age . '" data-guardian-required="' . $req . '" style="display:none">'
             . '<div class="p-3 rounded" style="background:#fff7ed;border:1px solid #fed7aa">'
             . '<div class="mb-2"><strong style="font-size:13px;color:#9a3412">'
             . '<i class="bi bi-shield-check me-1"></i>' . $lbl . '</strong></div>'
             . '<div class="row g-2">'
             . '<div class="col-md-6"><label class="form-label">Nom du responsable légal' . $gStar . '</label>'
             . '<input type="text" class="form-control" data-guardian="nom"></div>'
             . '<div class="col-md-6"><label class="form-label">Prénom du responsable légal' . $gStar . '</label>'
             . '<input type="text" class="form-control" data-guardian="prenom"></div>'
             . $extraHtml
             . '</div>' . $consentHtml . '</div></div>';
    }

    $name     = htmlspecialchars($field['bdd_column'] ?? '');
    $label    = htmlspecialchars($field['label']);
    $required = $forceOptional ? 0 : (int) ($field['required'] ?? 0);
    $reqAttr  = $required ? 'required' : '';
    $reqStar  = $required ? ' <span style="color:#ef4444">*</span>' : '';
    $val      = htmlspecialchars($value);

    // Le commentaire (textarea) s'affiche en pleine largeur ; les autres champs sur 2 colonnes.
    $colClass = ($type === 'textarea') ? 'col-12' : 'col-md-6';
    $html = "<div class=\"{$colClass}\">";
    $html .= "<label class=\"form-label\">{$label}{$reqStar}</label>";

    if ($type === 'select') {
        $options = array_map('trim', explode(',', $field['options_list'] ?? ''));
        $html .= "<select name=\"{$name}\" class=\"form-select\" {$reqAttr}>";
        foreach ($options as $opt) {
            $optVal = htmlspecialchars($opt);
            $sel = ($opt === $value) ? ' selected' : '';
            $html .= "<option value=\"{$optVal}\"{$sel}>{$optVal}</option>";
        }
        $html .= '</select>';
    } elseif ($type === 'date' && ($field['bdd_column'] ?? '') === 'naissance') {
        // Champ naissance « intelligent » : accepte une date complète (JJ/MM/AAAA),
        // une année (AAAA) ou un âge (converti en année par js/inscription-form.js).
        $html .= "<input name=\"{$name}\" type=\"text\" inputmode=\"numeric\" autocomplete=\"off\" class=\"form-control birthdate-input\" value=\"{$val}\" placeholder=\"JJ/MM/AAAA, année (AAAA) ou âge\" {$reqAttr}>";
        $html .= '<small class="form-text text-muted birthdate-hint"></small>';
    } elseif ($type === 'date') {
        $html .= "<input name=\"{$name}\" type=\"date\" class=\"form-control\" value=\"{$val}\" {$reqAttr}>";
    } elseif ($type === 'textarea') {
        $html .= "<textarea name=\"{$name}\" class=\"form-control\" rows=\"3\" {$reqAttr}>{$val}</textarea>";
    } elseif ($type === 'number') {
        $html .= "<input name=\"{$name}\" type=\"number\" class=\"form-control\" value=\"{$val}\" {$reqAttr}>";
    } elseif ($type === 'email') {
        $html .= "<input name=\"{$name}\" type=\"email\" class=\"form-control\" value=\"{$val}\" {$reqAttr}>";
    } else {
        $html .= "<input name=\"{$name}\" type=\"text\" class=\"form-control\" value=\"{$val}\" {$reqAttr}>";
    }

    $html .= '</div>';
    return $html;
}

/**
 * Retourne tous les noms de colonnes BDD des champs actifs (pour INSERT/UPDATE dynamiques).
 * @param PDO $pdo
 * @return array ['bdd_column' => ['encrypted' => bool, 'field_type' => string], ...]
 */
function getAllActiveFieldColumns(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT bdd_column, encrypted, field_type FROM forms WHERE active = 1 ORDER BY sort_order ASC');
    $cols = [];
    // Colonnes système gérées par leur propre logique (paiement / calcul du montant)
    // et qu'il ne faut JAMAIS traiter comme un champ saisi par l'utilisateur, même
    // si elles sont marquées « active » dans la table forms.
    $reserved = ['paiement_mode', 'montant_du', 'origine', 'created_at', 'date_inscription', 'created_by'];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['bdd_column'] && !in_array($row['bdd_column'], $reserved, true)) {
            $cols[$row['bdd_column']] = [
                'encrypted'  => (bool) $row['encrypted'],
                'field_type' => $row['field_type'],
            ];
        }
    }
    return $cols;
}
