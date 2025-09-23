<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group) {
    // Lire toutes les formules de béton (actives et inactives)
    $group->get('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->query("
            SELECT * FROM formules_beton 
            ORDER BY nom
        ");
        $formules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($formules));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire seulement les formules actives
    $group->get('/actives', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->query("
            SELECT * FROM formules_beton 
            WHERE actif = TRUE
            ORDER BY nom
        ");
        $formules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($formules));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire une formule spécifique
    $group->get('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT * FROM formules_beton 
            WHERE id = ?
        ");
        $stmt->execute([$args['id']]);
        $formule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$formule) {
            $response->getBody()->write(json_encode(['error' => 'Formule non trouvée']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($formule));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ajouter une formule de béton
    $group->post('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation du champ obligatoire
        if (empty($params['nom'])) {
            $response->getBody()->write(json_encode(['error' => 'Le nom de la formule est obligatoire']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO formules_beton (nom, description, prix_metre_cube, actif) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $params['nom'],
                $params['description'] ?? null,
                $params['prix_metre_cube'] ?? 0.00,
                $params['actif'] ?? true
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Formule de béton ajoutée avec succès',
                'id' => $pdo->lastInsertId()
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de l\'ajout de la formule: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Modifier une formule de béton
    $group->put('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Vérifier si la formule existe
        $stmt = $pdo->prepare("SELECT id FROM formules_beton WHERE id = ?");
        $stmt->execute([$args['id']]);
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Formule non trouvée']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE formules_beton 
                SET nom = ?, description = ?, prix_metre_cube = ?, actif = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $params['nom'] ?? '',
                $params['description'] ?? null,
                $params['prix_metre_cube'] ?? 0.00,
                $params['actif'] ?? true,
                $args['id']
            ]);

            $response->getBody()->write(json_encode(['message' => 'Formule mise à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Activer/désactiver une formule
    $group->patch('/{id}/statut', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        if (!isset($params['actif'])) {
            $response->getBody()->write(json_encode(['error' => 'Le champ actif est obligatoire']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("UPDATE formules_beton SET actif = ? WHERE id = ?");
            $stmt->execute([(bool)$params['actif'], $args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Formule non trouvée']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $statut = $params['actif'] ? 'activée' : 'désactivée';
            $response->getBody()->write(json_encode(['message' => "Formule $statut avec succès"]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Supprimer une formule de béton
    $group->delete('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);

        try {
            // Vérifier si la formule est utilisée dans des commandes
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM commandes WHERE formule_beton_id = ?");
            $stmt->execute([$args['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Impossible de supprimer cette formule car elle est utilisée dans des commandes'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Supprimer la formule
            $stmt = $pdo->prepare("DELETE FROM formules_beton WHERE id = ?");
            $stmt->execute([$args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Formule non trouvée']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'Formule supprimée avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la suppression: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Rechercher des formules
    $group->get('/search/{term}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $term = '%' . $args['term'] . '%';
        
        $stmt = $pdo->prepare("
            SELECT * FROM formules_beton 
            WHERE nom LIKE ? OR description LIKE ?
            ORDER BY nom
        ");
        $stmt->execute([$term, $term]);
        $formules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($formules));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Statistiques des formules (nombre de commandes par formule)
    $group->get('/stats/utilisation', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->query("
            SELECT fb.id, fb.nom, COUNT(cmd.id) as nombre_commandes, 
                   SUM(cmd.quantite_commandee) as total_metres_cubes
            FROM formules_beton fb
            LEFT JOIN commandes cmd ON fb.id = cmd.formule_beton_id
            GROUP BY fb.id, fb.nom
            ORDER BY nombre_commandes DESC, fb.nom
        ");
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($stats));
        return $response->withHeader('Content-Type', 'application/json');
    });
};