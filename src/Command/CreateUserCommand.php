<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:create', description: 'Create a Symfony application administrator from environment variables.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) getenv('SYMFOERP_ADMIN_EMAIL'));
        $plainPassword = (string) getenv('SYMFOERP_ADMIN_PASSWORD');

        if ($email === '' || $plainPassword === '') {
            $io->error('Set SYMFOERP_ADMIN_EMAIL and SYMFOERP_ADMIN_PASSWORD in the command environment.');
            return Command::INVALID;
        }
        if (strlen($plainPassword) < 12) {
            $io->error('The administrator password must contain at least 12 characters.');
            return Command::INVALID;
        }
        if ($this->users->findOneBy(['email' => mb_strtolower($email)]) !== null) {
            $io->error('A user with this email already exists. No account was changed.');
            return Command::FAILURE;
        }

        try {
            $user = new User($email);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
        }

        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Administrator %s created.', $user->getEmail()));
        return Command::SUCCESS;
    }
}
