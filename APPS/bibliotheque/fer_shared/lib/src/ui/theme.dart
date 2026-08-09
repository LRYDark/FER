/// Thème de l'application, aligné sur le site.
///
/// Le rose `#db2777` est la couleur d'accent par défaut du site (colonne
/// `theme_primary_color` de la table `setting`). L'application n'invente pas sa
/// propre identité : quelqu'un qui passe du mail au site puis à l'application
/// doit reconnaître la même association.
///
/// Clair ET sombre, tous les deux : le site laisse ce choix au coureur, forcer
/// un thème clair sur un téléphone réglé en sombre serait un pas en arrière.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// PARTI PRIS : ON SÉPARE PAR LE VIDE, PAS PAR DES TRAITS.
///
/// La version précédente encadrait chaque bloc d'une bordure et d'un coin
/// arrondi. Sur un écran de téléphone, cinq cartes bordées font cinq boîtes qui
/// se disputent l'attention, et l'œil compte les cadres au lieu de lire.
///
/// Ici, la hiérarchie vient de la TAILLE et du POIDS du texte, et la séparation
/// vient de l'ESPACE. C'est ce que font Réglages, Santé, Strava ou Revolut
/// aujourd'hui — et ce n'est pas une mode : moins de traits, c'est plus de
/// place pour le contenu sur un écran qui n'en a jamais assez.
///
/// ⚠️ Une seule exception assumée : les blocs d'ALERTE gardent un fond teinté.
/// Un avertissement qui ressemble au reste de la page n'est pas un
/// avertissement.
///
/// Tout cela vit ICI et nulle part ailleurs : les deux coques, iPhone et
/// Android, prennent le même fichier. Un style écrit dans un écran serait à
/// refaire deux fois, et divergerait au premier correctif.
library;

import 'package:flutter/material.dart';

const Color roseFer = Color(0xFFDB2777);
const Color roseFerSombre = Color(0xFFF472B6);

ThemeData themeFer({required Brightness luminosite}) {
  final sombre = luminosite == Brightness.dark;
  final schema = ColorScheme.fromSeed(
    seedColor: sombre ? roseFerSombre : roseFer,
    brightness: luminosite,
  );

  final base = ThemeData(useMaterial3: true, colorScheme: schema);

  return base.copyWith(
    scaffoldBackgroundColor: schema.surface,

    // Titres larges, alignés à gauche, sans ligne de séparation ni ombre :
    // la barre se fond dans la page au lieu de la couper en deux.
    appBarTheme: AppBarTheme(
      backgroundColor: schema.surface,
      surfaceTintColor: Colors.transparent,
      foregroundColor: schema.onSurface,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: base.textTheme.headlineSmall?.copyWith(
        color: schema.onSurface,
        fontWeight: FontWeight.w700,
        letterSpacing: -0.5,
      ),
    ),

    textTheme: base.textTheme.copyWith(
      // `letterSpacing` négatif sur les grandes tailles : c'est ce qui donne
      // l'aspect « titre » plutôt que « texte agrandi ».
      headlineLarge: base.textTheme.headlineLarge
          ?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.8),
      headlineMedium: base.textTheme.headlineMedium
          ?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.6),
      headlineSmall: base.textTheme.headlineSmall
          ?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.5),
      titleMedium: base.textTheme.titleMedium
          ?.copyWith(fontWeight: FontWeight.w600, letterSpacing: -0.2),
      titleSmall: base.textTheme.titleSmall
          ?.copyWith(fontWeight: FontWeight.w600),
    ),

    // Plus de bordure, plus de coin dessiné : une surface à peine détachée du
    // fond, employée seulement là où un vrai regroupement est nécessaire.
    cardTheme: CardThemeData(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: schema.surfaceContainerLow,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
    ),

    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(52),
        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        minimumSize: const Size.fromHeight(52),
        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        side: BorderSide(color: schema.outlineVariant),
      ),
    ),

    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: schema.surfaceContainerHighest,
      // Sans trait : le champ se reconnaît à son fond, comme sur iOS.
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide.none,
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: schema.primary, width: 2),
      ),
      contentPadding:
          const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
    ),

    listTileTheme: const ListTileThemeData(
      contentPadding: EdgeInsets.symmetric(horizontal: 4, vertical: 6),
    ),

    dividerTheme: DividerThemeData(
      color: schema.outlineVariant,
      thickness: 0.5,
      space: 1,
    ),

    // ── Fenêtres de confirmation ────────────────────────────────────────────
    // ⚠️ ELLES ÉTAIENT RESTÉES AU STYLE MATERIAL PAR DÉFAUT : coins peu
    // arrondis, teinte violette d'élévation, titre plus petit que les titres de
    // la page. Une fenêtre qui ne ressemble pas à l'application donne
    // l'impression de venir du système — et c'est précisément à ce moment-là
    // qu'on demande une décision (retirer une autorisation, se déconnecter).
    // Elle doit inspirer la même confiance que le reste.
    dialogTheme: DialogThemeData(
      backgroundColor: schema.surfaceContainerLow,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      titleTextStyle: base.textTheme.titleLarge?.copyWith(
        color: schema.onSurface,
        fontWeight: FontWeight.w700,
        letterSpacing: -0.3,
      ),
      contentTextStyle: base.textTheme.bodyMedium?.copyWith(
        color: schema.onSurfaceVariant,
        height: 1.45,
      ),
      insetPadding: const EdgeInsets.symmetric(horizontal: 28, vertical: 24),
    ),

    // ── Bandeaux de confirmation ────────────────────────────────────────────
    // `floating` et non `fixed` : posé, le bandeau se colle au bas de l'écran
    // et recouvre la barre de navigation. Flottant, il s'en détache, prend les
    // mêmes coins arrondis que les cartes, et laisse voir où l'on se trouve.
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: schema.inverseSurface,
      contentTextStyle: base.textTheme.bodyMedium?.copyWith(
        color: schema.onInverseSurface,
      ),
      actionTextColor: schema.inversePrimary,
      elevation: 3,
      insetPadding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    ),

    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: schema.surface,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      height: 68,
      indicatorColor: schema.primaryContainer,
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      labelTextStyle: WidgetStateProperty.resolveWith(
        (etats) => TextStyle(
          fontSize: 11,
          fontWeight: etats.contains(WidgetState.selected)
              ? FontWeight.w700
              : FontWeight.w500,
        ),
      ),
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
const double marge = 20;

/// Réserve au BAS de toute liste défilante.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// ⚠️ SANS ELLE, LE BANDEAU DE CONFIRMATION MANGE LA FIN DE LA PAGE.
///
/// Un bandeau (« Autorisation retirée… ») se pose au-dessus de la barre de
/// navigation, PAR-DESSUS le contenu — il ne pousse rien. Avec une marge basse
/// ordinaire, le dernier bloc de la liste passe dessous et devient
/// inatteignable : on défile jusqu'en bas, et il reste caché.
///
/// La réserve laisse de quoi faire défiler le contenu AU-DELÀ du bandeau. Elle
/// ne coûte rien quand il n'y en a pas : c'est du vide en fin de liste, que
/// personne ne remarque.
///
/// ⚠️ À AJOUTER À TOUTE NOUVELLE LISTE. Un écran qui l'oublie ne se voit qu'au
/// moment précis où un bandeau s'affiche — c'est-à-dire rarement, et jamais
/// pendant les essais.
const double margeBasListe = 96;

/* ═══════════════════════════════ Structure ═════════════════════════════════ */

/// Intertitre discret, façon « 2026 » au-dessus d'une liste.
///
/// En majuscules et en petit : il situe sans concurrencer le contenu. Un
/// intertitre de la même taille que ce qu'il annonce ne hiérarchise rien.
class SectionFer extends StatelessWidget {
  const SectionFer(this.texte, {this.action, super.key});

  final String texte;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 8, 4, 10),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text(
              texte.toUpperCase(),
              style: theme.textTheme.labelMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.8,
              ),
            ),
          ),
          if (action != null) action!,
        ],
      ),
    );
  }
}

/// Regroupement de contenu.
///
/// Par défaut SANS fond : le titre et l'espace suffisent à séparer. Le fond
/// n'est demandé que là où plusieurs lignes forment vraiment un objet — une
/// inscription, un résultat — et jamais pour décorer.
class CarteFer extends StatelessWidget {
  const CarteFer({
    required this.enfant,
    this.titre,
    this.icone,
    this.action,
    this.fond = false,
    this.contour = false,
    this.surTouche,
    super.key,
  });

  final Widget enfant;
  final String? titre;
  final IconData? icone;
  final Widget? action;

  /// Pose une surface teintée derrière le contenu.
  ///
  /// ⚠️ SUR UN THÈME COLORÉ, C'EST À MANIER AVEC PRÉCAUTION.
  /// `surfaceContainerLow` dérive de la couleur d'accent : sur le rose du
  /// projet, elle donne un bloc à peine plus foncé que le fond de la page —
  /// assez pour délaver, pas assez pour séparer. Préférer `contour` quand il
  /// s'agit seulement de délimiter.
  final bool fond;

  /// Encadre le contenu d'un filet, sans le remplir.
  ///
  /// C'est ce qu'il faut pour distinguer des blocs RÉPÉTÉS — une carte par
  /// édition, par exemple : le trait dit où l'un finit et où l'autre commence,
  /// sans poser d'aplat coloré qui écraserait le contenu.
  final bool contour;

  final VoidCallback? surTouche;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final contenu = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        if (titre != null) ...<Widget>[
          Row(
            children: <Widget>[
              if (icone != null) ...<Widget>[
                Icon(icone, color: theme.colorScheme.primary, size: 20),
                const SizedBox(width: 10),
              ],
              Expanded(
                child: Text(titre!, style: theme.textTheme.titleMedium),
              ),
              if (action != null) action!,
            ],
          ),
          const SizedBox(height: 14),
        ],
        enfant,
      ],
    );

    if (contour) {
      return Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: theme.colorScheme.outlineVariant),
        ),
        clipBehavior: Clip.antiAlias,
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: surTouche,
            child: Padding(
              padding: const EdgeInsets.all(marge),
              child: contenu,
            ),
          ),
        ),
      );
    }

    if (!fond) {
      return surTouche == null
          ? contenu
          : InkWell(
              onTap: surTouche,
              borderRadius: BorderRadius.circular(20),
              child: contenu,
            );
    }

    return Material(
      color: theme.colorScheme.surfaceContainerLow,
      borderRadius: BorderRadius.circular(20),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: surTouche,
        child: Padding(padding: const EdgeInsets.all(marge), child: contenu),
      ),
    );
  }
}

/// Bloc d'information mis en avant — la seule chose qui garde un fond coloré.
///
/// ⚠️ Réservé à ce qui doit ARRÊTER LE REGARD : une alerte, un état de course,
/// une conséquence. S'en servir pour du contenu ordinaire userait l'effet, et
/// le jour où il faudra vraiment prévenir, plus rien ne se distinguera.
class BlocAccent extends StatelessWidget {
  const BlocAccent({
    required this.enfant,
    this.couleur,
    this.icone,
    super.key,
  });

  final Widget enfant;
  final Color? couleur;
  final IconData? icone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = couleur ?? theme.colorScheme.primary;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Color.alphaBlend(c.withAlpha(26), theme.colorScheme.surface),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (icone != null) ...<Widget>[
            Icon(icone, size: 20, color: c),
            const SizedBox(width: 12),
          ],
          Expanded(child: enfant),
        ],
      ),
    );
  }
}

/// Ligne « libellé, puis valeur en dessous ».
///
/// ═════════════════════════════════════════════════════════════════════════════
/// TOUJOURS EMPILÉ. UNE SEULE MISE EN PAGE, SANS EXCEPTION.
///
/// La disposition en deux colonnes — libellé à gauche, valeur poussée à droite
/// — se lit très bien tant que la valeur est courte. Appliquée à une adresse,
/// elle donne ceci, et c'est ce qu'on avait à l'écran :
///
///     Rendez-vous                     50A Rue Saint-Louis,
///                                        57600 Morsbach,
///                                                  France
///
/// Un texte aligné à droite et replié sur trois lignes n'a plus de bord gauche
/// stable : l'œil doit chercher le début de chaque ligne.
///
/// ⚠️ UN PREMIER ESSAI CHOISISSAIT LA DISPOSITION SELON LA LONGUEUR. Mauvaise
/// idée : dans une même liste, « Distance » restait à droite pendant que
/// « Rendez-vous » passait dessous. Deux alignements côte à côte se lisent
/// comme un défaut d'affichage, pas comme une intention — et le regard n'a
/// plus aucune colonne à suivre.
///
/// Une seule règle, donc : le libellé au-dessus, en discret ; la valeur
/// dessous, alignée à gauche comme tout le reste de la page.
class LigneFer extends StatelessWidget {
  const LigneFer(this.libelle, this.valeur, {this.icone, super.key});

  final String libelle;
  final String valeur;
  final IconData? icone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 9),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (icone != null) ...<Widget>[
            Padding(
              // Cale l'icône sur la première ligne du libellé plutôt que sur le
              // haut du bloc : sans ce décalage elle flotte au-dessus.
              padding: const EdgeInsets.only(top: 1),
              child: Icon(icone,
                  size: 18, color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(width: 12),
          ],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  libelle,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
                const SizedBox(height: 3),
                Text(
                  valeur,
                  style: theme.textTheme.bodyLarge
                      ?.copyWith(fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Choix parmi une liste, présenté en feuille glissante.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI PAS `DropdownButton`.
///
/// Le menu déroulant de Material ouvre un rectangle gris flottant, sans rapport
/// avec le reste de l'écran, posé par-dessus le contenu et coupé aux bords de
/// la fenêtre. Il ne prend ni les coins, ni les couleurs, ni la typographie du
/// thème, et sur iPhone il détonne franchement.
///
/// La feuille glissante est ce qu'emploient aujourd'hui iOS ET Android : elle
/// monte du bas, à portée du pouce, prend toute la largeur — donc de la place
/// pour des libellés entiers — et se referme d'un glissement.
///
/// ⚠️ À EMPLOYER POUR TOUT NOUVEAU CHOIX. Un `DropdownButton` ajouté plus tard
/// ramènerait la fenêtre grise, et l'écart ne se verrait qu'à l'usage.
///
/// En dessous de trois options, préférer `SegmentedButton` : tout afficher vaut
/// mieux que faire ouvrir quoi que ce soit.
class ChoixFer<T> extends StatelessWidget {
  const ChoixFer({
    required this.libelle,
    required this.valeur,
    required this.options,
    required this.texteDe,
    required this.surChangement,
    this.titreFeuille,
    super.key,
  });

  final String libelle;
  final T? valeur;
  final List<T> options;
  final String Function(T) texteDe;
  final ValueChanged<T>? surChangement;

  /// Titre de la feuille. Reprend [libelle] par défaut.
  final String? titreFeuille;

  Future<void> _ouvrir(BuildContext context) async {
    final theme = Theme.of(context);
    final choisi = await showModalBottomSheet<T>(
      context: context,
      backgroundColor: theme.colorScheme.surfaceContainerLow,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
      ),
      // Une liste longue doit pouvoir défiler sans occuper tout l'écran d'un
      // coup : la feuille s'adapte à son contenu.
      isScrollControlled: true,
      builder: (c) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.fromLTRB(marge, 0, marge, 8),
              child: Text(titreFeuille ?? libelle,
                  style: theme.textTheme.titleMedium),
            ),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.only(bottom: 12),
                children: <Widget>[
                  for (final o in options)
                    ListTile(
                      contentPadding:
                          const EdgeInsets.symmetric(horizontal: marge),
                      title: Text(texteDe(o)),
                      // La coche marque le choix courant : sans elle, on rouvre
                      // la feuille juste pour vérifier ce qui est retenu.
                      trailing: o == valeur
                          ? Icon(Icons.check, color: theme.colorScheme.primary)
                          : null,
                      onTap: () => Navigator.pop(c, o),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
    if (choisi != null) surChangement?.call(choisi);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final actif = surChangement != null && options.isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(libelle,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
        const SizedBox(height: 8),
        Material(
          color: theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(14),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: actif ? () => _ouvrir(context) : null,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      valeur == null ? 'Choisir…' : texteDe(valeur as T),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyLarge?.copyWith(
                        color: valeur == null
                            ? theme.colorScheme.onSurfaceVariant
                            : theme.colorScheme.onSurface,
                      ),
                    ),
                  ),
                  Icon(Icons.keyboard_arrow_down,
                      color: theme.colorScheme.onSurfaceVariant),
                ],
              ),
            ),
          ),
        ),
      ],
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
    final theme = Theme.of(context);
    final c = couleur ?? theme.colorScheme.onSurfaceVariant;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
      decoration: BoxDecoration(
        color: Color.alphaBlend(c.withAlpha(31), theme.colorScheme.surface),
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
            style:
                TextStyle(color: c, fontSize: 12, fontWeight: FontWeight.w700),
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
            Icon(icone, size: 44, color: theme.colorScheme.outlineVariant),
            const SizedBox(height: 20),
            Text(titre,
                style: theme.textTheme.titleMedium, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text(
              explication,
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            if (action != null) ...<Widget>[
              const SizedBox(height: 24),
              action!,
            ],
          ],
        ),
      ),
    );
  }
}
