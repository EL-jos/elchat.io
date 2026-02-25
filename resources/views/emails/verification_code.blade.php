<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('assets/emails/css/verification-code.css') }}">
</head>

<body>
<div id="el-page-container">
    <div id="el-card-container">
        <div class="el-card-header">
            <h2 class="el-title">Votre code de vérification OTP</h2>
        </div>
        <div class="el-card-body">
            <p><strong>Bonjour, {{ \Illuminate\Support\Str::title($user->firstname) }}</strong></p>

            <p>
                Vous avez initié la création de votre compte sur <strong>Quiz</strong>, la plateforme qui vous permet
                d’évaluer vos compétences techniques en fonction des exigences réelles des offres d’emploi tech.
            </p>

            <p>
                Veuillez utiliser le code de vérification ci-dessous pour confirmer votre adresse email :
            </p>
            <div class="el-container-code">
                @foreach (str_split($code) as $char)
                    <span class="el-code-item">{{ $char }}</span>
                @endforeach
            </div>
            <p>
                Ce code est valable pendant <strong>{{ $minutes }} minutes</strong>.
            </p>

            <p class="el-disable">
                Pour des raisons de sécurité, ne partagez jamais ce code avec qui que ce soit.
            </p>

            <p class="el-disable">
                Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet email en toute sécurité.
            </p>

            <h3 class="el-best-regards">Cordialement,</h3>
            <h3>L’équipe Quiz</h3>

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
