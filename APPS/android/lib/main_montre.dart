import 'package:fer_shared/fer_shared.dart';
import 'package:flutter/material.dart';

import 'ecran_montre.dart';

/// Point d'entrée **Wear OS** — le SECOND de ce projet.
///
/// ═════════════════════════════════════════════════════════════════════════════
/// UN SEUL PROJET, DEUX APPLICATIONS
///
/// Le téléphone démarre sur `lib/main.dart`, la montre sur ce fichier. Même
/// dossier, même `pubspec.yaml`, même cœur — seuls les écrans changent.
///
///     flutter run                                    → téléphone
///     flutter run -t lib/main_montre.dart            → montre
///     flutter build appbundle                        → paquet téléphone
///     flutter build appbundle -t lib/main_montre.dart --flavor wear
///
/// ⚠️ DEUX PAQUETS DISTINCTS, PAS UN SEUL. Une application Wear OS autonome doit
/// déclarer `<uses-feature android:name="android.hardware.type.watch"
/// android:required="true"/>`, ce qui l'EXCLUT des téléphones. Un unique
/// paquet ne peut donc pas servir les deux — d'où la variante `wear`, décrite
/// dans README/04-wear-os.md.
///
/// La session, le client API, la file d'attente hors réseau et le suivi de
/// course viennent tels quels de `package:fer_shared`. Seuls les écrans
/// changent : quatre onglets sur un cadran de 45 mm seraient illisibles.
///
/// ⚠️ PAS DE RAPPEL LOCAL ICI. Le réveil avant la course est posé par le
/// téléphone, qui est l'appareil qu'on regarde la veille au soir. En poser un
/// second sur la montre ferait sonner deux fois pour la même chose.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final session = await Session.ouvrir(
    urlParDefaut: 'https://jr.zerobug-57.fr/FER/api/mobile',
    // RappelIndisponible par défaut : la montre ne prétend pas programmer un
    // rappel qu'elle ne pose pas.
  );

  runApp(AppMontre(session: session));
}

class AppMontre extends StatelessWidget {
  const AppMontre({required this.session, super.key});

  final Session session;

  @override
  Widget build(BuildContext context) => PorteeSession(
        session: session,
        child: MaterialApp(
          title: 'Forbach en Rose',
          debugShowCheckedModeBanner: false,
          // ⚠️ THÈME SOMBRE IMPOSÉ, et ce n'est pas une préférence esthétique.
          // Les écrans OLED des montres n'allument que les pixels non noirs :
          // un fond clair vide la batterie bien avant l'arrivée.
          theme: themeFer(luminosite: Brightness.dark),
          home: const EcranMontre(),
        ),
      );
}
