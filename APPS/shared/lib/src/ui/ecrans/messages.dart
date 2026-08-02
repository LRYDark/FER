import 'package:flutter/material.dart';

import '../../models/course_app.dart';
import '../portee.dart';
import '../theme.dart';

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

class EcranMessages extends StatelessWidget {
  const EcranMessages({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final messages = session.notifications;

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
                  'changements de dernière minute — apparaîtront ici.',
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
        separatorBuilder: (_, __) => const Divider(height: 1, indent: 72),
        itemBuilder: (context, i) => _LigneMessage(
          message: messages[i],
          nonLu: !session.messageLu(messages[i].id),
        ),
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
      // Pastille de couleur avec l'icône du type : c'est ce qui distingue une
      // consigne de course d'une information générale, avant même de lire.
      leading: Stack(
        children: <Widget>[
          CircleAvatar(
            // ignore: deprecated_member_use
            backgroundColor: couleur.withOpacity(0.15),
            child: Icon(iconeDe(message.type), color: couleur, size: 20),
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
        maxLines: 2,
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
        padding: const EdgeInsets.fromLTRB(marge, 0, marge, marge),
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
