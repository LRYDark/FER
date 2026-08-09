╔══════════════════════════════════════════════════════════════════════════════╗
║  À FUSIONNER dans ios/Podfile APRÈS `flutter create`.                        ║
║                                                                              ║
║  Comme Info-additions.plist, ce fichier n'est pas utilisé tel quel : il dit  ║
║  ce qu'il faut AJOUTER au bloc `post_install` généré par Flutter, et         ║
║  pourquoi. `ios/` n'est pas versionné — sans cette note, le correctif se     ║
║  reperdrait au prochain `flutter create`, et la compilation échouerait sur   ║
║  un message qui ne dit pas ce qu'il faut faire.                              ║
╚══════════════════════════════════════════════════════════════════════════════╝

Le `post_install` généré ressemble à ceci :

    post_install do |installer|
      installer.pods_project.targets.each do |target|
        flutter_additional_ios_build_settings(target)
      end
    end

Ajoutez la boucle sur les configurations :

    post_install do |installer|
      installer.pods_project.targets.each do |target|
        flutter_additional_ios_build_settings(target)

        target.build_configurations.each do |config|
          config.build_settings['ENABLE_MODULE_VERIFIER'] = 'NO'

          if config.build_settings['IPHONEOS_DEPLOYMENT_TARGET'].to_f < 13.0
            config.build_settings['IPHONEOS_DEPLOYMENT_TARGET'] = '13.0'
          end
        end
      end
    end

Puis `cd ios && pod install`.

────────────────────────────────────────────────────────────────────────────────
POURQUOI ENABLE_MODULE_VERIFIER = NO

Le « module verifier », activé par défaut depuis Xcode 15, refuse les en-têtes
parapluie qui incluent en "guillemets" au lieu de <chevrons>. Deux greffons
écrivent encore à l'ancienne :

    flutter_local_notifications (17.x)   device_info_plus (10.x)

et la compilation s'arrête sur :

    error: double-quoted include "ActionEventSink.h" in framework header,
           expected angle-bracketed instead
    → VerifyModule flutter_local_notifications.framework   ÉCHEC

⚠️ CE N'EST PAS UNE ERREUR DE COMPILATION. Le verifier est un contrôle de
conformité des modules pour leurs consommateurs, pas une étape de production du
binaire. Les greffons se compilent et fonctionnent ; c'est le contrôle qui est
plus strict que ce qu'ils savaient produire à l'époque.

Ce sont exactement deux des trois greffons que Flutter signale déjà comme « ne
supportant pas Swift Package Manager » — le même retard, vu par un autre bout.

⚠️ À RETIRER le jour où ces greffons montent de version :
   flutter_local_notifications 19+ et device_info_plus 11+ adoptent SPM et n'ont
   plus le problème. Attention, la 19 change l'API d'initialisation : ce n'est
   pas une montée à faire la veille d'une course.

────────────────────────────────────────────────────────────────────────────────
POURQUOI IPHONEOS_DEPLOYMENT_TARGET À 13.0

Certains pods portent encore une cible 9.0, hors de la plage admise par Xcode 26
(12.0 minimum). Sans cette ligne, chaque compilation produit une pluie
d'avertissements dans lesquels une vraie erreur passe inaperçue.
