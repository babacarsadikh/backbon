<?php

declare(strict_types=1);

namespace App\Application\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportExporter
{
    public function export(array $report, string $directory, string $basename): array
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le dossier des rapports.');
        }
        $pdf = rtrim($directory, '/') . '/' . $basename . '.pdf';
        $xlsx = rtrim($directory, '/') . '/' . $basename . '.xlsx';
        $this->pdf($report, $pdf);
        $this->excel($report, $xlsx);
        return ['pdf' => $pdf, 'excel' => $xlsx];
    }

    private function pdf(array $report, string $path): void
    {
        $i = $report['indicateurs'];
        $rows = '';
        foreach ($report['livraisons'] as $row) {
            $rows .= '<tr><td>' . $this->e(date('H:i', strtotime((string)$row['heure_depart']))) . '</td><td>' . $this->e($row['bon_reference']) . '</td><td>' . $this->e($row['commande_reference']) . '</td><td>' . $this->e($row['client']) . '</td><td>' . $this->e($row['chantier'] ?: '—') . '</td><td>' . $this->e($row['chauffeur'] ?: '—') . '</td><td class="n">' . number_format((float)$row['quantite_chargee'], 2, ',', ' ') . '</td></tr>';
        }
        $date = substr($report['periode']['debut'], 0, 10);
        $logoPath = dirname(__DIR__, 3) . '/public/assets/logobeton.png';
        $logo = is_file($logoPath) ? '<img class="logo" src="data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) . '">' : '';
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
          @page{margin:22mm 10mm 16mm}body{font-family:DejaVu Sans;font-size:9px;color:#25252f}.logo{position:absolute;left:0;top:-7mm;width:32mm}h1{text-align:center;color:#27279c;font-size:18px;margin:0 0 4px}.date{text-align:center;margin-bottom:14px}.kpis{width:100%;border-collapse:separate;border-spacing:5px}.kpis td{background:#f0f2ff;border:1px solid #d9dcff;padding:8px;text-align:center}.kpis strong{display:block;font-size:14px;color:#27279c}table.data{width:100%;border-collapse:collapse;margin-top:12px;table-layout:fixed}.data th{background:#27279c;color:#fff;padding:6px}.data td{border:1px solid #ddd;padding:5px;word-wrap:break-word}.data .n{text-align:right}.total{font-weight:bold;background:#eef0ff}footer{position:fixed;bottom:-10mm;width:100%;text-align:center;color:#777;font-size:8px}</style></head><body>
          ' . $logo . '<h1>RAPPORT JOURNALIER DES LIVRAISONS</h1><div class="date">Date : ' . $this->e($date) . '</div>
          <table class="kpis"><tr><td>Commandes<strong>' . $i['nombre_commandes'] . '</strong></td><td>Livraisons<strong>' . $i['nombre_livraisons'] . '</strong></td><td>Clients<strong>' . $i['nombre_clients'] . '</strong></td><td>Livré<strong>' . number_format((float)$i['quantite_livree'],2,',',' ') . ' m³</strong></td><td>Restant<strong>' . number_format((float)$i['quantite_restante'],2,',',' ') . ' m³</strong></td></tr></table>
          <table class="data"><thead><tr><th style="width:6%">Heure</th><th style="width:12%">Bon</th><th style="width:13%">Commande</th><th style="width:19%">Client</th><th style="width:18%">Chantier</th><th style="width:20%">Chauffeur</th><th style="width:8%">m³</th></tr></thead><tbody>' . $rows . '<tr class="total"><td colspan="6">TOTAL GÉNÉRAL</td><td class="n">' . number_format((float)$i['quantite_livree'],2,',',' ') . '</td></tr></tbody></table><footer>DISTRICO BETON — Rapport généré automatiquement</footer></body></html>';
        $options = new Options(); $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options); $dompdf->loadHtml($html); $dompdf->setPaper('A4', 'landscape'); $dompdf->render();
        $canvas = $dompdf->getCanvas(); $canvas->page_text(760, 565, 'Page {PAGE_NUM}/{PAGE_COUNT}', null, 8, [0.4,0.4,0.4]);
        file_put_contents($path, $dompdf->output());
    }

    private function excel(array $report, string $path): void
    {
        $book = new Spreadsheet(); $sheet = $book->getActiveSheet(); $sheet->setTitle('Livraisons');
        $headers = ['Heure','N° bon','Commande','Client','Chantier','Chauffeur','Quantité livrée (m³)'];
        $sheet->fromArray($headers, null, 'A1'); $sheet->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF27279C');
        $sheet->freezePane('A2'); $sheet->setAutoFilter('A1:G1');
        $line = 2; foreach ($report['livraisons'] as $row) { $sheet->fromArray([date('H:i',strtotime((string)$row['heure_depart'])),$row['bon_reference'],$row['commande_reference'],$row['client'],$row['chantier'],$row['chauffeur'],(float)$row['quantite_chargee']],null,'A'.$line++); }
        $sheet->setCellValue('F'.$line, 'TOTAL'); $sheet->setCellValue('G'.$line, (float)$report['indicateurs']['quantite_livree']); $sheet->getStyle('F'.$line.':G'.$line)->getFont()->setBold(true);
        foreach (range('A','G') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        $sheet->getStyle('G2:G'.$line)->getNumberFormat()->setFormatCode('#,##0.00');
        $summary = $book->createSheet(); $summary->setTitle('Synthèse'); $summary->fromArray([['Indicateur','Valeur'],['Commandes',$report['indicateurs']['nombre_commandes']],['Livraisons',$report['indicateurs']['nombre_livraisons']],['Clients',$report['indicateurs']['nombre_clients']],['Quantité commandée',$report['indicateurs']['quantite_commandee']],['Quantité livrée',$report['indicateurs']['quantite_livree']],['Quantité restante',$report['indicateurs']['quantite_restante']]],null,'A1');
        $summary->getStyle('A1:B1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $summary->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF27279C');
        $summary->getColumnDimension('A')->setWidth(26); $summary->getColumnDimension('B')->setWidth(18);
        $summary->getStyle('B5:B7')->getNumberFormat()->setFormatCode('#,##0.00');
        (new Xlsx($book))->save($path); $book->disconnectWorksheets();
    }

    private function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
