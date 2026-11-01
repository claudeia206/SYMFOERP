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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Securely reset the password of an existing administrator.'
)]
final class ResetAdminPasswordCommand extends Command
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

        if ($email === '' && $input->isInteractive()) {
            $email = mb_strtolower(trim((string) $io->ask('Administrator email')));
        }
        if ($plainPassword === '' && $input->isInteractive()) {
            $plainPassword = (string) $io->askHidden('New password (12 characters minimum)');
            $confirmation = (string) $io->askHidden('Confirm new password');
            if (!hash_equals($plainPassword, $confirmation)) {
                $io->error('The password confirmation does not match. No account was changed.');
                return Command::INVALID;
            }
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            $io->error('Set a valid SYMFOERP_ADMIN_EMAIL in the command environment.');
            return Command::INVALID;
        }
        if (strlen($plainPassword) < 12) {
            $io->error('The new administrator password must contain at least 12 characters.');
            return Command::INVALID;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error('No account exists for this email. Use app:user:create only for the first administrator.');
            return Command::FAILURE;
        }
        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $io->error('This account is not an administrator. No account was changed.');
            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();

        $io->success(sprintf('Password reset for administrator %s. The account roles were not changed.', $user->getEmail()));
        return Command::SUCCESS;
    }
}
