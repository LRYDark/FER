<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation d'inscription - Forbach en Rose</title>
    <style>
        /* Reset pour emails */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #ffe1f0 0%, #fff 40%, #ffe1f0 100%);
            padding: 20px;
        }

        /* Container principal */
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* Header avec dégradé rose */
        .email-header {
            background: #ff4f9c;
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            background: linear-gradient(135deg, #ff4f9c 0%,  #e03f8a 100%);
        }

        .email-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0.3;
        }

        .email-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: 1px;
            position: relative;
            z-index: 2;
        }

        .email-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 2;
        }

        /* Contenu principal */
        .email-content {
            padding: 40px 30px;
            background: #f5f5f5ff;
        }

        .welcome-message {
            text-align: center;
            margin-bottom: 30px;
        }

        .welcome-message h2 {
            color:  #e03f8a;
            font-size: 1.8rem;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .welcome-message p {
            font-size: 1.1rem;
            color: #666;
            line-height: 1.7;
        }

        /* Carte d'information */
        .info-card {
            background: linear-gradient(135deg, var(--rose-light) 0%, #fff 100%);
            border-radius: 15px;
            padding: 25px;
            margin: 25px 0;
            border-left: 5px solid #ff4f9c;
        }

        .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px 0;
        }

        .info-item:last-child {
            margin-bottom: 0;
        }

        .info-icon {
            width: 24px;
            height: 24px;
            margin-right: 15px;
            fill: #ff4f9c;
            flex-shrink: 0;
        }

        .info-text {
            flex: 1;
        }

        .info-label {
            font-weight: 600;
            color:  #e03f8a;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1.1rem;
            color: #333;
            margin-top: 2px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ff4f9c 0%,  #e03f8a 100%);
            color: white;
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 25px;
            display: inline-block;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 5px 15px rgba(255, 79, 156, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 79, 156, 0.4);
        }

        /* Section conseils */
        .tips-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin: 25px 0;
        }

        .tips-section h3 {
            color:  #e03f8a;
            font-size: 1.3rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .tips-section h3::before {
            content: "💡";
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .tips-list {
            list-style: none;
        }

        .tips-list li {
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
        }

        /* Footer */
        .email-footer {
            background: #333;
            color: white;
            padding: 30px;
            text-align: center;
        }

        .social-links {
            margin: 20px 0;
        }

        .social-links a {
            display: inline-block;
            margin: 0 10px;
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .social-links a:hover {
            opacity: 1;
        }

        .social-links img {
            width: 30px;
            height: 30px;
        }

        .footer-text {
            font-size: 0.9rem;
            opacity: 0.8;
            line-height: 1.6;
        }

        /* Responsive */
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }
            
            .email-header {
                padding: 30px 20px;
            }
            
            .email-header h1 {
                font-size: 1.8rem;
            }
            
            .email-content {
                padding: 30px 20px;
            }
            
            .info-card {
                padding: 20px;
            }
            
            .info-item {
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
            }
            
            .info-icon {
                margin-bottom: 5px;
                margin-right: 0;
            }
        }

        /* Animation d'entrée */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .email-container {
            animation: fadeInUp 0.8s ease-out;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1><span style="color: #ff69b4; font-size: 1.2em;">🎀</span> Forbach en Rose</h1>
            <p>Course caritative contre le cancer du sein</p>
        </div>

        <!-- Contenu principal -->
        <div class="email-content">
            <div class="welcome-message">
                <h2>✨ Inscription confirmée !</h2>
                <p>Félicitations ! Votre inscription à la course caritative "Forbach en Rose" a été enregistrée avec succès. Merci de vous joindre à nous pour cette belle cause.</p>
            </div>

            <!-- Informations de l'inscription -->
            <div class="info-card">
                <div class="info-item">
                    <svg class="info-icon" viewBox="0 0 24 24">
                        <path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM21 9V7L15 7V9C15 9.55 14.55 10 14 10V22H16V16H20V22H22V10C22 9.45 21.55 9 21 9Z"/>
                    </svg>
                    <div class="info-text">
                        <div class="info-label">Participant</div>
                        <div class="info-value"><?= mb_strtoupper($lastname, 'UTF-8') . ' ' .mb_convert_case($firstname, MB_CASE_TITLE, 'UTF-8') ?></div>
                    </div>
                </div>

                <div class="info-item">
                    <svg class="info-icon" viewBox="0 0 24 24">
                        <path d="M9 11H7V9H9V11ZM13 11H11V9H13V11ZM17 11H15V9H17V11ZM19 3H18V1H16V3H8V1H6V3H5C3.89 3 3 3.9 3 5V19C3 20.1 3.89 21 5 21H19C20.1 21 21 20.1 21 19V5C21 3.9 20.1 3 19 3ZM19 19H5V8H19V19Z"/>
                    </svg>
                    <div class="info-text">
                        <div class="info-label">Date de l'événement</div>
                        <div class="info-value"><?= $date ?></div>
                    </div>
                </div>

                <div class="info-item">
                    <svg class="info-icon" viewBox="0 0 24 24">
                        <path d="M12 2C8.13 2 5 5.13 5 9C5 14.25 12 22 12 22S19 14.25 19 9C19 5.13 15.87 2 12 2ZM12 11.5C10.62 11.5 9.5 10.38 9.5 9S10.62 6.5 12 6.5S14.5 7.62 14.5 9S13.38 11.5 12 11.5Z"/>
                    </svg>
                    <div class="info-text">
                        <div class="info-label">Lieu de départ</div>
                        <div class="info-value">Forbach, Moselle</div>
                    </div>
                </div>

                <!-- <div class="info-item">
                    <svg class="info-icon" viewBox="0 0 24 24">
                        <path d="M20 6H16L14 4H10L8 6H4C2.9 6 2 6.9 2 8V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V8C22 6.9 21.1 6 20 6ZM12 17C9.24 17 7 14.76 7 12S9.24 7 12 7S17 9.24 17 12S14.76 17 12 17ZM12 9C10.34 9 9 10.34 9 12S10.34 15 12 15S15 13.66 15 12S13.66 9 12 9Z"/>
                    </svg>
                    <div class="info-text">
                        <div class="info-label">Numéro de dossard</div>
                        <div class="info-value">#[NUMERO_DOSSARD]</div>
                    </div>
                </div>-->
            </div>

            <!-- Conseils de préparation -->
            <div class="tips-section">
                <h3>Conseils pour le jour J</h3>
                <ul class="tips-list">
                    <li>❤️ Arrivez 30 minutes avant le départ</li>
                    <li>❤️ Portez des vêtements roses pour soutenir la cause</li>
                    <!-- <li>N'oubliez pas votre bouteille d'eau et de la crème solaire</li> -->
                    <li>❤️ Prenez vos chaussures de course les plus confortables</li>
                    <!-- <li>Invitez vos proches à venir vous encourager</li >-->
                </ul>
            </div>

            <div style="text-align: center; margin: 30px 0; padding: 20px; background: var(--rose-light); border-radius: 15px;">
                <p style="font-size: 1.1rem; color:  #e03f8a; font-weight: 600;">
                    🎗️ Ensemble, courons pour la recherche contre le cancer du sein
                </p>
                <p style="margin-top: 10px; color: #666;">
                    Votre participation fait la différence. Merci de votre engagement !
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="email-footer">
            <div class="social-links">
                <a href="[LINK_FACEBOOK]" target="_blank">
                    <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAiIGhlaWdodD0iMzAiIHZpZXdCb3g9IjAgMCAzMCAzMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTUiIGN5PSIxNSIgcj0iMTUiIGZpbGw9IiMzYjU5OTgiLz4KPHA" alt="Facebook">
                </a>
                <a href="[LINK_INSTAGRAM]" target="_blank">
                    <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAiIGhlaWdodD0iMzAiIHZpZXdCb3g9IjAgMCAzMCAzMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGNpcmNsZSBjeD0iMTUiIGN5PSIxNSIgcj0iMTUiIGZpbGw9IiNlNDQwNWYiLz4KPHA" alt="Instagram">
                </a>
            </div>
            
            <div class="footer-text">
                <p><strong>Forbach en Rose</strong></p>
                <p>Course caritative contre le cancer du sein</p>
                <p style="margin-top: 15px; font-size: 0.8rem;">
                    Si vous avez des questions, n'hésitez pas à nous contacter.<br>
                    Email: contact@forbachenrose.fr | Tél: 03 XX XX XX XX
                </p>
                <p style="margin-top: 10px; font-size: 0.7rem; opacity: 0.6;">
                    Vous recevez cet email car vous vous êtes inscrit(e) à notre événement.
                </p>
            </div>
        </div>
    </div>
</body>
</html>