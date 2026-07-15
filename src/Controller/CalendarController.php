<?php

namespace App\Controller;

use App\Entity\Calendar;
use App\Form\CalendarType;
use App\Repository\CalendarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/calendar')]
#[IsGranted('IS_AUTHENTICATED_FULLY')] // only logged-in users cann see
final class CalendarController extends AbstractController
{
    #[Route(name: 'app_calendar_index', methods: ['GET'])]
    public function index(CalendarRepository $calendarRepository): Response
    {
        // show public calendars only
        return $this->render('calendar/index.html.twig', [
            'calendars' => $calendarRepository->findBy(['isPublic' => true]),
        ]);
    }

    #[Route('/new', name: 'app_calendar_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $calendar = new Calendar();
        $form = $this->createForm(CalendarType::class, $calendar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $calendar->setIsPublic(true);
            $calendar->setOwner($this->getUser());

            $entityManager->persist($calendar);
            $entityManager->flush();

            return $this->redirectToRoute('app_calendar_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('calendar/new.html.twig', [
            'calendar' => $calendar,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_calendar_show', methods: ['GET'])]
    public function show(Calendar $calendar): Response
    {
        if (!$calendar->isPublic() && $calendar->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot view this calendar.');
        }

        return $this->render('calendar/show.html.twig', [
            'calendar' => $calendar,
        ]);
    }

}
