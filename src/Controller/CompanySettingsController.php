<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CompanyProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CompanySettingsController extends AbstractController
{
    #[Route('/settings/company', name: 'app_company_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $entityManager): Response
    {
        $profile = $entityManager->find(CompanyProfile::class, 1) ?? new CompanyProfile();
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('company_profile_update', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            try {
                $profile->update($request->request->all());
                $entityManager->persist($profile);
                $entityManager->flush();
                $this->addFlash('success', 'Les informations de l’entreprise ont été enregistrées.');
                return $this->redirectToRoute('app_company_settings');
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $this->render('settings/company.html.twig', [
            'profile' => $profile,
            'errors' => $errors,
            'readiness_errors' => $profile->getInvoiceReadinessErrors(),
        ]);
    }
}
