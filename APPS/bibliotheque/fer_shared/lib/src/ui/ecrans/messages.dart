/// Les messages de l'organisation, présentés comme une boîte de réception.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UN MESSAGE, PAS UN BANDEAU.
///
/// La première version empilait des bandeaux colorés en tête de chaque onglet.
/// Trois annonces et le contenu de l'application disparaissait sous les
/// avertissements ; passée la première lecture, l'œil ne les voyait plus.
///
/// Ici on reprend ce que tout le monde sait déjà lire : une **liste de
/// messages**. Titre, extrait, date, pastille sur les non lus. On touche pour
/// ouvrir et lire en entier. C'est la forme d'un mail ou d'une conversation,
/// et elle ne demande aucun apprentissage.
///
/// ⚠️ LES ÉPINGLÉES RESTENT EN HAUT, MÊME LUES. Elles portent les informations
/// pratiques qu'on relit la veille — rendez-vous, parking, dossards. Les faire
/// descendre parce qu'elles ont déjà été ouvertes viderait la page où l'on va
/// justement les rechercher.
library;

import 'package:flutter/material.dart';

import '../../models/course_app.dart';
import '../portee.dart';
import '../theme.dart';

class EcranMessages extends StatelessWidget {
  const EcranMessages({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    // Les messages écartés sur cet appareil ne figurent plus dans la liste.
    final messages = session.messagesVisibles;

    if (messages.isEmpty) {
      return RefreshIndicator(
        onRefresh: session.rafraichirNotifications,
        child: ListView(
          children: <Widget>[
            SizedBox(height: MediaQuery.sizeOf(context).height * 0.15),
            const RienAAfficher(
              icone: Icons.mark_email_read_outlined,
              titre: 'Aucun message',
              explication:
                  "Les informations de l'organisation — horaires, rendez-vous, "
                  'changements de dernière minute — apparaîtront ici. Tirez vers '
                  'le bas pour vérifier.',
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: session.rafraichirNotifications,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(vertical: 8),
        itemCount: messages.length,
        separatorBuilder: (_, __) => const Divider(height: 1, indent: 68),
        itemBuilder: (context, i) {
          final m = messages[i];
          final ligne = _LigneMessage(
            message: m,
            nonLu: !session.messageLu(m.id),
          );

          // ⚠️ LES ÉPINGLÉS NE SE BALAIENT PAS. Ce sont les informations qu'on
          // relit la veille — rendez-vous, parking, retrait des dossards. Le
          // geste est trop facile pour être délibéré à chaque fois, et il
          // viderait la page où l'on va justement les rechercher.
          if (!session.peutMasquer(m)) return ligne;

          return Dismissible(
            key: ValueKey<int>(m.id),
            // De la droite vers la gauche seulement : le glissement inverse
            // sert au retour arrière sur iOS, et les deux se marcheraient dessus.
            direction: DismissDirection.endToStart,
            background: Container(
              alignment: Alignment.centerRight,
              padding: const EdgeInsets.symmetric(horizontal: marge),
              color: Theme.of(context).colorScheme.errorContainer,
              child: Icon(Icons.delete_outline,
                  color: Theme.of(context).colorScheme.onErrorContainer),
            ),
            onDismissed: (_) {
              session.masquerMessage(m.id);
              // ⚠️ « ANNULER » N'EST PAS UN CONFORT. Un balayage part tout seul
              // en faisant défiler ; sans retour en arrière, une consigne de
              // course disparaîtrait sur un geste que personne n'a voulu.
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: const Text('Message retiré de votre boîte.'),
                action: SnackBarAction(
                  label: 'Annuler',
                  onPressed: () => session.demasquerMessage(m.id),
                ),
              ));
            },
            child: ligne,
          );
        },
      ),
    );
  }
}

class _LigneMessage extends StatelessWidget {
  const _LigneMessage({required this.message, required this.nonLu});

  final NotificationCourse message;
  final bool nonLu;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final couleur = couleurDe(message.type, theme);

    return ListTile(
      // ⚠️ LE THÈME MET `horizontal: 4` SUR TOUTES LES LISTES — bien pour des
      // lignes de texte, trop serré ici : la pastille ronde arrivait presque au
      // bord de l'écran, ce qui donne l'impression d'un contenu coupé.
      // ⚠️ RESSERRÉ : deux messages remplissaient l'écran.
      //
      // `vertical: 8` sur un ListTile à trois lignes donnait des rangées de
      // près de 100 px. Sur une boîte de réception, c'est la HAUTEUR qui décide
      // du nombre de messages qu'on embrasse d'un coup d'œil — et un aperçu
      // qu'il faut faire défiler pour compter ses messages ne sert à rien.
      //
      // `dense` réduit aussi l'interligne interne. Le confort de touche reste
      // assuré : la rangée fait encore plus de 60 px de haut, bien au-delà des
      // 44 px recommandés par Apple.
      contentPadding: const EdgeInsets.symmetric(horizontal: marge, vertical: 2),
      dense: true,
      visualDensity: VisualDensity.compact,
      minLeadingWidth: 40,
      // Pastille de couleur avec l'icône du type : c'est ce qui distingue une
      // consigne de course d'une information générale, avant même de lire.
      leading: Stack(
        children: <Widget>[
          CircleAvatar(
            radius: 18,
            // ignore: deprecated_member_use
            backgroundColor: couleur.withOpacity(0.15),
            child: Icon(iconeDe(message.type), color: couleur, size: 18),
          ),
          if (nonLu)
            Positioned(
              right: 0,
              top: 0,
              child: Container(
                width: 11,
                height: 11,
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary,
                  shape: BoxShape.circle,
                  border: Border.all(color: theme.colorScheme.surface, width: 2),
                ),
              ),
            ),
        ],
      ),
      title: Row(
        children: <Widget>[
          if (message.epingle) ...<Widget>[
            Icon(Icons.push_pin, size: 13, color: theme.colorScheme.outline),
            const SizedBox(width: 4),
          ],
          Expanded(
            child: Text(
              message.titre,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              // Le gras porte le « non lu », comme dans une boîte mail. Aucune
              // légende à expliquer : le geste est connu.
              style: TextStyle(
                fontWeight: nonLu ? FontWeight.w700 : FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
      subtitle: Text(
        message.message,
        // Une seule ligne d'aperçu : le message entier s'ouvre d'un appui, et
        // deux lignes tronquées n'en disaient guère plus qu'une.
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: theme.textTheme.bodySmall,
      ),
      trailing: Text(
        dateCourte(message.publieLe),
        style: theme.textTheme.bodySmall
            ?.copyWith(color: theme.colorScheme.outline),
      ),
      isThreeLine: true,
      onTap: () {
        PorteeSession.action(context).marquerLu(message.id);
        showModalBottomSheet<void>(
          context: context,
          showDragHandle: true,
          isScrollControlled: true,
          builder: (_) => _MessageEntier(message: message),
        );
      },
    );
  }
}

/// Le message en entier, ouvert par le bas.
///
/// Une feuille plutôt qu'une page : on lit trois lignes et on referme d'un
/// glissement. Empiler une page de navigation pour cela ferait un aller-retour
/// disproportionné.
class _MessageEntier extends StatelessWidget {
  const _MessageEntier({required this.message});

  final NotificationCourse message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final couleur = couleurDe(message.type, theme);

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(marge, 0, marge, margeBasListe),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                Icon(iconeDe(message.type), color: couleur),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(message.titre,
                      style: theme.textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.w700)),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              dateLongue(message.publieLe),
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.outline),
            ),
            const SizedBox(height: 16),
            Flexible(
              child: SingleChildScrollView(
                child: Text(message.message, style: theme.textTheme.bodyMedium),
              ),
            ),
            if (message.expireLe != null) ...<Widget>[
              const SizedBox(height: 16),
              Text(
                'Ne sera plus affiché après le ${dateCourte(message.expireLe)}.',
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.outline),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/* ═══════════════════════════ Petits outils ════════════════════════════ */

Color couleurDe(TypeNotification t, ThemeData theme) => switch (t) {
      TypeNotification.urgent => theme.colorScheme.error,
      TypeNotification.course => theme.colorScheme.primary,
      TypeNotification.info => theme.colorScheme.tertiary,
    };

IconData iconeDe(TypeNotification t) => switch (t) {
      TypeNotification.urgent => Icons.priority_high,
      TypeNotification.course => Icons.flag_outlined,
      TypeNotification.info => Icons.info_outline,
    };

/// « 14:32 » aujourd'hui, « 03/10 » sinon — la convention des boîtes mail.
String dateCourte(DateTime? d) {
  if (d == null) return '';
  final l = d.toLocal();
  final n = DateTime.now();
  if (l.year == n.year && l.month == n.month && l.day == n.day) {
    return '${l.hour.toString().padLeft(2, '0')}:'
        '${l.minute.toString().padLeft(2, '0')}';
  }
  return '${l.day.toString().padLeft(2, '0')}/'
      '${l.month.toString().padLeft(2, '0')}';
}

String dateLongue(DateTime? d) {
  if (d == null) return '';
  final l = d.toLocal();
  return '${l.day.toString().padLeft(2, '0')}/'
      '${l.month.toString().padLeft(2, '0')}/${l.year} à '
      '${l.hour.toString().padLeft(2, '0')}:'
      '${l.minute.toString().padLeft(2, '0')}';
}
