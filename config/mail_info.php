<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Information importante - Forbach en Rose</title>
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
            background: linear-gradient(135deg, #ff4f9c 0%, #e03f8a 100%);
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

        .main-message {
            text-align: center;
            margin-bottom: 30px;
        }

        .main-message h2 {
            color: #e03f8a;
            font-size: 1.8rem;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .main-message p {
            font-size: 1.1rem;
            color: #666;
            line-height: 1.7;
        }

        /* Sections d'informations modulaires */
        .info-section {
            background: linear-gradient(135deg, #ffe1f0 0%, #fff 100%);
            border-radius: 15px;
            padding: 25px;
            margin: 25px 0;
            border-left: 5px solid #ff4f9c;
        }

        .info-section h3 {
            color: #e03f8a;
            font-size: 1.3rem;
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .info-section h3 .emoji {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        .info-section p {
            margin-bottom: 15px;
            line-height: 1.7;
        }

        .info-section p:last-child {
            margin-bottom: 0;
        }

        /* Liste d'informations */
        .info-list {
            list-style: none;
            margin: 15px 0;
        }

        .info-list li {
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
            line-height: 1.6;
        }

        .info-list li::before {
            content: "♥";
            position: absolute;
            left: 0;
            color: #ff4f9c;
            font-weight: bold;
        }

        /* Encadré important */
        .important-notice {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 15px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }

        .important-notice .notice-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .important-notice h3 {
            color: #856404;
            font-size: 1.2rem;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .important-notice p {
            color: #856404;
            font-weight: 500;
        }

        /* Encadré urgence */
        .urgent-notice {
            background: #f8d7da;
            border: 2px solid #dc3545;
            border-radius: 15px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }

        .urgent-notice .notice-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .urgent-notice h3 {
            color: #721c24;
            font-size: 1.2rem;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .urgent-notice p {
            color: #721c24;
            font-weight: 500;
        }

        /* Boutons d'action */
        .action-buttons {
            text-align: center;
            margin: 30px 0;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ff4f9c 0%, #e03f8a 100%);
            color: white;
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 25px;
            display: inline-block;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 5px 15px rgba(255, 79, 156, 0.3);
            margin: 5px 10px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 79, 156, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 25px;
            display: inline-block;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
            margin: 5px 10px;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4);
        }

        /* Carte de contact */
        .contact-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin: 25px 0;
            text-align: center;
        }

        .contact-card h3 {
            color: #e03f8a;
            font-size: 1.3rem;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .contact-info {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 15px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            color: #666;
        }

        .contact-item svg {
            width: 20px;
            height: 20px;
            margin-right: 8px;
            fill: #ff4f9c;
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

        /* Message de motivation */
        .motivation-message {
            text-align: center;
            margin: 30px 0;
            padding: 25px;
            background: #ffe1f0;
            border-radius: 15px;
            border: 2px solid #ff4f9c;
        }

        .motivation-message p {
            font-size: 1.1rem;
            color: #e03f8a;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .motivation-message p:last-child {
            color: #666;
            font-weight: normal;
            font-size: 1rem;
            margin-bottom: 0;
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
            
            .info-section {
                padding: 20px;
            }
            
            .contact-info {
                flex-direction: column;
                gap: 10px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn-primary,
            .btn-secondary {
                margin: 5px 0;
                width: 100%;
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
            <!-- Message principal - À personnaliser -->
            <div class="main-message">
                <h2>📢 <?= $mailTitle ?></h2>
            </div>

            <!-- Section d'information 3 - Modulaire -->
            <div class="info-section">
                <p><?= $description ?></p>
            </div>

            <!-- Boutons d'action (optionnels) -->
            <div class="action-buttons">
                <a href="[LIEN_ACTION_1]" class="btn-primary">[TEXTE_BOUTON_1]</a>
                <!-- <a href="[LIEN_ACTION_2]" class="btn-secondary">[TEXTE_BOUTON_2]</a> -->
            </div>

            <!-- Carte de contact -->
            <div class="contact-card">
                <h3>💬 Besoin d'aide ?</h3>
                <p>Notre équipe est là pour répondre à toutes vos questions.</p>
                <div class="contact-info">
                    <div class="contact-item">
                        <svg viewBox="0 0 24 24">
                            <path d="M20 4H4C2.9 4 2.01 4.9 2.01 6L2 18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM20 8L12 13L4 8V6L12 11L20 6V8Z"/>
                        </svg>
                        <span>contact@forbachenrose.fr</span>
                    </div>
                    <div class="contact-item">
                        <svg viewBox="0 0 24 24">
                            <path d="M6.62 10.79C8.06 13.62 10.38 15.94 13.21 17.38L15.41 15.18C15.69 14.9 16.08 14.82 16.43 14.93C17.55 15.3 18.75 15.5 20 15.5C20.55 15.5 21 15.95 21 16.5V20C21 20.55 20.55 21 20 21C10.61 21 3 13.39 3 4C3 3.45 3.45 3 4 3H7.5C8.05 3 8.5 3.45 8.5 4C8.5 5.25 8.7 6.45 9.07 7.57C9.18 7.92 9.1 8.31 8.82 8.59L6.62 10.79Z"/>
                        </svg>
                        <span>03 XX XX XX XX</span>
                    </div>
                </div>
            </div>

            <!-- Message de motivation -->
            <div class="motivation-message">
                <p>🎗️ Ensemble, courons pour la recherche contre le cancer du sein</p>
                <p>Merci de votre participation et de votre engagement pour cette belle cause !</p>
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
                    Vous recevez cet email car vous êtes inscrit(e) à notre événement.
                </p>
            </div>
        </div>
    </div>
</body>
</html>