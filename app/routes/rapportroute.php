<?php

declare(strict_types=1);

use App\Application\Reports\ReportService;
use App\Application\Reports\ReportManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (Group $group): void {
    $json = static function (Response $response, array $payload, int $status = 200): Response {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $group->get('', function (Request $request, Response $response) use ($json) {
        $pdo = $this->get(PDO::class);
        $stmt = $pdo->query('SELECT * FROM rapports ORDER BY date_rapport DESC, generated_at DESC LIMIT 500');
        return $json($response, ['rapports' => $stmt->fetchAll()]);
    });

    $group->get('/apercu', function (Request $request, Response $response) use ($json) {
        try {
            return $json($response, (new ReportService($this->get(PDO::class)))->build($request->getQueryParams()));
        } catch (Throwable $error) {
            return $json($response, ['error' => 'Impossible de calculer le rapport', 'message' => $error->getMessage()], 422);
        }
    });

    $group->get('/kpis', function (Request $request, Response $response) use ($json) {
        $pdo = $this->get(PDO::class);
        $delivery = $pdo->query("SELECT SUM(date_production=CURRENT_DATE) livraisons_aujourdhui,
            COALESCE(SUM(CASE WHEN date_production=CURRENT_DATE THEN quantite_chargee ELSE 0 END),0) quantite_aujourdhui,
            COALESCE(SUM(CASE WHEN date_production>=DATE_FORMAT(CURRENT_DATE,'%Y-%m-01') THEN quantite_chargee ELSE 0 END),0) quantite_mois
            FROM bons_livraison WHERE statut='livre'")->fetch();
        $clients = $pdo->query("SELECT COUNT(DISTINCT cmd.client_id) total FROM bons_livraison bl JOIN commandes cmd ON cmd.id=bl.commande_id WHERE bl.statut='livre' AND bl.date_production=CURRENT_DATE")->fetchColumn();
        $orders = $pdo->query("SELECT SUM(cmd.statut IN ('en_attente','en_cours')) commandes_en_cours,SUM(cmd.statut='livree') commandes_terminees,
            COALESCE(SUM(GREATEST(0,cmd.quantite_commandee-COALESCE(x.livree,0))),0) quantite_restante
            FROM commandes cmd LEFT JOIN (SELECT commande_id,SUM(quantite_chargee) livree FROM bons_livraison WHERE statut='livre' GROUP BY commande_id) x ON x.commande_id=cmd.id WHERE cmd.statut<>'annulee'")->fetch();
        return $json($response, array_merge($delivery, ['clients_servis'=>(int)$clients], $orders));
    });

    $group->post('/generer', function (Request $request, Response $response) use ($json) {
        try {
            $filters = (array)$request->getParsedBody();
            $manager = new ReportManager($this->get(PDO::class), dirname(__DIR__, 2) . '/var/reports');
            return $json($response, $manager->generate($filters, false), 201);
        } catch (Throwable $error) {
            return $json($response, ['error' => 'Échec de la génération', 'message' => $error->getMessage()], 500);
        }
    });

    $group->post('/{id:[0-9]+}/regenerer', function (Request $request, Response $response, array $args) use ($json) {
        $pdo = $this->get(PDO::class); $stmt = $pdo->prepare('SELECT * FROM rapports WHERE id=?'); $stmt->execute([(int)$args['id']]); $old = $stmt->fetch();
        if (!$old) return $json($response, ['error' => 'Rapport introuvable'], 404);
        $filters = json_decode((string)$old['filtres_json'], true) ?: []; $filters['date_debut'] = substr($old['date_debut'],0,10); $filters['date_fin'] = substr($old['date_fin'],0,10);
        return $json($response, (new ReportManager($pdo, dirname(__DIR__, 2) . '/var/reports'))->generate($filters, true));
    });

    $group->get('/{id:[0-9]+}/{format:pdf|excel}', function (Request $request, Response $response, array $args) use ($json) {
        $stmt = $this->get(PDO::class)->prepare('SELECT pdf_path,excel_path FROM rapports WHERE id=?'); $stmt->execute([(int)$args['id']]); $row = $stmt->fetch();
        $path = $row ? $row[$args['format']==='pdf'?'pdf_path':'excel_path'] : null;
        if (!$path || !is_file($path)) return $json($response, ['error' => 'Fichier introuvable'], 404);
        $response->getBody()->write(file_get_contents($path));
        $type = $args['format']==='pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        return $response->withHeader('Content-Type',$type)->withHeader('Content-Disposition','attachment; filename="'.basename($path).'"');
    });

    $group->get('/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($json) {
        $stmt = $this->get(PDO::class)->prepare('SELECT * FROM rapports WHERE id = ?');
        $stmt->execute([(int)$args['id']]);
        $report = $stmt->fetch();
        return $report ? $json($response, $report) : $json($response, ['error' => 'Rapport introuvable'], 404);
    });
};
