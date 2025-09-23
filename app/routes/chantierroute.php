<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group) {
    // Lire tous les chantiers d'un client spécifique
    $group->get('/client/{client_id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT c.*, cl.nom as client_nom, cl.prenom as client_prenom
            FROM chantiers c
            INNER JOIN clients cl ON c.client_id = cl.id
            WHERE c.client_id = ?
            ORDER BY c.nom_chantier
        ");
        $stmt->execute([$args['client_id']]);
        $chantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($chantiers));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Lire un chantier spécifique
    $group->get('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        
        $stmt = $pdo->prepare("
            SELECT c.*, cl.nom as client_nom, cl.prenom as client_prenom
            FROM chantiers c
            INNER JOIN clients cl ON c.client_id = cl.id
            WHERE c.id = ?
        ");
        $stmt->execute([$args['id']]);
        $chantier = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chantier) {
            $response->getBody()->write(json_encode(['error' => 'Chantier non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($chantier));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Ajouter un chantier pour un client
    $group->post('', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Validation des champs obligatoires
        if (empty($params['client_id']) || empty($params['nom_chantier']) || empty($params['adresse'])) {
            $response->getBody()->write(json_encode(['error' => 'Client ID, nom du chantier et adresse sont obligatoires']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO chantiers (client_id, nom_chantier, adresse, ville, code_postal, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $params['client_id'],
                $params['nom_chantier'],
                $params['adresse'],
                $params['ville'] ?? null,
                $params['code_postal'] ?? null,
                $params['notes'] ?? null
            ]);

            $response->getBody()->write(json_encode([
                'message' => 'Chantier ajouté avec succès',
                'id' => $pdo->lastInsertId()
            ]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de l\'ajout du chantier: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Modifier un chantier
    $group->put('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $params = (array) $request->getParsedBody();

        // Vérifier si le chantier existe
        $stmt = $pdo->prepare("SELECT id FROM chantiers WHERE id = ?");
        $stmt->execute([$args['id']]);
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode(['error' => 'Chantier non trouvé']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE chantiers 
                SET nom_chantier = ?, adresse = ?, ville = ?, code_postal = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $params['nom_chantier'] ?? '',
                $params['adresse'] ?? '',
                $params['ville'] ?? null,
                $params['code_postal'] ?? null,
                $params['notes'] ?? null,
                $args['id']
            ]);

            $response->getBody()->write(json_encode(['message' => 'Chantier mis à jour avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la mise à jour: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Supprimer un chantier
    $group->delete('/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);

        try {
            // Vérifier si le chantier a des commandes
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM commandes WHERE chantier_id = ?");
            $stmt->execute([$args['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Impossible de supprimer ce chantier car il a des commandes associées'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Supprimer le chantier
            $stmt = $pdo->prepare("DELETE FROM chantiers WHERE id = ?");
            $stmt->execute([$args['id']]);

            if ($stmt->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Chantier non trouvé']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode(['message' => 'Chantier supprimé avec succès']));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Erreur lors de la suppression: ' . $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    });

    // Rechercher des chantiers par nom ou adresse
    $group->get('/search/{term}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $term = '%' . $args['term'] . '%';
        
        $stmt = $pdo->prepare("
            SELECT c.*, cl.nom as client_nom, cl.prenom as client_prenom
            FROM chantiers c
            INNER JOIN clients cl ON c.client_id = cl.id
            WHERE c.nom_chantier LIKE ? OR c.adresse LIKE ? OR c.ville LIKE ?
            ORDER BY c.nom_chantier
        ");
        $stmt->execute([$term, $term, $term]);
        $chantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($chantiers));
        return $response->withHeader('Content-Type', 'application/json');
    });
};