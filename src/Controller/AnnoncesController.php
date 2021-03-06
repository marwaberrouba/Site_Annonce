<?php

namespace App\Controller;

use App\Entity\Annonces;
use App\Entity\Comments;
use App\Form\AnnonceContactType;
use App\Form\CommentsType;
use App\Repository\AnnoncesRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use App\Repository\CategoriesRepository;
use App\Service\SendMailService;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @Route("/annonces", name="annonces_")
 * @package App\Controller
 */

class AnnoncesController extends AbstractController
{
    /**
     * @Route("/", name="liste")
     * @return void 
     */
    public function index(AnnoncesRepository $annoncesRepo, CategoriesRepository $catRepo, Request $request){
        // On définit le nombre d'éléments par page
        $limit = 5;

        // On récupère le numéro de page
        $page = (int)$request->query->get("page", 1);

        // On récupère les filtres
        $filters = $request->get("categories");

        // On récupère les annonces de la page en fonction du filtre
        $annonces = $annoncesRepo->getPaginatedAnnonces($page, $limit, $filters);

        // On récupère le nombre total d'annonces
        $total = $annoncesRepo->getTotalAnnonces($filters);

        // On vérifie si on a une requête Ajax
        if($request->get('ajax')){
            return new JsonResponse([
                'content' => $this->renderView('annonces/_content.html.twig', compact('annonces', 'total', 'limit', 'page'))
            ]);
        }

        // On va chercher toutes les catégories
        $categories = $catRepo->findAll();


        return $this->render('annonces/index.html.twig', compact('annonces', 'total', 'limit', 'page', 'categories'));
    }

    /**
     * @Route("/details/{slug}", name="details")
     */
    public function details($slug, AnnoncesRepository $annoncesRepo, Request $request, MailerInterface $mailer)
    {
        $annonce = $annoncesRepo->findOneBy(['slug' => $slug]);

        if(!$annonce){
            throw new NotFoundHttpException('Pas d\'annonce trouvée');
        }

        $form = $this->createForm(AnnonceContactType::class);

        $contact = $form->handleRequest($request);

        if($form->isSubmitted() && $form->isValid()){
            // On crée le mail
            $email = (new TemplatedEmail())
                ->from($contact->get('email')->getData())
                ->to($annonce->getUsers()->getEmail())
                ->subject('Contact au sujet de votre annonce "' . $annonce->getTitle() . '"')
                ->htmlTemplate('emails/contact_annonce.html.twig')
                ->context([
                    'annonce' => $annonce,
                    'mail' => $contact->get('email')->getData(),
                    'message' => $contact->get('message')->getData()
                ]);

            // On envoie le mail
            $mailer->send($email);

            // On confirme et on redirige
            $this->addFlash('message', 'Votre e-mail a bien été envoyé');
            return $this->redirectToRoute('annonces_details', ['slug' => $annonce->getSlug()]);
        }

        // Partie commentaires
        // On crée le commentaire "vierge"
        $comment = new Comments;

        // On génère le formulaire
        $commentForm = $this->createForm(CommentsType::class, $comment);

        $commentForm->handleRequest($request);

        // Traitement du formulaire
        if($commentForm->isSubmitted() && $commentForm->isValid()){
            $comment->setCreatedAt(new DateTime());
            $comment->setAnnonces($annonce);

            // On récupère le contenu du champ parentid
            $parentid = $commentForm->get("parentid")->getData();

            // On va chercher le commentaire correspondant
            $em = $this->getDoctrine()->getManager();

            if($parentid != null){
                $parent = $em->getRepository(Comments::class)->find($parentid);
            }


            // On définit le parent
            $comment->setParent($parent ?? null);

            $em->persist($comment);
            $em->flush();

            $this->addFlash('message', 'Votre commentaire a bien été envoyé');
            return $this->redirectToRoute('annonces_details', ['slug' => $annonce->getSlug()]);
        }

        return $this->render('annonces/details.html.twig',
        [
            'annonce' => $annonce,
            'form' => $form->createView(),
            'commentForm' => $commentForm->createView()
        ]);
    }

    /**
     * @Route("/favoris/ajout/{id}", name="ajout_favoris")
     */
    public function ajoutFavoris(Annonces $annonce)
    {
        if(!$annonce){
            throw new NotFoundHttpException('Pas d\'annonce trouvée');
        }
        $annonce->addFavori($this->getUser());

        $em = $this->getDoctrine()->getManager();
        $em->persist($annonce);
        $em->flush();
        return $this->redirectToRoute('app_home');
    }

    /**
     * @Route("/favoris/retrait/{id}", name="retrait_favoris")
     */
    public function retraitFavoris(Annonces $annonce)
    {
        if(!$annonce){
            throw new NotFoundHttpException('Pas d\'annonce trouvée');
        }
        $annonce->removeFavori($this->getUser());

        $em = $this->getDoctrine()->getManager();
        $em->persist($annonce);
        $em->flush();
        return $this->redirectToRoute('app_home');
    }

    private function fonctionLongue(){
        sleep(3);
        return "Brouette";
    }
}
