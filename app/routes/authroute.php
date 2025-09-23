<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

return function (Group $group) {
    // Route de connexion
    $group->post('/login', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation des champs obligatoires
        if (empty($params['login']) || empty($params['mot_de_passe'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Login et mot de passe sont obligatoires'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            // Recherche de l'opérateur par login
            $stmt = $pdo->prepare("
                SELECT id, nom, prenom, login, mot_de_passe, role, actif 
                FROM operateurs 
                WHERE login = ? AND actif = TRUE
            ");
            $stmt->execute([$params['login']]);
            $operateur = $stmt->fetch(PDO::FETCH_ASSOC);

            // Vérification de l'existence et du mot de passe
            if (!$operateur || !password_verify($params['mot_de_passe'], $operateur['mot_de_passe'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Login ou mot de passe incorrect'
                ]));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }

            // Génération du token JWT
            $issuedAt = time();
            $expirationTime = $issuedAt + 3600; // valide pour 1 heure
            $payload = [
                'iat' => $issuedAt,
                'exp' => $expirationTime,
                'data' => [
                    'id' => $operateur['id'],
                    'nom' => $operateur['nom'],
                    'prenom' => $operateur['prenom'],
                    'login' => $operateur['login'],
                    'role' => $operateur['role']
                ]
            ];

            $jwtSecret = $_ENV['JWT_SECRET'] ?? 'votre_cle_secrete_par_defaut';
            $jwt = JWT::encode($payload, $jwtSecret, 'HS256');

            // Réponse de succès
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Connexion réussie',
                'token' => $jwt,
                'user' => [
                    'id' => $operateur['id'],
                    'nom' => $operateur['nom'],
                    'prenom' => $operateur['prenom'],
                    'login' => $operateur['login'],
                    'role' => $operateur['role']
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Erreur lors de la connexion: ' . $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Route de vérification du token (optionnelle)
    $group->post('/verify', function (Request $request, Response $response) {
        $token = $request->getHeaderLine('Authorization');
        
        if (empty($token)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Token manquant'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        try {
            $jwtSecret = $_ENV['JWT_SECRET'] ?? 'votre_cle_secrete_par_defaut';
            $decoded = JWT::decode(str_replace('Bearer ', '', $token), new Key($jwtSecret, 'HS256'));
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Token valide',
                'user' => $decoded->data
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Token invalide: ' . $e->getMessage()
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    });

    // Route de déconnexion (optionnelle)
    $group->post('/logout', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });
    // Ajouter un nouvel opérateur
    $group->post('/operateurs', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation simple
        if (empty($params['nom']) || empty($params['login']) || empty($params['mot_de_passe'])) {
            $response->getBody()->write(json_encode(['error' => 'Nom, login et mot de passe sont obligatoires']));
            return $response->withStatus(400);
        }

        try {
            // Hacher le mot de passe
            $hashedPassword = password_hash($params['mot_de_passe'], PASSWORD_DEFAULT);

            // Insertion
            $stmt = $pdo->prepare("
                INSERT INTO operateurs (nom, prenom, login, mot_de_passe, email, telephone, role) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $params['nom'],
                $params['prenom'] ?? null,
                $params['login'],
                $hashedPassword,
                $params['email'] ?? null,
                $params['telephone'] ?? null,
                $params['role'] ?? 'operateur'
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Opérateur créé avec succès',
                'id' => $pdo->lastInsertId()
            ]));
            return $response->withStatus(201);

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la création']));
            return $response->withStatus(500);
        }
    });

    // Lister tous les opérateurs
    $group->get('/operateurs', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);

        try {
            $stmt = $pdo->query("
                SELECT id, nom, prenom, login, email, telephone, role, actif, date_creation 
                FROM operateurs 
                ORDER BY nom
            ");
            $operateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode($operateurs));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur de récupération']));
            return $response->withStatus(500);
        }
    });
};