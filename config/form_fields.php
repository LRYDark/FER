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
 * Rend un champ HTML pour un formulaire.
 * @param array $field  Ligne de la table forms
 * @param string $value Valeur actuelle (pour l'édition)
 * @return string HTML
 */
function renderFormField(array $field, string $value = ''): string
{
    $type     = $field['field_type'] ?? 'text';

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
             . '</div></div></div>';
    }

    $name     = htmlspecialchars($field['bdd_column'] ?? '');
    $label    = htmlspecialchars($field['label']);
    $required = (int) ($field['required'] ?? 0);
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
    $reserved = ['paiement_mode', 'montant_du', 'origine', 'created_at', 'created_by'];
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
