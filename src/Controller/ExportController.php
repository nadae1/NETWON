<?php

namespace App\Controller;

use App\Repository\AnalyseResultatRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/export')]
class ExportController extends AbstractController
{
    #[Route('/trafic', name: 'export_trafic_form')]
    public function form(AnalyseResultatRepository $repo): Response
    {
        $service = $this->getCurrentUserService();

        return $this->render('export/trafic.html.twig', [
            'sites' => $repo->findDistinctSiteNames($service),
            'canExportAll' => true,
        ]);
    }

    #[Route('/trafic/generate', name: 'export_trafic_generate', methods: ['POST'])]
    public function generate(Request $request, AnalyseResultatRepository $repo): StreamedResponse
    {
        $service = $this->getCurrentUserService();

        $mode = $request->request->get('export_mode', 'all');
        $sitesRaw = trim((string) $request->request->get('sites', ''));
        $sitesList = array_values(array_filter(array_map('trim', explode(',', $sitesRaw))));
        $siteSearch = trim((string) $request->request->get('site_search', ''));
        $dateRange = $request->request->get('date_range', 'all');
        $dateValue = $request->request->get('date_value');
        $kpis = $request->request->all('kpis') ?? [];

        [$dateFrom, $dateTo] = $this->resolveDateRange($dateRange, $dateValue);

        $data = $repo->findForExport($service, $mode, $sitesList, $siteSearch, $dateFrom, $dateTo);

        return $this->generateExcel($data, $kpis);
    }

    private function getCurrentUserService(): ?string
    {
        if ($this->isGranted('ROLE_SUPERUSER') || $this->isGranted('ROLE_ADMIN')) {
            return null;
        }

        $user = $this->getUser();

        if (!$user) {
            return null;
        }

        if (method_exists($user, 'getService')) {
            return $user->getService();
        }

        return null;
    }

    private function resolveDateRange(string $dateRange, ?string $dateValue): array
    {
        if ($dateRange === 'all' || empty($dateValue)) {
            return [null, null];
        }

        $date = new \DateTime($dateValue);

        return match ($dateRange) {
            'day' => [
                $date->format('Y-m-d'),
                $date->format('Y-m-d')
            ],
            'week' => [
                $date->modify('monday this week')->format('Y-m-d'),
                $date->modify('sunday this week')->format('Y-m-d')
            ],
            'month' => [
                (new \DateTime($dateValue))->modify('first day of this month')->format('Y-m-d'),
                (new \DateTime($dateValue))->modify('last day of this month')->format('Y-m-d')
            ],
            default => [null, null],
        };
    }

    private function generateExcel(array $data, array $kpis): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Export Trafic');

        $allColumns = [
            'site' => 'Site',
            'serviceName' => 'Service',
            'classification' => 'Classification',
            'typeTrans' => 'Type Liaison',
            'siteTdd' => 'Site TDD',
            'siteFdd' => 'Site FDD',
            'maxTrafic' => 'Trafic Max (Mbps)',
            'seuilCritique' => 'Seuil Critique',
            'nombreOccurrences' => 'Nb Occurrences',
            'totalMeasures' => 'Total Measures',
            'dateMax' => 'Date Max',
            'dateAnalyse' => 'Date Analyse',
        ];

        $kpiMap = [
            'classification' => 'classification',
            'type_liaison' => 'typeTrans',
            'max_trafic' => 'maxTrafic',
            'seuil_critique' => 'seuilCritique',
            'nombre_occurrences' => 'nombreOccurrences',
            'updated_at' => 'dateAnalyse',
        ];

        if (!empty($kpis)) {
            $columns = ['site' => 'Site'];
            foreach ($kpis as $kpi) {
                if (isset($kpiMap[$kpi], $allColumns[$kpiMap[$kpi]])) {
                    $columns[$kpiMap[$kpi]] = $allColumns[$kpiMap[$kpi]];
                }
            }
        } else {
            $columns = $allColumns;
        }

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF6600']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ];

        $col = 'A';
        foreach ($columns as $label) {
            $sheet->setCellValue($col . '1', $label);
            $sheet->getColumnDimension($col)->setWidth(20);
            $col++;
        }

        $lastColLetter = $col === 'A' ? 'A' : chr(ord($col) - 1);
        $sheet->getStyle('A1:' . $lastColLetter . '1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $row = 2;
        foreach ($data as $item) {
            $col = 'A';
            foreach (array_keys($columns) as $key) {
                $value = match ($key) {
                    'site' => $item->getSite() ?? '—',
                    'serviceName' => $item->getServiceName() ?? '—',
                    'classification' => strtoupper($item->getClassification() ?? '—'),
                    'typeTrans' => strtoupper($item->getTypeTrans() ?? '—'),
                    'siteTdd' => $item->getSiteTdd() ?? '—',
                    'siteFdd' => $item->getSiteFdd() ?? '—',
                    'maxTrafic' => $item->getMaxTrafic() !== null ? round((float)$item->getMaxTrafic(), 2) : '—',
                    'seuilCritique' => $item->getSeuilCritique() !== null ? round((float)$item->getSeuilCritique(), 2) : '—',
                    'nombreOccurrences' => $item->getNombreOccurrences() ?? 0,
                    'totalMeasures' => $item->getTotalMeasures() ?? 0,
                    'dateMax' => $item->getDateMax()?->format('d/m/Y H:i') ?? '—',
                    'dateAnalyse' => $item->getDateAnalyse()?->format('d/m/Y H:i') ?? '—',
                    default => '—',
                };

                $sheet->setCellValue($col . $row, $value);
                $col++;
            }
            $row++;
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $lastColLetter . '1');

        $filename = 'export_trafic_' . date('Ymd_His') . '.xlsx';

        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
}