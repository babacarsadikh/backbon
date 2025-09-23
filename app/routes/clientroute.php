<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group) {
    // Lire tous les clients
    $group->get('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $stmt = $pdo->query("
            SELECT c.*, 
                   COUNT(ch.id) as nombre_chantiers
            FROM clients c
            LEFT JOIN chantiers ch ON c.id = ch.client_id
            GROUP BY c.id
            ORDER BY c.nom, c.prenom
        ");
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($clients));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire un client par ID avec ses chantiers
    $group->get('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        // Récupérer les infos du client
        $stmt = $pdo->prepare("
            SELECT * FROM clients 
            WHERE id = ?
        ");
        $stmt->execute([$args['id']]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            $response->getBody()->write(json_encode(['error' => 'Client non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Récupérer les chantiers du client
        $stmt = $pdo->prepare("
            SELECT * FROM chantiers 
            WHERE client_id = ?
            ORDER BY nom_chantier
        ");
        $stmt->execute([$args['id']]);
        $chantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $client['chantiers'] = $chantiers;

        $response->getBody()->write(json_encode($client));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ajouter un client
    $group->post('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation des champs obligatoires
        if (empty($params['nom']) ) {
            $response->getBody()->write(json_encode(['error' => 'Nom obligatoire']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $pdo->beginTransaction();

            // Insertion du client
            $stmt = $pdo->prepare("
                INSERT INTO clients (nom, prenom, entreprise, telephone) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $params['nom'],
                $params['prenom'],
                $params['entreprise'] ?? null,
                $params['telephone'] ?? null
            ]);

            $clientId = $pdo->lastInsertId();

            // Si des chantiers sont fournis, les ajouter
            if (!empty($params['chantiers']) && is_array($params['chantiers'])) {
                $stmtChantier = $pdo->prepare("
                    INSERT INTO chantiers (client_id, nom_chantier, adresse, ville, code_postal, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($params['chantiers'] as $chantier) {
                    $stmtChantier->execute([
                        $clientId,
                        $chantier['nom_chantier'] ?? 'Chantier principal',
                        $chantier['adresse'] ?? '',
                        $chantier['ville'] ?? null,
                        $chantier['code_postal'] ?? null,
                        $chantier['notes'] ?? null
                    ]);
                }
            }

            $pdo->commit();

            $response->getBody()->write(json_encode([
                'message' => 'Client ajouté avec succès',
                'id' => $clientId
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            //$pdo->rollBack();
            
            // // Vérifier si c'est une violation de contrainte unique (email)
            // if ($e->getCode() == 23000) {
            //     $response->getBody()->write(json_encode(['error' => 'Un client avec cet email existe déjà']));
            //     return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            // }
            
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de l\'ajout du client: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Modifier un client
    $group->put('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Vérifier si le client existe
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ?");
        $stmt->execute([$args['id']]);
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Client non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE clients 
                SET nom = ?, prenom = ?, entreprise = ?, email = ?, telephone = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $params['nom'] ?? '',
                $params['prenom'] ?? '',
                $params['entreprise'] ?? null,
                $params['email'] ?? '',
                $params['telephone'] ?? null,
                $args['id']
            ]);

            $response->getBody()->write(json_encode(['message' => 'Client mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            // Vérifier si c'est une violation de contrainte unique (email)
            if ($e->getCode() == 23000) {
                $response->getBody()->write(json_encode(['error' => 'Un client avec cet email existe déjà']));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
            
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Supprimer un client
    $group->delete('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);

        try {
            // Vérifier si le client a des commandes
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM commandes WHERE client_id = ?");
            $stmt->execute([$args['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Impossible de supprimer ce client car il a des commandes associées'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Supprimer les chantiers associés
            $stmt = $pdo->prepare("DELETE FROM chantiers WHERE client_id = ?");
            $stmt->execute([$args['id']]);

            // Supprimer le client
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            $stmt->execute([$args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Client non trouvé']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'Client supprimé avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la suppression: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Rechercher des clients
    $group->get('/search/{term}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $term = '%' . $args['term'] . '%';
        
        $stmt = $pdo->prepare("
            SELECT * FROM clients 
            WHERE nom LIKE ? OR prenom LIKE ? OR entreprise LIKE ? OR email LIKE ?
            ORDER BY nom, prenom
        ");
        $stmt->execute([$term, $term, $term, $term]);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($clients));
        return $response->withHeader('Content-Type', 'application/json');
    });
};