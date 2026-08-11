<?php
namespace App\Controller;

use App\Entity\SiteUpdateRequest;
use App\Entity\ProcessedSite;
use App\Service\SiteStateCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/superuser/site-updates')]
class SuperUserSiteUpdateController extends AbstractController
{
    #[Route('/', name: 'superuser_site_updates')]
    public function index(EntityManagerInterface $em)
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $pending = $em->getRepository(SiteUpdateRequest::class)->findBy(['status' => SiteUpdateRequest::STATUS_PENDING]);
        return $this->render('dashboard/superuser/site_updates.html.twig', ['pendingRequests' => $pending]);
    }

    #[Route('/{id}/validate', name: 'superuser_site_update_validate', methods: ['POST'])]
    public function validate(
        SiteUpdateRequest $updateRequest,
        Request $req,
        EntityManagerInterface $em,
        SiteStateCalculatorService $stateCalculator
    ) {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $action = $req->request->get('action');

        if ($action === 'validate') {
            $site = $em->getRepository(ProcessedSite::class)->findOneBy(['siteName' => $updateRequest->getSiteName()]);
            if ($site) {
                $capaciteChanged = false;

                // ✅ CORRIGÉ : ne touche plus maxTrafic (valeur mesurée),
                // uniquement capaciteMbps (valeur déclarée). L'ancien
                // code faisait `$site->setMaxTrafic($capacity)`, ce qui
                // forçait artificiellement le taux d'utilisation à 100%.
                if ($updateRequest->getNewCapacity()) {
                    $capacity = (float) $updateRequest->getNewCapacity();
                    if ($capacity > 0) {
                        $site->setCapaciteMbps($capacity);
                        $capaciteChanged = true;
                    }
                }
                if ($updateRequest->getNewSupportType()) {
                    $site->setTypeTrans($updateRequest->getNewSupportType());
                }

                if ($capaciteChanged) {
                    $site->setCapaciteUpdatedAt(new \DateTime());
                }
                $actionLabel = 'Demande de mise à jour validée (#' . $updateRequest->getId() . ')';
                if (method_exists($updateRequest, 'getNewSupportType') && $updateRequest->getNewSupportType()) {
                    $actionLabel .= ' - Type: ' . $updateRequest->getNewSupportType();
                }
                $site->setLastActionPerformed($actionLabel);

                // ✅ CORRIGÉ : au lieu de `setSiteStatus('secured')`
                // (chaîne minuscule que rien d'autre dans l'app ne
                // reconnaît -- casse toute la logique de badges/filtres
                // qui attend 'CRITIQUE'/'SURVEILLANCE'/'SECURISE'), on
                // recalcule l'état réel via le service partagé.
                $stateCalculator->recalculer($site);
            }
            $updateRequest->setStatus(SiteUpdateRequest::STATUS_VALIDATED);
            $updateRequest->getTicket()->setStatus('closed');
            $updateRequest->getTicket()->setClosedAt(new \DateTime());
            $this->addFlash('success', 'Site mis à jour (capacité et état recalculés) et ticket clôturé.');
        } else {
            $updateRequest->setStatus(SiteUpdateRequest::STATUS_REJECTED);
            $this->addFlash('warning', 'Demande rejetée.');
        }
        $updateRequest->setDecidedBy($this->getUser());
        $updateRequest->setDecidedAt(new \DateTimeImmutable());
        $em->flush();
        return $this->redirectToRoute('superuser_site_updates');
    }
}