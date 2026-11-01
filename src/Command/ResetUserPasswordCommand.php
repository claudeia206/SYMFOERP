<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:password-reset',
    description: 'Reset the password of an existing SYMFOERP user from environment variables.',
)]
final class ResetUserPasswordCommand extends Command
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
        $email = mb_strtolower(trim((string) getenv('SYMFOERP_ADMIN_EMAIL')));
        $plainPassword = (string) getenv('SYMFOERP_ADMIN_PASSWORD');

        if ($email === '' || $plainPassword === '') {
            $io->error('Set SYMFOERP_ADMIN_EMAIL and SYMFOERP_ADMIN_PASSWORD in the command environment.');
            return Command::INVALID;
        }

        if (strlen($plainPassword) < 12) {
            $io->error('The password must contain at least 12 characters.');
            return Command::INVALID;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error('No account exists for this email. No account was created or changed.');
            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();

        $io->success(sprintf('Password updated for %s. Existing roles were preserved.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
