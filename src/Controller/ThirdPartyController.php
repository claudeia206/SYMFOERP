<?php

namespace App\Controller;

use App\Entity\ThirdParty;
use App\Repository\ThirdPartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ThirdPartyController extends AbstractController
{
    private const CATEGORIES = [
        ThirdParty::CATEGORY_CUSTOMER,
        ThirdParty::CATEGORY_PROSPECT,
        ThirdParty::CATEGORY_SUPPLIER,
    ];

    private const ITEMS_PER_PAGE = 25;

    #[Route('/third-parties', name: 'app_third_parties', methods: ['GET'])]
    public function index(Request $request, ThirdPartyRepository $thirdParties): Response
    {
        $search = mb_substr(trim((string) $request->query->get('q', '')), 0, 120);
        $category = (string) $request->query->get('category', '');
        $total = $thirdParties->countSearchResults($search, $category);
        $pageCount = max(1, (int) ceil($total / self::ITEMS_PER_PAGE));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));

        return $this->render('third_party/index.html.twig', [
            'third_parties' => $thirdParties->search($search, $category, $page, self::ITEMS_PER_PAGE),
            'search' => $search,
            'selected_category' => $category,
            'current_page' => $page,
            'page_count' => $pageCount,
            'total_count' => $total,
            'categories' => [
                ThirdParty::CATEGORY_CUSTOMER => 'Client',
                ThirdParty::CATEGORY_PROSPECT => 'Prospect',
                ThirdParty::CATEGORY_SUPPLIER => 'Fournisseur',
            ],
        ]);
    }

    #[Route('/third-parties/new', name: 'app_third_party_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $thirdParty = new ThirdParty('À renseigner');
        $errors = [];
        $formValues = [
            'name' => $thirdParty->getName(),
            'category' => $thirdParty->getCategory(),
            'email' => '',
            'phone' => '',
            'address' => '', 'postal_code' => '', 'city' => '', 'country' => '',
        ];

        if ($request->isMethod('POST')) {
            $formValues = [
                'name' => (string) $request->request->get('name', ''),
                'category' => (string) $request->request->get('category', ''),
                'email' => (string) $request->request->get('email', ''),
                'phone' => (string) $request->request->get('phone', ''),
                'address' => (string) $request->request->get('address', ''),
                'postal_code' => (string) $request->request->get('postal_code', ''),
                'city' => (string) $request->request->get('city', ''),
                'country' => (string) $request->request->get('country', ''),
            ];
            if (!$this->isCsrfTokenValid('third_party_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $errors = $this->applyFormData($thirdParty, $request);
            if ($errors === []) {
                $entityManager->persist($thirdParty);
                $entityManager->flush();

                $this->addFlash('success', 'Le tiers a été créé.');

                return $this->redirectToRoute('app_third_parties');
            }
        }

        return $this->render('third_party/form.html.twig', [
            'third_party' => $thirdParty,
            'form_values' => $formValues,
            'errors' => $errors,
            'csrf_token_id' => 'third_party_create',
            'is_edit' => false,
        ]);
    }

    #[Route('/third-parties/{id}', name: 'app_third_party_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(ThirdParty $thirdParty): Response
    {
        return $this->render('third_party/show.html.twig', ['third_party' => $thirdParty]);
    }

    #[Route('/third-parties/{id}/edit', name: 'app_third_party_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, ThirdParty $thirdParty, EntityManagerInterface $entityManager): Response
    {
        $tokenId = 'third_party_edit_'.$thirdParty->getId();
        $errors = [];
        $formValues = [
            'name' => $thirdParty->getName(),
            'category' => $thirdParty->getCategory(),
            'email' => $thirdParty->getEmail() ?? '',
            'phone' => $thirdParty->getPhone() ?? '',
            'address' => $thirdParty->getAddress() ?? '', 'postal_code' => $thirdParty->getPostalCode() ?? '', 'city' => $thirdParty->getCity() ?? '', 'country' => $thirdParty->getCountry() ?? '',
        ];

        if ($request->isMethod('POST')) {
            $formValues = [
                'name' => (string) $request->request->get('name', ''),
                'category' => (string) $request->request->get('category', ''),
                'email' => (string) $request->request->get('email', ''),
                'phone' => (string) $request->request->get('phone', ''),
                'address' => (string) $request->request->get('address', ''),
                'postal_code' => (string) $request->request->get('postal_code', ''),
                'city' => (string) $request->request->get('city', ''),
                'country' => (string) $request->request->get('country', ''),
            ];
            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $errors = $this->applyFormData($thirdParty, $request);
            if ($errors === []) {
                $entityManager->flush();
                $this->addFlash('success', 'La fiche du tiers a été mise à jour.');

                return $this->redirectToRoute('app_third_party_show', ['id' => $thirdParty->getId()]);
            }
        }

        return $this->render('third_party/form.html.twig', [
            'third_party' => $thirdParty,
            'form_values' => $formValues,
            'errors' => $errors,
            'csrf_token_id' => $tokenId,
            'is_edit' => true,
        ]);
    }

    #[Route('/third-parties/{id}/delete', name: 'app_third_party_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, ThirdParty $thirdParty, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('third_party_delete_'.$thirdParty->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $entityManager->remove($thirdParty);
        $entityManager->flush();
        $this->addFlash('success', 'Le tiers a été supprimé.');

        return $this->redirectToRoute('app_third_parties');
    }

    /** @return list<string> */
    private function applyFormData(ThirdParty $thirdParty, Request $request): array
    {
        $name = trim((string) $request->request->get('name', ''));
        $category = (string) $request->request->get('category', '');
        $email = trim((string) $request->request->get('email', ''));
        $phone = trim((string) $request->request->get('phone', ''));
        $errors = [];

        if ($name === '' || mb_strlen($name) > 180) {
            $errors[] = 'Le nom est obligatoire et doit contenir au maximum 180 caractères.';
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $errors[] = 'Veuillez sélectionner une catégorie valide.';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180)) {
            $errors[] = 'Veuillez saisir une adresse e-mail valide (180 caractères maximum).';
        }
        if (mb_strlen($phone) > 40) {
            $errors[] = 'Le téléphone ne doit pas dépasser 40 caractères.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $thirdParty->setName($name)
            ->setCategory($category)
            ->setEmail($email)
            ->setPhone($phone)
            ->setAddress((string) $request->request->get('address', ''))
            ->setPostalCode((string) $request->request->get('postal_code', ''))
            ->setCity((string) $request->request->get('city', ''))
            ->setCountry((string) $request->request->get('country', ''));

        return [];
    }
}
