import 'package:flutter/material.dart';

/// Thème de l'application, aligné sur le site.
///
/// Le rose `#db2777` est la couleur d'accent par défaut du site (colonne
/// `theme_primary_color` de la table `setting`). L'application n'invente pas sa
/// propre identité : quelqu'un qui passe du mail au site puis à l'application
/// doit reconnaître la même association.
///
/// Clair ET sombre, tous les deux : le site laisse ce choix au coureur, forcer
/// un thème clair sur un téléphone réglé en sombre serait un pas en arrière.
library;

const Color roseFer = Color(0xFFDB2777);
const Color roseFerSombre = Color(0xFFF472B6);

ThemeData themeFer({required Brightness luminosite}) {
  final schema = ColorScheme.fromSeed(
    seedColor: luminosite == Brightness.dark ? roseFerSombre : roseFer,
    brightness: luminosite,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: schema,
    // Les surfaces des cartes portent l'essentiel de la lecture : une élévation
    // franche les sépare du fond sans avoir à dessiner de bordure.
    cardTheme: CardTheme(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: schema.outlineVariant),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(48),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
        ),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
      filled: true,
    ),
    listTileTheme: const ListTileThemeData(
      contentPadding: EdgeInsets.symmetric(horizontal: 16, vertical: 4),
    ),
  );
}

/// Chiffres à chasse fixe pour les chronos.
///
/// ⚠️ SANS CELA, LE CHRONO TREMBLE. Dans une police proportionnelle, un « 1 »
/// est plus étroit qu'un « 8 » : le compteur change de largeur à chaque
/// seconde, et l'œil suit un texte qui bouge au lieu de lire l'heure.
const TextStyle chiffresFixes = TextStyle(
  fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
);

/// Espacement standard entre les blocs, partout dans l'application.
const double marge = 16;

/// Carte au style commun : c'est le seul conteneur employé par les écrans.
class CarteFer extends StatelessWidget {
  const CarteFer({
    required this.enfant,
    this.titre,
    this.icone,
    this.action,
    super.key,
  });

  final Widget enfant;
  final String? titre;
  final IconData? icone;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(marge),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            if (titre != null) ...<Widget>[
              Row(
                children: <Widget>[
                  if (icone != null) ...<Widget>[
                    Icon(icone, color: theme.colorScheme.primary, size: 20),
                    const SizedBox(width: 8),
                  ],
                  Expanded(
                    child: Text(
                      titre!,
                      style: theme.textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  if (action != null) action!,
                ],
              ),
              const SizedBox(height: 12),
            ],
            enfant,
          ],
        ),
      ),
    );
  }
}

/// Pastille d'état, l'équivalent du `.pill` du site.
class Pastille extends StatelessWidget {
  const Pastille(this.texte, {this.couleur, this.icone, super.key});

  final String texte;
  final Color? couleur;
  final IconData? icone;

  @override
  Widget build(BuildContext context) {
    final c = couleur ?? Theme.of(context).colorScheme.outline;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        // ignore: deprecated_member_use
        color: c.withOpacity(0.14),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icone != null) ...<Widget>[
            Icon(icone, size: 13, color: c),
            const SizedBox(width: 5),
          ],
          Text(
            texte,
            style: TextStyle(
                color: c, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

/// Message affiché quand il n'y a rien à montrer.
///
/// Toujours accompagné d'une explication : « aucun résultat » sans dire
/// pourquoi laisse croire à une panne.
class RienAAfficher extends StatelessWidget {
  const RienAAfficher({
    required this.icone,
    required this.titre,
    required this.explication,
    this.action,
    super.key,
  });

  final IconData icone;
  final String titre;
  final String explication;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(icone, size: 48, color: theme.colorScheme.outline),
            const SizedBox(height: 16),
            Text(titre,
                style: theme.textTheme.titleMedium, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text(
              explication,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium
                  ?.copyWith(color: theme.colorScheme.outline),
            ),
            if (action != null) ...<Widget>[
              const SizedBox(height: 20),
              action!,
            ],
          ],
        ),
      ),
    );
  }
}
