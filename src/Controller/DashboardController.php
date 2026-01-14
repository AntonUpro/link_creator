<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateShortUrl;
use App\Entity\LinkClick;
use App\Entity\ShortUrl;
use App\Form\ShortUrlType;
use App\Service\ShortUrlService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private ShortUrlService $shortUrlService,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Главная страница dashboard
     */
    #[Route('', name: 'app_dashboard')]
    public function index(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $repository = $this->entityManager->getRepository(ShortUrl::class);
        $links = $repository->findUserLinks($this->getUser()->getId(), $limit, $offset);
        $total = $repository->count(['user' => $this->getUser()]);

        return $this->render('dashboard/index.html.twig', [
            'links' => $links,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit),
        ]);
    }

    /**
     * Создание ссылки через форму
     */
    #[Route('/create', name: 'app_dashboard_create')]
    public function create(Request $request): Response
    {
        $form = $this->createForm(ShortUrlType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $this->shortUrlService->createShortLink(
                new CreateShortUrl(
                    url:  $form->get('longUrl')->getData(),
                    customAlias: $form->get('customAlias')->getData(),
                    user: $this->getUser(),
                    expiresAt: $form->get('expiresAt')->getData(),
                    password: null,
                    isActive:  $form->get('isActive')->getData(),
                ),
            );

            $this->addFlash('success', 'Ссылка успешно создана!');

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('dashboard/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Редактирование ссылки
     */
    #[Route('/edit/{id}', name: 'app_dashboard_edit')]
    public function edit(ShortUrl $shortUrl, Request $request): Response
    {
        // Проверяем, что ссылка принадлежит пользователю
        if ($shortUrl->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('У вас нет доступа к этой ссылке');
        }

        $form = $this->createForm(ShortUrlType::class, $shortUrl);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Ссылка успешно обновлена!');

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('dashboard/edit.html.twig', [
            'form' => $form->createView(),
            'short_url' => $shortUrl,
        ]);
    }

    /**
     * Статистика по ссылке
     */
    #[Route('/stats/{id}', name: 'app_dashboard_stats')]
    public function stats(ShortUrl $shortUrl): Response
    {
        // Проверяем, что ссылка принадлежит пользователю
        if ($shortUrl->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('У вас нет доступа к этой ссылке');
        }

        // Получаем статистику кликов
        $clicks = $this->entityManager->getRepository(LinkClick::class)
            ->findBy(['shortUrl' => $shortUrl], ['createdAt' => 'DESC'], 100);

        return $this->render('dashboard/stats.html.twig', [
            'short_url' => $shortUrl,
            'clicks' => $clicks,
        ]);
    }
}
