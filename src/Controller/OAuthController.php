<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class OAuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordEncoder,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Route('/oauth/connect/{provider}', name: 'oauth_connect')]
    public function connect(ClientRegistry $clientRegistry, string $provider): RedirectResponse
    {
        if ($provider === 'yandex') {
            return $clientRegistry->getClient('yandex')->redirect([], []);
        }

        throw $this->createNotFoundException('Provider not supported');
    }

    #[Route('/oauth/callback/{provider}', name: 'oauth_callback')]
    public function callback(
        Request $request,
        ClientRegistry $clientRegistry,
        string $provider
    ): Response {
        if ($provider === 'yandex') {
            $client = $clientRegistry->getClient('yandex');

            try {
                // Get the OAuth2 user
                $yandexUser = $client->fetchUser();

                // Check if user already exists by email
                $existingUser = $this->userRepository->findOneBy(['email' => $yandexUser->getEmail()]);

                if ($existingUser) {
                    // Log in existing user
                    return $this->redirectToRoute('app_home');
                }

                // Create new user
                $user = new User();
                $user->setEmail($yandexUser->getEmail());

                // Set a random password since user logs in via OAuth
                $encodedPassword = $this->passwordEncoder->hashPassword($user, bin2hex(random_bytes(16)));
                $user->setPassword($encodedPassword);

                // Generate API token
                $apiToken = bin2hex(random_bytes(32));
                $user->setApiToken($apiToken);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                // Log in the new user
                return $this->redirectToRoute('app_home');
            } catch (\Exception $e) {
                // Handle error
                $this->addFlash('error', 'Ошибка при авторизации через Yandex: ' . $e->getMessage());
                return $this->redirectToRoute('app_login');
            }
        }

        throw $this->createNotFoundException('Provider not supported');
    }
}
