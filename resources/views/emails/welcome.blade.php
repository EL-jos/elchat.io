<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('assets/emails/css/welcome.css') }}">
</head>

<body>
<div id="el-page-container">
    <div id="el-card-container">
        <div class="el-card-header">
            <h2 class="el-title">Bienvenue sur Quiz 🎉</h2>
        </div>
        <div class="el-card-body">
            <p><strong>Bonjour {{ \Illuminate\Support\Str::title($user->firstname) }},</strong></p>
            <p>
                Nous sommes ravis de vous accueillir sur <strong>Quiz</strong>, la plateforme
                d’évaluation des compétences techniques conçue pour aider les candidats à se positionner
                efficacement face aux exigences réelles des offres d’emploi tech.
            </p>
            <div class="el-container-info">
                <div class="el-info-item">
                    <p>Name</p>
                    <span>{{ \Illuminate\Support\Str::title($user->firstname) . ' ' . \Illuminate\Support\Str::upper($user->lastname) }}</span>
                </div>
                <div class="el-info-item">
                    <p>E-mail</p>
                    <span>{{ $user->email }}</span>
                </div>
                <div class="el-info-item">
                    <p>Date d'inscription</p>
                    <span>{{ $user->created_at->format('d/m/Y') }}</span>
                </div>
                {{--<div class="el-info-item">
                    <p>Status</p>
                    <span>{{ \Illuminate\Support\Str::title($user->role->name) }}</span>
                </div>--}}
            </div>
            <p>
                Votre compte est désormais actif et vous pouvez accéder à l’ensemble des fonctionnalités
                de la plateforme.
            </p>
            <div class="el-container-features">
                <div class="el-feature-item">
                    <div class="el-icon">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <span>Authentification sécurisée et gestion du mot de passe</span>
                </div>
                <div class="el-feature-item">
                    <div class="el-icon">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <span>Passer des tests techniques par compétence selon l’offre d’emploi choisie</span>
                </div>
                <div class="el-feature-item">
                    <div class="el-icon">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <span>Obtenir un score clair et objectif basé sur vos performances réelles</span>
                </div>
                <div class="el-feature-item">
                    <div class="el-icon">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <span>Partager automatiquement vos résultats avec les recruteurs</span>
                </div>
            </div>
            <p>
                Quiz vous permet de valoriser vos compétences techniques, de gagner en crédibilité
                et d’augmenter vos chances de réussite lors de vos candidatures.
            </p>
            <h3 class="el-best-regards">Cordialement,</h3>
            <h3>Team Quiz</h3>
        </div>
        <div class="el-card-footer">
            <div class="el-divider"></div>
            <p>&copy; 2025 Quiz. All rights reserved.</p>
            <div class="el-container">
                <a href="https://www.facebook.com/"><i class="fa-brands fa-facebook-f"></i></a>
                <a href="https://www.linkedin.com/"><i class="fa-brands fa-linkedin-in"></i></a>
                <a href="https://www.instagram.com/"><i class="fa-brands fa-instagram"></i></a>
                <a href="https://www.youtube.com/"><i class="fa-brands fa-youtube"></i></a>
            </div>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/js/all.min.js"></script>
</body>

</html>
