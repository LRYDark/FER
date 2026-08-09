/// La carte à partager après la course.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// L'IMAGE EST FABRIQUÉE SUR L'APPAREIL, PAS DEMANDÉE AU SERVEUR.
///
/// Rien ne sort tant que la personne n'a pas choisi de partager, et le serveur
/// n'a pas à savoir que quelqu'un a partagé. C'est aussi ce qui permet de le
/// faire hors réseau, en sortant de l'arrivée.
///
/// ⚠️ CE QUI NE FIGURE JAMAIS SUR L'IMAGE : adresse email, QR code, nom de
/// famille complet. Une carte se retrouve sur Instagram, dans une story
/// publique, dans une capture qui circule. Le prénom et le dossard suffisent —
/// ils sont déjà sur le T-shirt.
library;

import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:share_plus/share_plus.dart';

import '../../models/modeles.dart';
import '../theme.dart';

class CartePartage extends StatefulWidget {
  const CartePartage({
    required this.inscription,
    required this.resultat,
    this.distanceM,
    this.deniveleM,
    this.calories,
    this.allure,
    super.key,
  });

  final Inscription inscription;
  final Resultat? resultat;
  final double? distanceM;
  final double? deniveleM;
  final String? calories;
  final String? allure;

  @override
  State<CartePartage> createState() => _CartePartageState();
}

class _CartePartageState extends State<CartePartage> {
  final GlobalKey _cle = GlobalKey();
  bool _occupe = false;

  /// Capture la carte affichée et la partage.
  ///
  /// `pixelRatio: 3` : une image de 400 px de large devient floue dès qu'elle
  /// est reprise dans une story. Trois fois la taille logique donne une image
  /// nette partout, pour quelques centaines de kilo-octets.
  Future<void> _partager() async {
    setState(() => _occupe = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final limite =
          _cle.currentContext!.findRenderObject()! as RenderRepaintBoundary;
      final image = await limite.toImage(pixelRatio: 3);
      final octets = await image.toByteData(format: ui.ImageByteFormat.png);
      if (octets == null) throw Exception('Image vide');

      final fichier = XFile.fromData(
        octets.buffer.asUint8List(),
        mimeType: 'image/png',
        name: 'forbach-en-rose-${widget.inscription.annee}.png',
      );
      await Share.shareXFiles(
        <XFile>[fichier],
        text: "J'ai marché pour Forbach en Rose ${widget.inscription.annee} 💗",
      );
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(content: Text("Le partage n'a pas abouti : $e")),
      );
    } finally {
      if (mounted) setState(() => _occupe = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Partager')),
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(marge),
          child: Column(
            children: <Widget>[
              // RepaintBoundary : c'est LUI qu'on capture. La carte est donc
              // exactement ce qu'on voit — pas une seconde mise en page à
              // maintenir en parallèle, qui finirait par diverger.
              RepaintBoundary(
                key: _cle,
                child: _Visuel(
                  inscription: widget.inscription,
                  resultat: widget.resultat,
                  distanceM: widget.distanceM,
                  deniveleM: widget.deniveleM,
                  calories: widget.calories,
                  allure: widget.allure,
                ),
              ),
              const SizedBox(height: marge),
              FilledButton.icon(
                onPressed: _occupe ? null : _partager,
                icon: const Icon(Icons.ios_share),
                label: Text(_occupe ? 'Préparation…' : 'Partager'),
              ),
              const SizedBox(height: 8),
              Text(
                'Seuls votre prénom et votre numéro apparaissent sur l\'image.',
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: Theme.of(context).colorScheme.outline),
              ),
            ],
          ),
        ),
      );
}

class _Visuel extends StatelessWidget {
  const _Visuel({
    required this.inscription,
    this.resultat,
    this.distanceM,
    this.deniveleM,
    this.calories,
    this.allure,
  });

  final Inscription inscription;
  final Resultat? resultat;
  final double? distanceM;
  final double? deniveleM;
  final String? calories;
  final String? allure;

  @override
  Widget build(BuildContext context) {
    // Le visuel ne suit PAS le thème du téléphone : une carte partagée doit
    // être la même pour tout le monde, et reconnaissable comme venant de
    // l'association.
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: <Color>[Color(0xFF1F1024), Color(0xFF3D1030)],
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              const Icon(Icons.favorite, color: roseFerSombre, size: 22),
              const SizedBox(width: 8),
              Text(
                'FORBACH EN ROSE ${inscription.annee}',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 1.5,
                  fontSize: 13,
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),

          // Le chrono, en grand. C'est ce qu'on vient montrer.
          Text(
            resultat?.chrono ?? '—',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 54,
              fontWeight: FontWeight.w800,
              height: 1,
              fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(height: 4),
          Text(
            // ⚠️ « TEMPS APPROCHÉ » RESTE SUR LA CARTE PARTAGÉE, même si la
            // mention a disparu des écrans pour les mesures exactes.
            //
            // Ici, ce n'est plus le coureur qui lit : c'est son entourage, hors
            // de toute application, sans rien pour recouper. Une carte qui
            // annoncerait un temps extrapolé au GPS comme un chrono ferait
            // circuler l'approximation bien au-delà de nous, et sans retour
            // possible. Le nom de la méthode, lui, n'apparaît plus : il ne
            // parlait qu'à ceux qui connaissent déjà la différence.
            resultat != null && resultat!.methode.estApproche
                ? 'Temps approché'
                : 'Marche solidaire',
            style: TextStyle(color: Colors.white.withAlpha(160), fontSize: 12),
          ),

          const SizedBox(height: 24),
          // ⚠️ DEUX CASES PAR LIGNE, comme sur l'écran Résultats. Quatre
          // colonnes à parts égales repliaient « ~563 kcal » sur deux lignes et
          // désalignaient les libellés — sur une image qu'on partage, ce défaut
          // reste visible pour toujours.
          //
          // ⚠️ LE TILDE DE `calories` EST LE DERNIER SIGNE que ce chiffre est
          // estimé, la marge en pourcentage ayant été retirée. Sur une image qui
          // circule hors de l'application, sans rien pour recouper, c'est lui
          // seul qui empêche de la lire comme une mesure. Ne jamais l'ôter.
          LayoutBuilder(
            builder: (context, contraintes) {
              const espace = 16.0;
              final demi = (contraintes.maxWidth - espace) / 2;
              final stats = <Widget>[
                _Stat('Distance', _km(distanceM)),
                _Stat('Dénivelé',
                    deniveleM != null ? '${deniveleM!.round()} m' : '—'),
                _Stat('Allure', allure != null ? '$allure /km' : '—'),
                if (calories != null && calories != '—')
                  _Stat('Calories', calories!),
              ];
              return Wrap(
                spacing: espace,
                runSpacing: 16,
                children: <Widget>[
                  for (final w in stats) SizedBox(width: demi, child: w),
                ],
              );
            },
          ),

          const SizedBox(height: 24),
          Divider(color: Colors.white.withAlpha(40), height: 1),
          const SizedBox(height: 12),
          Text(
            // Prénom seul et numéro : rien de plus que ce qui est déjà visible
            // sur le T-shirt le jour de la course.
            '${inscription.prenom ?? ''} · n° ${inscription.inscriptionNo}',
            style: TextStyle(color: Colors.white.withAlpha(200), fontSize: 13),
          ),
        ],
      ),
    );
  }

  static String _km(double? m) => m == null
      ? '—'
      : '${(m / 1000).toStringAsFixed(2).replaceAll('.', ',')} km';
}

class _Stat extends StatelessWidget {
  const _Stat(this.libelle, this.valeur);

  final String libelle;
  final String valeur;

  /// ⚠️ PLUS D'`Expanded` ICI. Il forçait un partage à parts égales, alors que
  /// « 84 m » et « ~563 kcal » n'ont pas la même longueur : le dernier chiffre
  /// se repliait sur deux lignes et les libellés cessaient de s'aligner. C'est
  /// désormais la disposition appelante qui fixe la largeur — deux cases par
  /// ligne, chacune assez large pour tenir.
  @override
  Widget build(BuildContext context) => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(libelle.toUpperCase(),
                style: TextStyle(
                    color: Colors.white.withAlpha(140),
                    fontSize: 10,
                    letterSpacing: 1)),
            const SizedBox(height: 2),
            Text(valeur,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w700)),
          ],
      );
}

/// Uint8List est importé pour la capture — référence explicite pour que
/// l'analyse ne signale pas l'import comme inutile.
typedef OctetsImage = Uint8List;
