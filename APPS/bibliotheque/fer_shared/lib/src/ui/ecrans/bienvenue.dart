/// Présentation du premier lancement : ce que fait l'application, puis les
/// autorisations qu'elle va demander — et seulement ensuite, les demandes.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// POURQUOI ANNONCER AVANT DE DEMANDER
///
/// Une boîte de dialogue système arrive sans contexte : « Autoriser l'accès à
/// votre position ? » devant un écran de connexion vide ne dit ni pourquoi, ni
/// quand, ni ce qui se passe si l'on refuse. Le réflexe est de refuser — et sur
/// iOS la question n'est POSÉE QU'UNE FOIS. Un refus au premier lancement est
/// donc définitif jusqu'à ce que quelqu'un aille le corriger dans les Réglages,
/// ce que personne ne fait. Le coureur découvre le problème le matin de la
/// course, quand son passage sur la ligne n'est pas détecté.
///
/// D'où ces deux pages : la première dit à quoi sert l'application, la seconde
/// annonce chaque autorisation et ce qu'elle sert à faire. Les demandes système
/// ne partent qu'après, au bouton « Continuer ».
///
/// ═════════════════════════════════════════════════════════════════════════════
/// CE QUE CET ÉCRAN NE FAIT PAS
///
/// Il ne bloque rien. « Plus tard » passe la présentation sans rien demander :
/// l'application marche sans aucune autorisation — on peut consulter son
/// dossard, ses inscriptions et ses résultats. Seuls le suivi de course et les
/// rappels ont besoin de plus, et ils le redemanderont au moment utile.
///
/// Il ne mémorise AUCUNE réponse. Ce qui est retenu, c'est uniquement que
/// l'explication a été montrée ; l'état réel des autorisations appartient au
/// système, et lui seul en est la source de vérité.
library;

import 'package:flutter/material.dart';

import '../portee.dart';

class EcranBienvenue extends StatefulWidget {
  const EcranBienvenue({super.key});

  @override
  State<EcranBienvenue> createState() => _EcranBienvenueState();
}

class _EcranBienvenueState extends State<EcranBienvenue> {
  final _pages = PageController();
  int _page = 0;
  bool _enCours = false;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  Future<void> _terminer({required bool avecAutorisations}) async {
    if (_enCours) return;
    setState(() => _enCours = true);

    final session = PorteeSession.action(context);
    await session.terminerBienvenue(
      position: avecAutorisations,
      notifications: avecAutorisations,
    );
    // `mounted` : la session prévient ses écouteurs, et le routeur remplace cet
    // écran pendant l'await. Toucher au State après coup lèverait une exception.
    if (mounted) setState(() => _enCours = false);
  }

  @override
  Widget build(BuildContext context) {
    final couleurs = Theme.of(context).colorScheme;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: <Widget>[
            Expanded(
              child: PageView(
                controller: _pages,
                onPageChanged: (i) => setState(() => _page = i),
                children: const <Widget>[_PagePresentation(), _PageAutorisations()],
              ),
            ),

            // Deux points, pas une barre de progression : on veut qu'on voie
            // d'un coup d'œil qu'il n'y a que deux écrans à passer.
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List<Widget>.generate(2, (i) {
                final actif = i == _page;
                return AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  margin: const EdgeInsets.symmetric(horizontal: 4),
                  width: actif ? 22 : 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: actif ? couleurs.primary : couleurs.outlineVariant,
                    borderRadius: BorderRadius.circular(4),
                  ),
                );
              }),
            ),
            const SizedBox(height: 20),

            Padding(
              padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
              child: _page == 0 ? _boutonsPage1() : _boutonsPage2(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _boutonsPage1() => FilledButton(
        onPressed: () => _pages.nextPage(
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        ),
        child: const Text('Suivant'),
      );

  Widget _boutonsPage2() => Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          FilledButton(
            onPressed: _enCours ? null : () => _terminer(avecAutorisations: true),
            child: _enCours
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Continuer'),
          ),
          const SizedBox(height: 4),
          TextButton(
            onPressed: _enCours ? null : () => _terminer(avecAutorisations: false),
            child: const Text('Plus tard'),
          ),
        ],
      );
}

/* ═══════════════════════════ Page 1 — l'application ════════════════════════ */

class _PagePresentation extends StatelessWidget {
  const _PagePresentation();

  @override
  Widget build(BuildContext context) {
    final couleurs = Theme.of(context).colorScheme;
    final textes = Theme.of(context).textTheme;

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 32, 24, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Center(
            child: Icon(Icons.favorite, size: 64, color: couleurs.primary),
          ),
          const SizedBox(height: 24),
          Text('Bienvenue', style: textes.headlineMedium),
          const SizedBox(height: 8),
          Text(
            'Forbach en Rose — votre espace coureur.',
            style: textes.titleMedium?.copyWith(color: couleurs.onSurfaceVariant),
          ),
          const SizedBox(height: 28),

          const _Ligne(
            icone: Icons.confirmation_number_outlined,
            titre: 'Vos inscriptions et votre dossard',
            texte: 'Toutes vos inscriptions, avec le QR code de votre dossard — '
                'le même que celui du mail de confirmation.',
          ),
          const _Ligne(
            icone: Icons.timer_outlined,
            titre: 'Votre course, en direct',
            texte: 'Chrono, distance, allure et passage des lignes de départ et '
                "d'arrivée. Le chrono reste visible écran verrouillé.",
          ),
          const _Ligne(
            icone: Icons.emoji_events_outlined,
            titre: 'Vos résultats',
            texte: 'Votre temps, la méthode de mesure et sa précision — toujours '
                'annoncés ensemble. Et une carte à partager.',
          ),
          const _Ligne(
            icone: Icons.campaign_outlined,
            titre: "Les messages de l'organisation",
            texte: 'Les informations importantes vous parviennent directement, '
                'avant et pendant la course.',
          ),

          const SizedBox(height: 8),
          // ⚠️ Dit dès la première page, et pas en petits caractères : une
          // application de course qui laisse croire qu'elle chronomètre
          // officiellement produit des contestations le jour du classement.
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: couleurs.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Icon(Icons.info_outline, size: 20, color: couleurs.onSurfaceVariant),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    "L'application n'établit aucun temps officiel : elle transmet "
                    "vos passages, et c'est l'organisation qui arbitre.",
                    style: textes.bodySmall
                        ?.copyWith(color: couleurs.onSurfaceVariant),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/* ═════════════════════════ Page 2 — les autorisations ══════════════════════ */

class _PageAutorisations extends StatelessWidget {
  const _PageAutorisations();

  @override
  Widget build(BuildContext context) {
    final couleurs = Theme.of(context).colorScheme;
    final textes = Theme.of(context).textTheme;

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 32, 24, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Center(
            child: Icon(Icons.shield_outlined, size: 64, color: couleurs.primary),
          ),
          const SizedBox(height: 24),
          Text('Avant de commencer', style: textes.headlineMedium),
          const SizedBox(height: 8),
          Text(
            'Votre téléphone va vous poser quelques questions. Voici à quoi '
            'chacune sert.',
            style: textes.titleMedium?.copyWith(color: couleurs.onSurfaceVariant),
          ),
          const SizedBox(height: 28),

          const _Ligne(
            icone: Icons.location_on_outlined,
            titre: 'Position',
            texte: 'Pour détecter votre passage sur les lignes de départ et '
                "d'arrivée et mesurer votre parcours. Uniquement pendant la "
                'course — jamais application fermée.',
          ),
          const _Ligne(
            icone: Icons.notifications_active_outlined,
            titre: 'Notifications',
            texte: "Pour le rappel avant le départ et les messages de "
                "l'organisation. C'est aussi ce qui affiche votre chrono sur "
                "l'écran verrouillé.",
          ),
          const _Ligne(
            icone: Icons.bluetooth,
            titre: 'Bluetooth',
            texte: 'Pour détecter la balise posée sur les lignes. Elle donne la '
                'mesure la plus précise ; sans elle, seul le GPS est utilisé. '
                'Demandé au début de votre course.',
          ),

          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: couleurs.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Icon(Icons.lock_outline, size: 20, color: couleurs.onSurfaceVariant),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Vous pouvez tout refuser : l\'application reste utilisable '
                    'pour vos inscriptions, votre dossard et vos résultats. '
                    'Votre poids, s\'il est renseigné, ne quitte jamais le '
                    'téléphone.',
                    style: textes.bodySmall
                        ?.copyWith(color: couleurs.onSurfaceVariant),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/* ═══════════════════════════════ Commun ════════════════════════════════════ */

class _Ligne extends StatelessWidget {
  const _Ligne({required this.icone, required this.titre, required this.texte});

  final IconData icone;
  final String titre;
  final String texte;

  @override
  Widget build(BuildContext context) {
    final couleurs = Theme.of(context).colorScheme;
    final textes = Theme.of(context).textTheme;

    return Padding(
      padding: const EdgeInsets.only(bottom: 22),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(icone, color: couleurs.primary),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(titre, style: textes.titleSmall),
                const SizedBox(height: 3),
                Text(
                  texte,
                  style: textes.bodyMedium
                      ?.copyWith(color: couleurs.onSurfaceVariant),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
