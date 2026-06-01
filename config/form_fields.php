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
    $name     = htmlspecialchars($field['bdd_column']);
    $label    = htmlspecialchars($field['label']);
    $type     = $field['field_type'] ?? 'text';
    $required = (int) ($field['required'] ?? 0);
    $reqAttr  = $required ? 'required' : '';
    $reqStar  = $required ? ' <span style="color:#ef4444">*</span>' : '';
    $val      = htmlspecialchars($value);

    $html = '<div class="col-md-6">';
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
    } elseif ($type === 'date') {
        $html .= "<input name=\"{$name}\" type=\"date\" class=\"form-control\" value=\"{$val}\" {$reqAttr}>";
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
