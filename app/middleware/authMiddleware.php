<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$authMiddleware = function (Request $request, RequestHandler $handler): Response {
    // Exclure les routes publiques (login, etc.)
    $path = $request->getUri()->getPath();
    $publicRoutes = ['/auth/login', '/auth/verify'];
    
    if (in_array($path, $publicRoutes)) {
        return $handler->handle($request);
    }

    $token = $request->getHeaderLine('Authorization');
    
    if (empty($token)) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Token d\'authentification manquant'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    try {
        $jwtSecret = $_ENV['JWT_SECRET'] ?? 'votre_cle_secrete_par_defaut';
        $decoded = JWT::decode(str_replace('Bearer ', '', $token), new Key($jwtSecret, 'HS256'));
        
        // Ajouter les informations utilisateur à la requête
        $request = $request->withAttribute('user', $decoded->data);
        return $handler->handle($request);

    } catch (Exception $e) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Token invalide: ' . $e->getMessage()
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
};