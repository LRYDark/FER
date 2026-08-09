/// « Mon compte » — profil, transferts, appareils, déconnexion.
library;

import 'package:flutter/material.dart';

import '../../api/api_erreur.dart';
import '../../models/modeles.dart';
import '../portee.dart';
import '../theme.dart';
import 'modifier_compte.dart';
import 'profil_physique.dart';
import 'transfert.dart';

class EcranCompte extends StatelessWidget {
  const EcranCompte({super.key});

  @override
  Widget build(BuildContext context) {
    final session = PorteeSession.de(context);
    final profil = session.profil;

    return RefreshIndicator(
      onRefresh: session.rafraichir,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(marge, marge, marge, margeBasListe),
        children: <Widget>[
          CarteFer(
            titre: 'Mon compte',
            icone: Icons.person_outline,
            // ⚠️ UN FILET, PAS UN APLAT — même parti que « Mes résultats ».
            //
            // Cet écran empile cinq blocs indépendants : identité, transferts,
            // appareils, suivi GPS, profil physique. Séparés par le seul vide,
            // on ne voyait plus où finissait l'un et où commençait le suivant,
            // et les boutons semblaient flotter entre deux rubriques.
            //
            // `fond: true` a été écarté ici comme ailleurs : `surfaceContainerLow`
            // dérive de l'accent et donne, sur le rose du projet, un aplat qui
            // délave le contenu sans le délimiter.
            contour: true,
            // ⚠️ L'APPLICATION NE FAISAIT QU'AFFICHER. Corriger une faute dans
            // son nom ou changer d'adresse obligeait à sortir de l'application
            // et à ouvrir le site — pour des champs que le serveur sait
            // modifier depuis le premier jour.
            action: IconButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                    builder: (_) => const EcranModifierCompte()),
              ),
              icon: const Icon(Icons.edit_outlined),
              tooltip: 'Modifier mes informations',
            ),
            enfant: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(profil?.nomComplet ?? '—',
                    style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(profil?.email ?? '',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: Theme.of(context).colorScheme.outline)),
              ],
            ),
          ),
          const SizedBox(height: marge),
          const _CarteTransferts(),
          const SizedBox(height: marge),
          const _CarteAppareils(),
          const SizedBox(height: marge),
          const _CarteConsentementGps(),
          const SizedBox(height: marge),

          // ⚠️ CET ÉCRAN N'ÉTAIT ATTEIGNABLE QUE DEPUIS LE SUIVI DE COURSE —
          // c'est-à-dire pendant la course, et seulement si le chronométrage
          // était ouvert. Le poids qu'on y saisit sert pourtant à estimer les
          // calories des courses PASSÉES : il fallait pouvoir le renseigner en
          // dehors, et le corriger quand il change.
          CarteFer(
            titre: 'Mon profil physique',
            icone: Icons.monitor_weight_outlined,
            contour: true,
            action: IconButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                    builder: (_) => const EcranProfilPhysique()),
              ),
              icon: const Icon(Icons.edit_outlined),
              tooltip: 'Modifier',
            ),
            enfant: Text(
              'Votre poids sert à estimer les calories dépensées. Il reste sur '
              "cet appareil : il n'est jamais envoyé au serveur, et "
              "l'organisation n'y a pas accès.",
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          const SizedBox(height: marge),
          // ⚠️ PLUS DE CARTE « RAPPEL AVANT LA COURSE » ICI.
          //
          // Elle expliquait un mécanisme au lieu de rendre un service : onze
          // mois sur douze elle affichait « aucun rappel, l'heure de départ
          // n'est pas encore publiée », ce qui n'appelle aucune action.
          //
          // Le rappel LUI-MÊME n'est pas supprimé — il reste posé par
          // `Reveil.reprogrammer()` à chaque lecture de la configuration, et
          // c'est l'organisation qui en décide l'heure. Ce qui disparaît, c'est
          // seulement son bulletin de santé dans les réglages : l'information
          // utile arrive par les messages, là où le coureur regarde déjà.
          OutlinedButton.icon(
            onPressed: () => _confirmerDeconnexion(context),
            icon: const Icon(Icons.logout),
            label: const Text('Me déconnecter'),
          ),
        ],
      ),
    );
  }

  static Future<void> _confirmerDeconnexion(BuildContext context) async {
    final session = PorteeSession.action(context);
    // ⚠️ La confirmation dit ce qui va être PERDU. Se déconnecter pendant une
    // course purge la file d'envoi : les données d'un compte ne doivent pas
    // repartir sous l'identité du suivant.
    final enAttente =
        session.file.detectionsEnAttente + session.file.pointsEnAttente;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Se déconnecter ?'),
        content: Text(
          enAttente > 0
              ? "$enAttente donnée(s) de course n'ont pas encore été envoyées. "
                  'Elles seront perdues. Attendez le retour du réseau si vous '
                  'venez de courir.'
              : 'Vous devrez saisir un nouveau code pour revenir.',
        ),
        actions: <Widget>[
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Annuler')),
          FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Me déconnecter')),
        ],
      ),
    );
    if (ok ?? false) await session.deconnexion();
  }
}

/* ═══════════════════════════ Transferts ═══════════════════════════════ */

/// Transférer une inscription à quelqu'un d'autre — exactement ce que fait le
/// site, avec le même double accord : le destinataire doit confirmer par un
/// code reçu par mail. Sans cela, on pourrait faire disparaître l'inscription
/// de quelqu'un en saisissant une adresse au hasard.
class _CarteTransferts extends StatefulWidget {
  const _CarteTransferts();

  @override
  State<_CarteTransferts> createState() => _CarteTransfertsState();
}

class _CarteTransfertsState extends State<_CarteTransferts> {
  Future<List<Transfert>>? _liste;

  @override
  void initState() {
    super.initState();
    _recharger();
  }

  void _recharger() {
    _liste = PorteeSession.action(context)
        .api
        .transferts()
        .then((l) => l.map(Transfert.depuisJson).toList());
  }

  /// ⚠️ UN ÉCRAN, PLUS UNE FENÊTRE. Le transfert se déclenche aussi depuis la
  /// fiche d'une inscription : enfermé dans une fenêtre privée de cet écran, il
  /// aurait fallu le réécrire là-bas — et les deux copies auraient divergé.
  Future<void> _nouveau() async {
    if (PorteeSession.action(context).inscriptions.isEmpty) return;
    final envoye = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(builder: (_) => const EcranTransfert()),
    );
    if ((envoye ?? false) && mounted) setState(_recharger);
  }

  Future<void> _annuler(int id) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      await PorteeSession.action(context).api.annulerTransfert(id);
      messenger.showSnackBar(
          const SnackBar(content: Text('Demande annulée.')));
      if (mounted) setState(_recharger);
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return CarteFer(
      titre: "Transférer une inscription",
      icone: Icons.swap_horiz,
      contour: true,
      action: IconButton(
        onPressed: _nouveau,
        icon: const Icon(Icons.add),
        tooltip: 'Nouvelle demande',
      ),
      enfant: FutureBuilder<List<Transfert>>(
        future: _liste,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          final l = snap.data ?? const <Transfert>[];
          if (l.isEmpty) {
            return Text(
              'Vous pouvez céder une inscription à quelqu\'un d\'autre : elle '
              'passera sur son adresse email, avec son espace et son '
              'chronométrage. La personne doit accepter depuis le mail '
              "qu'elle recevra.",
              style: theme.textTheme.bodyMedium,
            );
          }
          return Column(
            children: <Widget>[
              for (final t in l)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('n° ${t.inscriptionNo} → ${t.emailCible ?? '?'}'),
                  subtitle: Text('Édition ${t.annee} · ${t.statut}'),
                  trailing: t.enAttente
                      ? IconButton(
                          icon: const Icon(Icons.close),
                          tooltip: 'Annuler',
                          onPressed: () => _annuler(t.id),
                        )
                      : null,
                ),
            ],
          );
        },
      ),
    );
  }
}

/* ═══════════════════════════ Appareils ════════════════════════════════ */

class _CarteAppareils extends StatefulWidget {
  const _CarteAppareils();

  @override
  State<_CarteAppareils> createState() => _CarteAppareilsState();
}

class _CarteAppareilsState extends State<_CarteAppareils> {
  Future<List<Appareil>>? _liste;

  @override
  void initState() {
    super.initState();
    _recharger();
  }

  void _recharger() {
    _liste = PorteeSession.action(context)
        .api
        .appareils()
        .then((l) => l.map(Appareil.depuisJson).toList());
  }

  @override
  Widget build(BuildContext context) => CarteFer(
        titre: 'Mes appareils',
        icone: Icons.devices_outlined,
        contour: true,
        enfant: FutureBuilder<List<Appareil>>(
          future: _liste,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            final l = snap.data ?? const <Appareil>[];
            return Column(
              children: <Widget>[
                for (final a in l)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(a.courant ? Icons.smartphone : Icons.devices),
                    title: Text(a.nom),
                    subtitle: Text(a.plateforme ?? '—'),
                    // ⚠️ L'appareil COURANT ne se révoque pas d'ici : on se
                    // déconnecterait soi-même sans l'avoir voulu. Le bouton
                    // « Me déconnecter » est là pour ça, et il prévient.
                    trailing: a.courant
                        ? const Pastille('cet appareil', couleur: Colors.green)
                        : IconButton(
                            icon: const Icon(Icons.link_off),
                            tooltip: 'Révoquer',
                            onPressed: () async {
                              final m = ScaffoldMessenger.of(context);
                              try {
                                await PorteeSession.action(context)
                                    .api
                                    .revoquerAppareil(a.id);
                                if (mounted) setState(_recharger);
                              } on ApiErreur catch (e) {
                                m.showSnackBar(
                                    SnackBar(content: Text(e.message)));
                              }
                            },
                          ),
                  ),
              ],
            );
          },
        ),
      );
}

/* ═══════════════════════ Suivi GPS — un RÉGLAGE ════════════════════════════
 *
 * ⚠️ DEUX AUTORISATIONS DIFFÉRENTES, QU'IL NE FAUT PAS CONFONDRE.
 *
 *   1. L'autorisation de POSITION du téléphone — demandée à la présentation du
 *      premier lancement. Elle décide si l'application peut LIRE votre position.
 *      Elle appartient à iOS / Android, et se change dans leurs Réglages.
 *
 *   2. Ce consentement-ci. Il décide si le serveur peut CONSERVER le tracé de
 *      votre course. Il appartient au compte, il est le même sur tous vos
 *      appareils, et c'est lui que le RGPD encadre.
 *
 * On peut parfaitement accorder la première et refuser le second : le suivi
 * fonctionne alors sur l'appareil — chrono, distance, allure, calories — sans
 * qu'aucun tracé ne parte. C'est pourquoi la présentation du premier lancement
 * NE demande PAS celui-ci : au premier écran, personne n'a encore de course à
 * enregistrer, et un consentement arraché dans ces conditions n'en est pas un.
 * ══════════════════════════════════════════════════════════════════════════ */

class _CarteConsentementGps extends StatefulWidget {
  const _CarteConsentementGps();

  @override
  State<_CarteConsentementGps> createState() => _CarteConsentementGpsState();
}

class _CarteConsentementGpsState extends State<_CarteConsentementGps> {
  bool _occupe = false;

  /// Réponse du serveur au dernier basculement.
  ///
  /// ⚠️ On ne s'en sert que TANT QUE le profil n'a pas été rechargé : il fait
  /// foi. Sans cet état local, le bouton reviendrait à sa position d'avant
  /// pendant l'aller-retour réseau, et donnerait l'impression que le
  /// basculement a échoué.
  bool? _optimiste;

  /// Demande confirmation AVANT de retirer, jamais avant d'accorder.
  ///
  /// ⚠️ L'ASYMÉTRIE EST VOULUE. Accorder ne casse rien et se défait en un
  /// geste : y ajouter une question ne protégerait personne et découragerait
  /// juste l'accord. Retirer, lui, arrête l'enregistrement — et si c'est fait
  /// par erreur le matin de la course, on ne s'en aperçoit qu'en cherchant sa
  /// carte le soir, quand il est trop tard pour refaire le parcours.
  ///
  /// La question dit CE QUI S'ARRÊTE et CE QUI CONTINUE. Un « Êtes-vous sûr ? »
  /// nu ne fait que déplacer la responsabilité sans rien apprendre.
  Future<void> _confirmerRetrait() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text("Retirer l'autorisation ?"),
        content: const Text(
          "Le chemin suivi pendant vos prochaines courses ne sera plus "
          'enregistré, et vous n\'aurez pas de carte de parcours.\n\n'
          'Votre temps continue d\'être mesuré normalement, et vos résultats '
          'restent accessibles.\n\n'
          "Le retrait vaut pour l'avenir : les traces déjà enregistrées ne "
          'sont pas effacées. Vous pouvez réautoriser à tout moment.',
        ),
        actions: <Widget>[
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Annuler')),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(c).colorScheme.error,
              foregroundColor: Theme.of(c).colorScheme.onError,
            ),
            child: const Text('Retirer'),
          ),
        ],
      ),
    );
    if (ok ?? false) await _basculer(false);
  }

  /// ⚠️ SUPPRESSION DÉFINITIVE : LA CONFIRMATION EST OBLIGATOIRE.
  ///
  /// Rien ne permet de revenir en arrière — les points sont effacés du serveur,
  /// et l'application ne les a jamais gardés. La question dit donc ce qui part
  /// ET ce qui reste : sans cette précision, beaucoup renonceraient de peur de
  /// perdre leur chrono, ou l'accepteraient en croyant tout effacer.
  Future<void> _confirmerSuppressionTraces() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Supprimer vos tracés GPS ?'),
        content: const Text(
          'Le chemin enregistré de vos courses passées sera effacé du serveur, '
          'définitivement. Vous ne pourrez plus revoir votre parcours sur la '
          'carte.\n\n'
          'Vos temps, vos résultats et vos inscriptions ne sont PAS touchés : '
          'vous restez au classement.',
        ),
        actions: <Widget>[
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Annuler')),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(c).colorScheme.error,
              foregroundColor: Theme.of(c).colorScheme.onError,
            ),
            child: const Text('Supprimer'),
          ),
        ],
      ),
    );
    if (!(ok ?? false)) return;

    setState(() => _occupe = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final n = await PorteeSession.action(context).api.supprimerTraces();
      messenger.showSnackBar(SnackBar(
        content: Text(n == 0
            ? "Aucun tracé enregistré : il n'y avait rien à supprimer."
            : '$n tracé(s) supprimé(s). Vos temps et vos résultats sont conservés.'),
      ));
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  Future<void> _basculer(bool valeur) async {
    setState(() => _occupe = true);
    final session = PorteeSession.action(context);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final r = await session.api.consentementGps(valeur);
      if (mounted) setState(() => _optimiste = r);
      messenger.showSnackBar(SnackBar(
        content: Text(r
            ? 'Suivi GPS autorisé. Vous pouvez le retirer à tout moment.'
            : 'Autorisation retirée. Aucune nouvelle trace ne sera enregistrée.'),
      ));
      // Le profil est la source de vérité : on le relit pour que l'état affiché
      // ne dépende plus de la réponse gardée en mémoire.
      await session.rafraichir();
      if (mounted) setState(() => _optimiste = null);
    } on ApiErreur catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final session = PorteeSession.de(context);
    final jours = session.config?.tracesConservationJours ?? 0;
    final autorise = _optimiste ?? (session.profil?.tracesConsent ?? false);

    return CarteFer(
      titre: 'Suivi GPS pendant la course',
      icone: Icons.place_outlined,
      contour: true,
      action: Pastille(
        autorise ? 'Autorisé' : 'Non autorisé',
        couleur: autorise ? Colors.green : null,
        icone: autorise ? Icons.check_circle_outline : Icons.block,
      ),
      enfant: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            autorise
                ? 'Le chemin que vous suivez pendant la course est enregistré, '
                    'et vous le retrouvez sur la carte de vos résultats.'
                : "Le chemin suivi pendant la course n'est pas enregistré. Votre "
                    'temps, lui, est mesuré normalement.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 14),

          // ⚠️ UN SEUL BOUTON, CELUI QUI CORRESPOND À L'ÉTAT.
          //
          // « Autoriser » et « Retirer » côte à côte laissaient croire que rien
          // n'était décidé, et proposaient de retirer une autorisation jamais
          // donnée. Sur un consentement, un affichage ambigu ne se rattrape pas :
          // il faut qu'on voie d'un coup d'œil ce qui est en vigueur, et que le
          // seul geste offert soit celui qui le change.
          if (autorise)
            OutlinedButton.icon(
              onPressed: _occupe ? null : _confirmerRetrait,
              icon: const Icon(Icons.block, size: 18),
              label: const Text("Retirer l'autorisation"),
            )
          else
            FilledButton.icon(
              onPressed: _occupe ? null : () => _basculer(true),
              icon: const Icon(Icons.check, size: 18),
              label: const Text('Autoriser le suivi GPS'),
            ),

          const SizedBox(height: 14),
          // ⚠️ LE DROIT À L'EFFACEMENT, ET IL MANQUAIT.
          //
          // Retirer l'autorisation empêche les tracés FUTURS mais laisse les
          // anciens : l'écran le disait honnêtement, et c'était bien le
          // problème — on annonçait une conservation sans offrir aucun moyen
          // d'y mettre fin. Un tracé dit où quelqu'un se trouvait minute par
          // minute ; c'est la donnée la plus intrusive que ce projet détienne.
          TextButton.icon(
            onPressed: _occupe ? null : _confirmerSuppressionTraces,
            icon: const Icon(Icons.delete_outline, size: 18),
            label: const Text('Supprimer mes tracés enregistrés'),
            style: TextButton.styleFrom(
              foregroundColor: theme.colorScheme.error,
              alignment: Alignment.centerLeft,
              padding: EdgeInsets.zero,
            ),
          ),
          const SizedBox(height: 10),
          // Le texte suit le RÉGLAGE : annoncer un effacement qui n'a pas lieu
          // (ou l'inverse) est exactement ce que ce projet s'interdit.
          Text(
            jours > 0
                ? 'Vos temps et vos résultats sont conservés : vous les '
                    'retrouverez ici chaque année. Seul le chemin suivi sur la '
                    'carte est effacé au bout de $jours jours.'
                : "Le retrait vaut pour l'avenir — il n'efface pas les traces "
                    'déjà enregistrées. Vos temps et vos résultats, eux, sont '
                    "conservés d'une année sur l'autre.",
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ],
      ),
    );
  }
}
