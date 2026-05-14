<?php
namespace App\Controller;

use App\Entity\SiteUpdateRequest;
use App\Entity\ProcessedSite;
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
    public function validate(SiteUpdateRequest $request, Request $req, EntityManagerInterface $em)
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $action = $req->request->get('action');
        if ($action === 'validate') {
            // Mettre à jour le site correspondant dans ProcessedSite
            $site = $em->getRepository(ProcessedSite::class)->findOneBy(['siteName' => $request->getSiteName()]);
            if ($site) {
                if ($request->getNewCapacity()) $site->setMaxTrafic($request->getNewCapacity());
                if ($request->getNewSupportType()) $site->setTypeTrans($request->getNewSupportType());
                // Mettre à jour le statut du site (critical->secured)
                $site->setSiteStatus('secured');
            }
            $request->setStatus(SiteUpdateRequest::STATUS_VALIDATED);
            $request->getTicket()->setStatus('closed');
            $this->addFlash('success', 'Site mis à jour et ticket clôturé.');
        } else {
            $request->setStatus(SiteUpdateRequest::STATUS_REJECTED);
            $this->addFlash('warning', 'Demande rejetée.');
        }
        $request->setDecidedBy($this->getUser());
        $request->setDecidedAt(new \DateTimeImmutable());
        $em->flush();
        return $this->redirectToRoute('superuser_site_updates');
    }
}