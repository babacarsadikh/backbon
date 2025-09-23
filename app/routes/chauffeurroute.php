<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group) {
    // Lire tous les chauffeurs avec leurs camions
    $group->get('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->query("
            SELECT c.*, cam.plaque_immatriculation, cam.modele, cam.capacite
            FROM chauffeurs c
            LEFT JOIN camions cam ON c.camion_id = cam.id
            ORDER BY c.nom, c.prenom
        ");
        $chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($chauffeurs));
        return $response->withHeader('Content-Type', 'application/json');
    });
 // Chauffeurs disponibles (sans camion assigné ou avec camion disponible)
    $group->get('/disponibles', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->query("
            SELECT c.*, cam.plaque_immatriculation
            FROM chauffeurs c
            LEFT JOIN camions cam ON c.camion_id = cam.id
            WHERE (c.camion_id IS NULL OR cam.etat = 'disponible')
            ORDER BY c.nom, c.prenom
        ");
        $chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($chauffeurs));
        return $response->withHeader('Content-Type', 'application/json');
    });
    // Lire un chauffeur spécifique
    $group->get('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT c.*, cam.plaque_immatriculation, cam.modele, cam.capacite
            FROM chauffeurs c
            LEFT JOIN camions cam ON c.camion_id = cam.id
            WHERE c.id = ?
        ");
        $stmt->execute([$args['id']]);
        $chauffeur = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chauffeur) {
            $response->getBody()->write(json_encode(['error' => 'Chauffeur non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($chauffeur));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ajouter un chauffeur
    $group->post('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation des champs obligatoires
        if (empty($params['nom']) || empty($params['prenom'])) {
            $response->getBody()->write(json_encode(['error' => 'Nom, prénom et téléphone sont obligatoires']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO chauffeurs (nom, prenom, telephone, camion_id) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $params['nom'],
                $params['prenom'],
                $params['telephone'],
                $params['camion_id'] ?? null
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Chauffeur ajouté avec succès',
                'id' => $pdo->lastInsertId()
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de l\'ajout du chauffeur: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Modifier un chauffeur
    $group->put('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Vérifier si le chauffeur existe
        $stmt = $pdo->prepare("SELECT id FROM chauffeurs WHERE id = ?");
        $stmt->execute([$args['id']]);
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Chauffeur non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE chauffeurs 
                SET nom = ?, prenom = ?, telephone = ?, camion_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $params['nom'] ?? '',
                $params['prenom'] ?? '',
                $params['telephone'] ?? '',
                $params['camion_id'] ?? null,
                $args['id']
            ]);

            $response->getBody()->write(json_encode(['message' => 'Chauffeur mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Supprimer un chauffeur
    $group->delete('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);

        try {
            // Vérifier si le chauffeur a des livraisons
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM bons_livraison WHERE chauffeur_id = ?");
            $stmt->execute([$args['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Impossible de supprimer ce chauffeur car il a des livraisons associées'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Supprimer le chauffeur
            $stmt = $pdo->prepare("DELETE FROM chauffeurs WHERE id = ?");
            $stmt->execute([$args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Chauffeur non trouvé']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'Chauffeur supprimé avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la suppression: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Rechercher des chauffeurs
    $group->get('/search/{term}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $term = '%' . $args['term'] . '%';
        
        $stmt = $pdo->prepare("
            SELECT c.*, cam.plaque_immatriculation
            FROM chauffeurs c
            LEFT JOIN camions cam ON c.camion_id = cam.id
            WHERE c.nom LIKE ? OR c.prenom LIKE ? OR c.telephone LIKE ?
            ORDER BY c.nom, c.prenom
        ");
        $stmt->execute([$term, $term, $term]);
        $chauffeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($chauffeurs));
        return $response->withHeader('Content-Type', 'application/json');
    });

   
};