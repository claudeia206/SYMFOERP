<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users', name: 'app_admin_users')]
final class UserAdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'users' => $this->users->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/new', name: '_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $email = '';
        $error = null;
        if ($request->isMethod('POST')) {
            $email = mb_strtolower(trim((string) $request->request->get('email', '')));
            $password = (string) $request->request->get('password', '');
            $makeAdmin = $request->request->getBoolean('is_admin');
            if (!$this->isCsrfTokenValid('admin_user_create', (string) $request->request->get('_token'))) {
                $error = 'Le formulaire a expiré. Rechargez la page.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
                $error = 'Saisissez une adresse e-mail valide (180 caractères maximum).';
            } elseif (strlen($password) < 12) {
                $error = 'Le mot de passe doit contenir au moins 12 caractères.';
            } elseif ($this->users->findOneBy(['email' => $email]) !== null) {
                $error = 'Un compte utilise déjà cette adresse e-mail.';
            } else {
                $user = new User($email);
                $user->setRoles($makeAdmin ? ['ROLE_ADMIN'] : []);
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('Le compte %s a été créé.', $email));
                return $this->redirectToRoute('app_admin_users_index');
            }
        }
        return $this->render('admin/users/new.html.twig', ['email' => $email, 'error' => $error]);
    }

    #[Route('/{id}/role', name: '_role', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function changeRole(User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_user_role_'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $makeAdmin = $request->request->getBoolean('is_admin');
        $currentlyAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
        if ($makeAdmin !== $currentlyAdmin) {
            if ($user->getId() === $this->getUser()?->getId() && !$makeAdmin) {
                $this->addFlash('error', 'Vous ne pouvez pas retirer votre propre rôle administrateur.');
                return $this->redirectToRoute('app_admin_users_index');
            }
            if (!$makeAdmin) {
                $adminCount = count(array_filter($this->users->findAll(), static fn (User $candidate): bool => in_array('ROLE_ADMIN', $candidate->getRoles(), true)));
                if ($adminCount <= 1) {
                    $this->addFlash('error', 'Impossible de retirer le dernier administrateur.');
                    return $this->redirectToRoute('app_admin_users_index');
                }
            }
            $user->setRoles($makeAdmin ? ['ROLE_ADMIN'] : []);
            $this->entityManager->flush();
            $this->addFlash('success', 'Les droits du compte ont été mis à jour.');
        }
        return $this->redirectToRoute('app_admin_users_index');
    }
}
