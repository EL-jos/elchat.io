<?php

namespace App\Services;

class MercureService
{
    protected $mercureUrl;
    protected $jwtKey;

    public function __construct()
    {
        $this->mercureUrl = env('MERCURE_URL');
        $this->jwtKey = env('JWT_KEY');
    }

    public function post(string $topic, array $data, ?string $view = null)
    {
        // Si une vue est fournie, on la rend
        if ($view) {
            // Rendre la vue Blade avec les données
            $htmlContent = view($view, $data)->render();
        } else {
            // Si aucune vue n'est fournie, on crée des données JSON
            $htmlContent = json_encode($data);
        }

        // Construire les données à envoyer (topic et contenu HTML ou JSON)
        $postData = http_build_query([
            'topic' => $topic,
            'data' => $htmlContent
        ]);

        // Envoyer la requête au Hub Mercure
        $response = file_get_contents(
            $this->mercureUrl,
            false,
            stream_context_create(
                [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\nAuthorization: Bearer " . $this->jwtKey . "\r\n",
                        'content' => $postData
                    ]
                ]
            )
        );

        return $response;
    }
}
