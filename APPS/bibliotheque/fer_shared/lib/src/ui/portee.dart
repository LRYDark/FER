import 'package:flutter/material.dart';

import '../session.dart';

/// Met la [Session] à disposition de tout l'arbre des widgets.
///
/// `InheritedNotifier` plutôt qu'un paquet d'injection : la session est un
/// `ChangeNotifier`, l'infrastructure existe déjà dans Flutter, et une
/// dépendance de moins est une dépendance de moins à maintenir pendant les
/// années où l'application restera installée sur les téléphones.
class PorteeSession extends InheritedNotifier<Session> {
  const PorteeSession({
    required Session session,
    required super.child,
    super.key,
  }) : super(notifier: session);

  /// La session, avec réabonnement : le widget appelant se reconstruit à chaque
  /// changement. C'est ce qu'on veut presque partout.
  static Session de(BuildContext context) {
    final p = context.dependOnInheritedWidgetOfExactType<PorteeSession>();
    assert(p != null, 'Aucune PorteeSession au-dessus de ce widget.');
    return p!.notifier!;
  }

  /// La session SANS réabonnement. À utiliser dans un `onPressed` : on veut
  /// agir, pas se reconstruire parce qu'on a lu l'objet.
  static Session action(BuildContext context) {
    final p = context.getInheritedWidgetOfExactType<PorteeSession>();
    assert(p != null, 'Aucune PorteeSession au-dessus de ce widget.');
    return p!.notifier!;
  }
}
