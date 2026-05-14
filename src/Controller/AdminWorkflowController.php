<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/admin/workflow')]
class AdminWorkflowController extends AbstractController
{
    #[Route('/', name: 'admin_workflow_index', methods: ['GET'])]
    public function index(Request $request, TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $status = $request->query->get('status');
        $allowedStatuses = ['open', 'in_progress', 'completed', 'closed'];

        if ($status && !in_array($status, $allowedStatuses, true)) {
            $status = null;
        }

        return $this->render('dashboard/admin/workflow/index.html.twig', [
            'tickets' => $ticketRepository->findByStatusOrdered($status),
            'currentStatus' => $status,
        ]);
    }

    #[Route('/ticket/{id}', name: 'admin_workflow_show', methods: ['GET'])]
    public function show(Ticket $ticket): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('dashboard/admin/workflow/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }

    #[Route('/export-statistics', name: 'admin_workflow_export_statistics', methods: ['GET'])]
    public function exportStatistics(TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $tickets = $ticketRepository->findAllOrdered();

        $total = count($tickets);
        $open = 0;
        $inProgress = 0;
        $completed = 0;
        $closed = 0;
        $overdue = 0;
        $totalProgress = 0;

        $statusStats = [];
        $serviceStats = [];
        $actionStats = [];
        $now = new \DateTime();

        foreach ($tickets as $ticket) {
            $status = $ticket->getStatus();
            $action = $ticket->getActionType() ?: 'N/A';

            $statusStats[$status] = ($statusStats[$status] ?? 0) + 1;
            $actionStats[$action] = ($actionStats[$action] ?? 0) + 1;

            if ($status === 'open') {
                $open++;
            }

            if ($status === 'in_progress') {
                $inProgress++;
            }

            if ($status === 'completed') {
                $completed++;
            }

            if ($status === 'closed') {
                $closed++;
            }

            if (
                $ticket->getDeadlineAt()
                && $ticket->getDeadlineAt() < $now
                && !in_array($status, ['completed', 'closed'], true)
            ) {
                $overdue++;
            }

            $totalProgress += $ticket->getProgress();

            foreach ($ticket->getTasks() as $task) {
                $service = $task->getServiceName() ?: 'N/A';
                $taskStatus = $task->getStatus();

                if (!isset($serviceStats[$service])) {
                    $serviceStats[$service] = [
                        'total' => 0,
                        'pending' => 0,
                        'in_progress' => 0,
                        'done' => 0,
                        'blocked' => 0,
                    ];
                }

                $serviceStats[$service]['total']++;

                if (isset($serviceStats[$service][$taskStatus])) {
                    $serviceStats[$service][$taskStatus]++;
                }
            }
        }

        ksort($statusStats);
        ksort($serviceStats);
        ksort($actionStats);

        $averageProgress = $total > 0 ? round($totalProgress / $total, 1) : 0;

        $html = $this->buildWorkflowPdfHtml([
            'tickets' => $tickets,
            'total' => $total,
            'open' => $open,
            'inProgress' => $inProgress,
            'completed' => $completed,
            'closed' => $closed,
            'overdue' => $overdue,
            'averageProgress' => $averageProgress,
            'statusStats' => $statusStats,
            'serviceStats' => $serviceStats,
            'actionStats' => $actionStats,
            'generatedAt' => new \DateTime(),
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'workflow_statistics_' . date('Ymd_His') . '.pdf';

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    private function buildWorkflowPdfHtml(array $data): string
    {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: DejaVu Sans, sans-serif;
                    color: #0f172a;
                    font-size: 12px;
                }

                .header {
                    background: #0f172a;
                    color: white;
                    padding: 22px;
                    text-align: center;
                }

                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }

                .header p {
                    margin: 6px 0 0;
                    font-size: 12px;
                    color: #cbd5e1;
                }

                .section {
                    margin: 22px 0;
                }

                h2 {
                    color: #1e293b;
                    font-size: 17px;
                    border-bottom: 2px solid #e2e8f0;
                    padding-bottom: 6px;
                    margin-bottom: 12px;
                }

                .cards {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }

                .cards td {
                    width: 25%;
                    padding: 12px;
                    border: 1px solid #dbe3ef;
                    background: #f8fafc;
                    vertical-align: top;
                }

                .label {
                    font-size: 11px;
                    color: #64748b;
                    text-transform: uppercase;
                    font-weight: bold;
                }

                .value {
                    font-size: 26px;
                    font-weight: bold;
                    margin-top: 6px;
                    color: #020617;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 10px;
                }

                th {
                    background: #e2e8f0;
                    color: #0f172a;
                    padding: 8px;
                    border: 1px solid #cbd5e1;
                    text-align: left;
                    font-size: 11px;
                }

                td {
                    padding: 8px;
                    border: 1px solid #e2e8f0;
                    font-size: 11px;
                }

                .badge {
                    padding: 3px 6px;
                    border-radius: 5px;
                    font-size: 10px;
                    font-weight: bold;
                }

                .open { background: #dbeafe; color: #1d4ed8; }
                .in_progress { background: #fef3c7; color: #b45309; }
                .completed { background: #ccfbf1; color: #047857; }
                .closed { background: #dcfce7; color: #15803d; }
                .overdue { background: #fee2e2; color: #dc2626; }

                .footer {
                    margin-top: 30px;
                    font-size: 10px;
                    color: #64748b;
                    text-align: center;
                }
            </style>
        </head>
        <body>

        <div class="header">
            <h1>Rapport Statistiques Workflows</h1>
            <p>NetWON - Généré le <?= $data['generatedAt']->format('d/m/Y H:i') ?></p>
        </div>

        <div class="section">
            <h2>Résumé global</h2>
            <table class="cards">
                <tr>
                    <td><div class="label">Total workflows</div><div class="value"><?= $data['total'] ?></div></td>
                    <td><div class="label">En cours</div><div class="value"><?= $data['open'] + $data['inProgress'] ?></div></td>
                    <td><div class="label">Clôturés</div><div class="value"><?= $data['closed'] ?></div></td>
                    <td><div class="label">Retard</div><div class="value"><?= $data['overdue'] ?></div></td>
                </tr>
            </table>
            <table class="cards">
                <tr>
                    <td><div class="label">Ouverts</div><div class="value"><?= $data['open'] ?></div></td>
                    <td><div class="label">In progress</div><div class="value"><?= $data['inProgress'] ?></div></td>
                    <td><div class="label">Terminés</div><div class="value"><?= $data['completed'] ?></div></td>
                    <td><div class="label">Progression moyenne</div><div class="value"><?= $data['averageProgress'] ?>%</div></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Répartition par statut</h2>
            <table>
                <thead>
                    <tr>
                        <th>Statut</th>
                        <th>Nombre</th>
                        <th>Pourcentage</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data['statusStats'] as $status => $count): ?>
                    <tr>
                        <td><?= htmlspecialchars($status) ?></td>
                        <td><?= $count ?></td>
                        <td><?= $data['total'] > 0 ? round(($count / $data['total']) * 100, 1) : 0 ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Répartition par service</h2>
            <table>
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Total tâches</th>
                        <th>Pending</th>
                        <th>In progress</th>
                        <th>Done</th>
                        <th>Blocked</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data['serviceStats'] as $service => $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($service) ?></td>
                        <td><?= $row['total'] ?></td>
                        <td><?= $row['pending'] ?></td>
                        <td><?= $row['in_progress'] ?></td>
                        <td><?= $row['done'] ?></td>
                        <td><?= $row['blocked'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Répartition par type d'action</h2>
            <table>
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Nombre</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data['actionStats'] as $action => $count): ?>
                    <tr>
                        <td><?= htmlspecialchars($action) ?></td>
                        <td><?= $count ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>Liste détaillée des workflows</h2>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Titre</th>
                        <th>Action</th>
                        <th>Statut</th>
                        <th>Progression</th>
                        <th>Créé par</th>
                        <th>Date limite</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data['tickets'] as $ticket): ?>
                    <?php
                    $isOverdue = $ticket->getDeadlineAt()
                        && $ticket->getDeadlineAt() < new \DateTime()
                        && !in_array($ticket->getStatus(), ['completed', 'closed'], true);
                    ?>
                    <tr>
                        <td><?= $ticket->getId() ?></td>
                        <td><?= htmlspecialchars($ticket->getTitle() ?: '-') ?></td>
                        <td><?= htmlspecialchars($ticket->getActionType() ?: '-') ?></td>
                        <td>
                            <span class="badge <?= htmlspecialchars($ticket->getStatus()) ?>">
                                <?= htmlspecialchars($ticket->getStatus()) ?>
                            </span>
                            <?php if ($isOverdue): ?>
                                <span class="badge overdue">retard</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $ticket->getProgress() ?>%</td>
                        <td>
                            <?= htmlspecialchars(
                                $ticket->getCreatedBy()?->getUsername()
                                ?? $ticket->getCreatedBy()?->getEmail()
                                ?? 'Utilisateur'
                            ) ?>
                        </td>
                        <td><?= $ticket->getDeadlineAt() ? $ticket->getDeadlineAt()->format('d/m/Y H:i') : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="footer">Rapport généré automatiquement par NetWON.</div>

        </body>
        </html>
        <?php

        return ob_get_clean();
    }
}