/// Coquille de l'application connectée.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UNE SEULE MISE EN PAGE, DEUX FORMATS — ET LE MÊME CONTENU.
///
/// Sous 720 px (téléphone, montre en mode étendu) : barre de navigation en bas,
/// à portée du pouce. Au-delà (tablette, iPad) : rail latéral, qui laisse toute
/// la largeur au contenu.
///
/// ⚠️ AUCUNE FONCTION N'EST RETIRÉE SUR PETIT ÉCRAN. Une tablette et un
/// téléphone donnent accès aux mêmes choses : c'est la disposition qui change,
/// pas ce qu'on peut faire. Un écran « allégé » finit toujours par manquer de
/// ce qu'on cherche précisément ce jour-là.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../../models/course_app.dart';
import '../portee.dart';
import '../theme.dart';
import 'compte.dart';
import 'inscriptions.dart';
import 'messages.dart';
import 'resultats.dart';

class EcranAccueil extends StatefulWidget {
  const EcranAccueil({super.key});

  @override
  State<EcranAccueil> createState() => _EcranAccueilState();
}

class _EcranAccueilState extends State<EcranAccueil>
    with WidgetsBindingObserver {
  int _onglet = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Retour au premier plan : on relit les messages de l'organisation et la
    // configuration. C'est le mécanisme qui remplace le push — sans lui, une
    // consigne écrite ce matin n'arriverait jamais.
    if (state == AppLifecycleState.resumed) {
      final session = PorteeSession.action(context);
      session.rafraichirConfig();
      session.rafraichirNotifications();
      session.file.vidange();
    }
  }

  /// Change d'onglet, et relit les messages en arrivant sur le leur.
  ///
  /// ⚠️ SANS CELA, IL FALLAIT REDÉMARRER L'APPLICATION POUR VOIR UN MESSAGE.
  /// La liste n'était relue qu'au lancement et au retour au premier plan :
  /// une annonce publiée pendant qu'on garde l'application ouverte n'arrivait
  /// jamais. Le jour de la course, c'est précisément là qu'on la garde ouverte.
  ///
  /// La relecture ne bloque pas l'affichage : l'onglet s'ouvre tout de suite
  /// avec ce qu'on a déjà, et se complète quand la réponse arrive.
  void _allerA(int i) {
    setState(() => _onglet = i);
    final session = PorteeSession.action(context);
    // L'onglet « Résultats » n'existe que si le chronométrage est ouvert :
    // « Messages » glisse donc d'une place selon la période.
    const indexMessages = 2;
    if (i == indexMessages) unawaited(session.rafraichirNotifications());
  }

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final large = MediaQuery.sizeOf(context).width >= 720;

    // ⚠️ IL N'Y A PLUS D'ONGLET « COURSE ».
    //
    // Il faisait doublon : les infos pratiques figurent déjà sur l'inscription,
    // et le suivi n'a de sens que rapporté à un dossard. Une inscription passe
    // par TROIS ÉTATS — à venir, en cours, terminée — et c'est le même objet
    // qu'on ouvre à chaque fois. On ne court pas « dans un onglet », on court
    // SON dossard : le chrono se trouve donc là où on l'a laissé.
    //
    // Le suivi vit maintenant dans un écran ouvert depuis l'inscription
    // (`EcranSuivi`), pas dans la barre du bas.
    //
    // « Résultats » reste, mais devient une ARCHIVE : les éditions passées, que
    // l'inscription en cours ne montre pas. Le temps du jour, lui, s'affiche
    // sur l'inscription elle-même.
    final nonLus = session.messagesNonLus;

    /* ⚠️ « RÉSULTATS » NE DISPARAÎT PLUS HORS PÉRIODE.
     *
     * Il ne porte plus que des temps : c'est lui qui contient maintenant
     * l'historique complet des éditions passées — dossard, montant payé,
     * taille de T-shirt. Le masquer onze mois sur douze rendrait ces
     * informations introuvables, alors qu'elles servent justement hors saison
     * (« combien j'avais payé l'an dernier ? »).
     *
     * L'écran se garde de lui-même : sans chronométrage, il affiche les
     * éditions et leur reçu, sans temps. */
    final pages = <Widget>[
      const EcranInscriptions(),
      const EcranResultats(),
      const EcranMessages(),
      const EcranCompte(),
    ];
    final destinations = <_Destination>[
      const _Destination('Inscriptions', Icons.confirmation_number_outlined,
          Icons.confirmation_number),
      const _Destination('Résultats', Icons.emoji_events_outlined,
          Icons.emoji_events),
      // La pastille remplace les bandeaux : elle signale sans occuper la place
      // du contenu, et c'est un signe que tout le monde sait déjà lire.
      _Destination('Messages', Icons.mail_outline, Icons.mail, badge: nonLus),
      const _Destination('Compte', Icons.person_outline, Icons.person),
    ];

    // Le chronométrage a pu se fermer pendant qu'on était sur son onglet.
    final index = _onglet.clamp(0, pages.length - 1);

    // ⚠️ PLUS DE BARRE DE TITRE SUR LES ONGLETS.
    //
    // Elle répétait ce que la barre du bas dit déjà, en surbrillance : on ne
    // peut pas être sur « Inscriptions » sans le voir. Deux fois la même
    // information, pour une soixantaine de pixels de hauteur perdus sur chaque
    // écran — et sur un téléphone, la hauteur est ce qui manque toujours.
    //
    // `SafeArea` remplace la barre pour écarter le contenu de l'encoche : sans
    // elle, la première ligne passerait sous l'heure et la Dynamic Island.
    //
    // ⚠️ LES ÉCRANS OUVERTS PAR-DESSUS GARDENT LEUR BARRE (`EcranSuivi`,
    // `EcranInscription`) : elle y porte la flèche de retour, qui est le seul
    // moyen d'en sortir. Ce n'est pas le même besoin.
    final corps = SafeArea(
      bottom: false,
      child: Column(
        children: <Widget>[
          const _BandeauErreur(),
          // ⚠️ SEULEMENT SUR INSCRIPTIONS ET MESSAGES.
          //
          // Un message épinglé — parking, retrait des dossards — concerne la
          // course. Répété au-dessus des résultats et des réglages, il rognait
          // le haut de CHAQUE écran sans jamais rien y apporter, et se
          // transformait en bandeau publicitaire qu'on finit par ne plus lire.
          //
          // Il reste sur « Inscriptions », l'écran d'accueil de fait, et sur
          // « Messages », où il est chez lui.
          if (destinations[index].libelle == 'Inscriptions' ||
              destinations[index].libelle == 'Messages')
            const _BandeauNotifications(),
          Expanded(child: pages[index]),
        ],
      ),
    );

    if (large) {
      return Scaffold(
        body: Row(
          children: <Widget>[
            NavigationRail(
              selectedIndex: index,
              onDestinationSelected: _allerA,
              labelType: NavigationRailLabelType.all,
              destinations: <NavigationRailDestination>[
                for (final d in destinations)
                  NavigationRailDestination(
                    icon: d.icoineAvecBadge(false),
                    selectedIcon: d.icoineAvecBadge(true),
                    label: Text(d.libelle),
                  ),
              ],
            ),
            const VerticalDivider(width: 1),
            Expanded(child: corps),
          ],
        ),
      );
    }

    return Scaffold(
      body: corps,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: _allerA,
        destinations: <NavigationDestination>[
          for (final d in destinations)
            NavigationDestination(
              icon: d.icoineAvecBadge(false),
              selectedIcon: d.icoineAvecBadge(true),
              label: d.libelle,
            ),
        ],
      ),
    );
  }
}

class _Destination {
  const _Destination(this.libelle, this.icone, this.iconePleine,
      {this.badge = 0});

  final String libelle;
  final IconData icone;
  final IconData iconePleine;

  /// Nombre affiché en pastille, 0 = aucune.
  final int badge;

  Widget icoineAvecBadge(bool selectionnee) {
    final ico = Icon(selectionnee ? iconePleine : icone);
    if (badge <= 0) return ico;
    return Badge(label: Text('$badge'), child: ico);
  }
}

/// La panne, quand il y en a une — en tête de TOUS les onglets.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// ⚠️ CE BANDEAU A MANQUÉ, ET SON ABSENCE A COÛTÉ CHER.
///
/// `Session.erreur` était renseignée à chaque échec de chargement… et n'était
/// affichée QUE par les écrans bloquants (version refusée, API fermée). Sur les
/// écrans ordinaires, rien. Une panne de chargement produisait donc des listes
/// vides silencieuses, impossibles à distinguer d'un compte réellement vide.
///
/// C'est la pire confusion possible ici : « aucune inscription rattachée »
/// envoie le coureur vérifier son adresse d'inscription, alors qu'il fallait
/// simplement réessayer. Deux messages, deux gestes opposés.
///
/// Il vit dans la coquille et non dans un écran : une panne ne concerne pas un
/// onglet, elle concerne la session. Le placer dans chaque écran aurait garanti
/// qu'on l'oublie dans l'un d'eux — c'est exactement ce qui s'était passé.
class _BandeauErreur extends StatelessWidget {
  const _BandeauErreur();

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final message = session.erreur;
    if (message == null) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final couleur = theme.colorScheme.error;

    return Padding(
      padding: const EdgeInsets.fromLTRB(marge, 12, marge, 0),
      child: BlocAccent(
        couleur: couleur,
        icone: Icons.error_outline,
        enfant: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(message, style: theme.textTheme.bodyMedium),
            const SizedBox(height: 6),
            // Une panne sans moyen d'agir n'est qu'un reproche : le bouton fait
            // partie du message.
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: session.chargement ? null : session.rafraichir,
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Réessayer'),
                style: TextButton.styleFrom(
                  foregroundColor: couleur,
                  padding: EdgeInsets.zero,
                  minimumSize: const Size(0, 32),
                  tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Les messages ÉPINGLÉS, en tête de tous les onglets.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// SEULEMENT LES ÉPINGLÉS — LE RESTE VIT DANS L'ONGLET MESSAGES.
///
/// La version précédente empilait ici tous les messages récents : trois annonces
/// et le contenu de l'application disparaissait sous les bandeaux. Ne restent
/// que les épinglés, ceux que l'organisation a explicitement désignés comme
/// « à relire » — rendez-vous, parking, retrait des dossards.
///
/// Ils suivent les dates de publication et de fin comme les autres : ils
/// apparaissent à l'heure dite et disparaissent tout seuls.
class _BandeauNotifications extends StatelessWidget {
  const _BandeauNotifications();

  @override
  Widget build(BuildContext context) {
    final epingles = PorteeSession.de(context)
        .notifications
        // Le serveur ne sert déjà que les messages destinés à l'application :
        // un envoi ponctuel sur les téléphones n'arrive jamais jusqu'ici.
        .where((n) => n.epingle)
        .toList();
    if (epingles.isEmpty) return const SizedBox.shrink();

    return Column(
      // Deux au maximum. Au-delà, on repousserait le contenu hors de l'écran,
      // et personne ne lit le troisième bandeau.
      children: <Widget>[
        for (final n in epingles.take(2)) _Epingle(notification: n),
      ],
    );
  }
}

class _Epingle extends StatelessWidget {
  const _Epingle({required this.notification});

  final NotificationCourse notification;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final couleur = couleurDe(notification.type, theme);

    return Padding(
      padding: const EdgeInsets.fromLTRB(marge, 12, marge, 0),
      // ⚠️ MÊME BLOC QUE PARTOUT AILLEURS. L'ancien bandeau se dessinait à la
      // main — fond à 10 %, bordure à 60 d'alpha, coins à 12 — et détonnait
      // depuis la refonte : il était le dernier morceau d'interface encadré
      // dans une application qui ne l'est plus.
      child: BlocAccent(
        couleur: couleur,
        icone: Icons.push_pin_outlined,
        enfant: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              notification.titre,
              style: theme.textTheme.titleSmall?.copyWith(color: couleur),
            ),
            const SizedBox(height: 2),
            Text(
              notification.message,
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}
