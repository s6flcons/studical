<?php

namespace App\Controller;

use App\Entity\Event;
use App\Form\EventType;
use App\Repository\CalendarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EventController extends AbstractController
{
    #[Route('/calendar/{calendarId}/event/new', name: 'app_event_new', methods: ['GET', 'POST'])]
    public function new(int $calendarId, Request $request, CalendarRepository $calendarRepository, EntityManagerInterface $entityManager): Response
    {
        $calendar = $calendarRepository->find($calendarId);

        if (!$calendar) {
            throw $this->createNotFoundException('Calendar not found.');
        }

        $canAddEvent = $calendar->isPublic() || $calendar->getOwner() === $this->getUser();

        if (!$canAddEvent) {
            throw $this->createAccessDeniedException('You cannot add events to this calendar.');
        }

        $event = new Event();
        $event->setCalendar($calendar);
        $event->setCreator($this->getUser());

        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($event);
            $entityManager->flush();

            return $this->redirectToRoute('app_calendar_show', ['id' => $calendar->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('event/new.html.twig', [
            'event' => $event,
            'calendar' => $calendar,
            'form' => $form,
        ]);
    }

    #[Route('/event/{id}/edit', name: 'app_event_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Event $event, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessEventVisible($event);

        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_calendar_show', ['id' => $event->getCalendar()->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('event/edit.html.twig', [
            'event' => $event,
            'form' => $form,
        ]);
    }

    #[Route('/event/{id}', name: 'app_event_delete', methods: ['POST'])]
    public function delete(Request $request, Event $event, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessEventVisible($event);

        $calendarId = $event->getCalendar()->getId();

        if ($this->isCsrfTokenValid('delete'.$event->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($event);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_calendar_show', ['id' => $calendarId], Response::HTTP_SEE_OTHER);
    }

    private function denyAccessUnlessEventVisible(Event $event): void
    {
        $calendar = $event->getCalendar();

        if (!$calendar->isPublic() && $calendar->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot access this event.');
        }
    }
}
